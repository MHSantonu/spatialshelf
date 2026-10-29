<?php

namespace App\Services\WhatsApp;

use App\Models\Order;
use App\Models\Product;
use App\Support\Money;

/**
 * Renders a draft order as a WhatsApp invoice message.
 *
 * Body shape: a "Your order from <shop>" header, one line per priced
 * item ("2 × Lux Soap 100g — ৳90"), a divider, the total, then
 * "Not available:" and "Reduced to stock:" sections when non-empty.
 * Lines capped to stock carry a note on the row; those rows land in
 * the reduced section. The body stays under WhatsApp's 1024-character
 * limit by dropping priced lines from the end and appending a
 * "+N more items" line; the total always covers the whole order.
 */
final class InvoiceFormatter
{
    private const MAX_CHARS = 1024;

    private const DIVIDER = '--------------------';

    /**
     * @return array{body: string, buttons: list<array{0: string, 1: string}>}
     */
    public function format(Order $order): array
    {
        $order->loadMissing(['shop', 'lines.product']);

        $items = [];
        $unavailable = [];
        $reduced = [];
        foreach ($order->lines->sortBy('id') as $line) {
            $requested = trim((string) ($line->requested_text ?? ''));
            $product = $line->product;
            if ($line->status !== 'matched' || $line->product_id === null || ! $product instanceof Product) {
                if ($requested !== '') {
                    $unavailable[] = $requested;
                }

                continue;
            }
            $size = self::compactSize($product);
            $items[] = "{$line->qty} × {$product->name}".($size === '' ? '' : " {$size}").' — '.Money::format((float) $line->line_total);
            if ($line->note !== null && trim((string) $line->note) !== '') {
                $reduced[] = "{$product->name} ×{$line->qty}";
            }
        }

        $shop = $order->shop->name;
        $tail = ['Total: '.Money::format((float) $order->total)];
        if ($unavailable !== []) {
            $tail[] = 'Not available: '.implode(', ', $unavailable);
        }
        if ($reduced !== []) {
            $tail[] = 'Reduced to stock: '.implode(', ', $reduced);
        }

        return [
            'body' => $this->assemble(["Your order from {$shop}"], $items, $tail),
            'buttons' => [
                ["confirm:{$order->id}", 'Confirm'],
                ["change:{$order->id}", 'Change'],
                ["cancel:{$order->id}", 'Cancel'],
            ],
        ];
    }

    /**
     * @param  list<string>  $head
     * @param  list<string>  $items
     * @param  list<string>  $tail
     */
    private function assemble(array $head, array $items, array $tail): string
    {
        $shown = $items;
        $dropped = 0;
        while ($shown !== [] && mb_strlen($this->join($head, $shown, $dropped, $tail), 'UTF-8') > self::MAX_CHARS) {
            array_pop($shown);
            $dropped++;
        }
        $body = $this->join($head, $shown, $dropped, $tail);
        if (mb_strlen($body, 'UTF-8') > self::MAX_CHARS) {
            // Pathological tail (a giant unavailable list): hard cap, UTF-8 safe.
            $body = mb_substr($body, 0, self::MAX_CHARS, 'UTF-8');
        }

        return $body;
    }

    /**
     * @param  list<string>  $head
     * @param  list<string>  $shown
     * @param  list<string>  $tail
     */
    private function join(array $head, array $shown, int $dropped, array $tail): string
    {
        $lines = [...$head, ...$shown];
        if ($dropped > 0) {
            $lines[] = "+{$dropped} more items";
        }
        $lines[] = self::DIVIDER;
        array_push($lines, ...$tail);

        return implode("\n", $lines);
    }

    public static function compactSize(Product $product): string
    {
        if (is_numeric($product->size_value) && (float) $product->size_value > 0) {
            $value = rtrim(rtrim(sprintf('%.2f', (float) $product->size_value), '0'), '.');

            return $value.trim((string) $product->size_unit);
        }

        return '';
    }
}
