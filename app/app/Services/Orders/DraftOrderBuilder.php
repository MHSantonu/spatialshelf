<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Builds a draft order from parsed order lines (spec contract 2 output).
 *
 * Lines that matched the same product merge into one, summing qty, so a
 * repeated item shows as "x2" on the invoice. Pricing always comes from
 * the product row, never from the model; qty is capped at stock_qty with
 * an English note carrying the ৳ unit price. Ambiguous and not_found
 * lines keep product_id null but stay on the order with their
 * alternative_ids, so the reply can list them. The whole parsed payload,
 * including whatever raw model responses the caller attached, lands in
 * orders.context for debugging and the demo.
 *
 * Model text (names, notes) may hold ৳ or Bangla; it is only ever cut
 * with mb_substr, so stored output stays valid UTF-8.
 */
final class DraftOrderBuilder
{
    /**
     * @param  array{lines?: mixed, ...}  $parsed  Decoded parse_order JSON
     *                                             (lines + intent). Callers attach raw model responses ('raw',
     *                                             'asr_raw', ...); the payload is stored verbatim in orders.context.
     */
    public function build(Shop $shop, ?Customer $customer, string $sourceType, array $parsed, ?string $rawInputPath, ?string $transcript): Order
    {
        return DB::transaction(function () use ($shop, $customer, $sourceType, $parsed, $rawInputPath, $transcript): Order {
            $order = Order::create([
                'shop_id' => $shop->id,
                'customer_id' => $customer?->id,
                'status' => OrderStatus::Draft,
                'source_type' => $sourceType,
                'raw_input_path' => $rawInputPath,
                'transcript' => $transcript,
                'context' => $parsed,
                'total' => 0,
            ]);

            $total = 0.0;
            foreach ($this->mergedLines($parsed['lines'] ?? []) as $line) {
                $row = $this->priceLine((int) $shop->id, $line);
                $order->lines()->create($row);
                $total += (float) $row['line_total'];
            }
            $order->total = round($total, 2);
            $order->save();

            return $order->load('lines');
        });
    }

    /**
     * Merge matched lines by product_id, keeping first-seen order; every
     * other line passes through untouched.
     *
     * @return list<array{status: string, product_id: string|null, requested_text: string, qty: int, confidence: float|null, alternative_ids: list<string>}>
     */
    private function mergedLines(mixed $lines): array
    {
        if (! is_array($lines)) {
            return [];
        }
        $merged = [];
        $at = [];
        foreach ($lines as $line) {
            $normalized = $this->normalizeLine($line);
            if ($normalized === null) {
                continue;
            }

            $status = $normalized['status'];
            $productId = $normalized['product_id'];
            if ($status !== 'matched' || $productId === '' || $productId === 'none') {
                $normalized['status'] = $status === 'matched' ? 'not_found' : $status;
                $normalized['product_id'] = null;
                $merged[] = $normalized;

                continue;
            }
            if (isset($at[$productId])) {
                $pos = $at[$productId];
                $current = $merged[$pos];
                $current['qty'] += $normalized['qty'];
                $current['requested_text'] = $this->joinTexts(
                    $current['requested_text'], $normalized['requested_text']
                );
                $current['confidence'] = $this->maxConfidence(
                    $current['confidence'], $normalized['confidence']
                );
                $current['alternative_ids'] = $this->mergeIds(
                    $current['alternative_ids'], $normalized['alternative_ids']
                );
                $merged[$pos] = $current;
            } else {
                $at[$productId] = count($merged);
                $merged[] = $normalized;
            }
        }

        return $merged;
    }

    /**
     * @return array{status: string, product_id: string, requested_text: string, qty: int, confidence: float|null, alternative_ids: list<string>}|null
     */
    private function normalizeLine(mixed $line): ?array
    {
        if (! is_array($line)) {
            return null;
        }
        $status = (string) ($line['status'] ?? 'not_found');
        if (! in_array($status, ['matched', 'ambiguous', 'not_found'], true)) {
            $status = 'not_found';
        }

        return [
            'status' => $status,
            'product_id' => isset($line['product_id']) ? trim((string) $line['product_id']) : 'none',
            'requested_text' => trim((string) ($line['requested_text'] ?? '')),
            'qty' => max(1, (int) round((float) ($line['qty'] ?? 1))),
            'confidence' => isset($line['confidence']) ? (float) $line['confidence'] : null,
            'alternative_ids' => $this->alternativeIds($line['alternative_ids'] ?? []),
        ];
    }

    /** @return list<string> */
    private function alternativeIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($id): string => trim((string) $id), $ids
        ), fn (string $id): bool => $id !== ''));
    }

    private function joinTexts(string $first, string $second): string
    {
        if ($first === '' || $second === '' || $first === $second) {
            return $first !== '' ? $first : $second;
        }

        return $first.' + '.$second;
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<string>
     */
    private function mergeIds(array $a, array $b): array
    {
        $seen = [];
        foreach ([...$a, ...$b] as $id) {
            $seen[$id] = true;
        }

        return array_keys($seen);
    }

    private function maxConfidence(?float $a, ?float $b): ?float
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return max($a, $b);
    }

    /**
     * @param  array{status: string, product_id: string|null, requested_text: string, qty: int, confidence: float|null, alternative_ids: list<string>}  $line
     * @return array<string, mixed>
     */
    private function priceLine(int $shopId, array $line): array
    {
        $product = $line['product_id'] !== null
            ? Product::query()->where('shop_id', $shopId)->where('active', true)->find($line['product_id'])
            : null;

        if ($line['status'] !== 'matched' || ! $product instanceof Product) {
            return [
                'product_id' => null,
                'requested_text' => $this->text($line['requested_text']),
                'qty' => $line['qty'],
                'unit_price' => 0,
                'line_total' => 0,
                'match_confidence' => $line['confidence'],
                'status' => $line['status'] === 'ambiguous' ? 'ambiguous' : 'not_found',
                'note' => null,
                'alternative_ids' => $line['alternative_ids'],
            ];
        }

        $stock = (int) $product->stock_qty;
        $qty = min($line['qty'], $stock);
        $unit = round((float) $product->price, 2);
        $note = null;
        if ($qty < $line['qty']) {
            $note = $stock === 0
                ? 'Out of stock at '.Money::format($unit)." each; wanted {$line['qty']}."
                : "Only {$stock} in stock at ".Money::format($unit)." each; wanted {$line['qty']}.";
        }

        return [
            'product_id' => $product->id,
            'requested_text' => $this->text($line['requested_text']),
            'qty' => $qty,
            'unit_price' => $unit,
            'line_total' => round($qty * $unit, 2),
            'match_confidence' => $line['confidence'],
            'status' => 'matched',
            'note' => $note,
            'alternative_ids' => $line['alternative_ids'],
        ];
    }

    /**
     * Column-safe model text: mb_substr keeps ৳ and Bangla intact where
     * substr would split a multibyte character.
     */
    private function text(string $text): string
    {
        return mb_substr($text, 0, 255, 'UTF-8');
    }
}
