<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\MuseSparkException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Shop;
use App\Services\Catalog\MemoryMatcher;
use App\Services\Meta\MuseSpark;
use App\Services\Meta\VoiceTranscriber;
use App\Services\WorkerClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Takes a customer order from any surface to a priced draft.
 *
 * The single entry point both /try and WhatsApp use: audio is
 * transcribed first, then the text and/or image go to Muse Spark for
 * parsing, then the parsed lines become a draft order. A photo without
 * audio is classified first: a single product goes to visual memory
 * (Muse Spark as backup), anything else keeps the list path. The channel,
 * the typed text or caption, and which path matched ('memory' or 'model')
 * are recorded in orders.context; the source_type comes from whichever
 * input carried the order (voice beats photo beats text).
 * Orders from /try land as simulated, never draft, so demos and judges
 * cannot move real stock; only WhatsApp orders await confirmation.
 */
final class OrderIntake
{
    private readonly VoiceTranscriber $transcriber;

    private readonly MuseSpark $spark;

    private readonly DraftOrderBuilder $builder;

    private readonly WorkerClient $worker;

    public function __construct(?VoiceTranscriber $transcriber = null, ?MuseSpark $spark = null, ?DraftOrderBuilder $builder = null, ?WorkerClient $worker = null)
    {
        $this->transcriber = $transcriber ?? new VoiceTranscriber;
        $this->spark = $spark ?? new MuseSpark;
        $this->builder = $builder ?? new DraftOrderBuilder;
        $this->worker = $worker ?? new WorkerClient;
    }

    /**
     * @param  string  $channel  Entry surface: 'try' or 'whatsapp'.
     * @param  string|null  $text  Typed message, transcript, or photo caption.
     * @param  string|null  $imagePath  List photo; absolute or shared-storage-relative.
     * @param  string|null  $audioPath  Voice note; shared-storage-relative.
     */
    public function handle(Shop $shop, ?Customer $customer, string $channel, ?string $text, ?string $imagePath, ?string $audioPath): Order
    {
        if (! in_array($channel, ['try', 'whatsapp'], true)) {
            throw new \InvalidArgumentException("Unknown order channel [{$channel}].");
        }
        $imagePath = $this->present($imagePath);
        $audioPath = $this->present($audioPath);
        if ($this->present($text) === null && $imagePath === null && $audioPath === null) {
            throw new \InvalidArgumentException('OrderIntake needs text, an image, or audio.');
        }

        $transcript = $audioPath !== null ? $this->transcriber->transcribe($audioPath, $shop) : null;
        $typed = trim((string) $text);

        $classifyRaw = null;
        if ($imagePath !== null && $audioPath === null) {
            $classified = $this->spark->classifyImage($imagePath);
            if ($classified['kind'] === 'single_product') {
                return $this->handleSingleProduct(
                    $shop, $customer, $channel,
                    $typed === '' ? null : $typed,
                    $imagePath, $classified['raw']
                );
            }
            $classifyRaw = $classified['raw'];
        }

        $texts = array_filter(
            [$typed, trim((string) $transcript)],
            fn (string $part): bool => $part !== ''
        );
        $result = $this->spark->parseOrder($shop, $texts === [] ? null : implode("\n", $texts), $imagePath);

        $parsed = array_merge($result['parsed'], [
            'raw' => $result['raw'],
            'channel' => $channel,
            'input_text' => $typed === '' ? null : $typed,
            'match_path' => 'model',
        ]);
        if ($classifyRaw !== null) {
            $parsed['classify_raw'] = $classifyRaw;
        }
        $order = $this->builder->build(
            $shop,
            $customer,
            $audioPath !== null ? 'voice' : ($imagePath !== null ? 'photo' : 'text'),
            $parsed,
            $audioPath ?? $imagePath,
            $transcript,
        );

        return $this->simulatedIfTry($order, $channel);
    }

    /**
     * One product photo: visual memory first, Muse Spark as backup.
     *
     * The worker crops to the largest object and embeds it. Within
     * match_distance the product is the order line; a runner-up within
     * 0.03 makes it ambiguous with both ids. No match — or a worker
     * failure — falls back to parseOrder on the image, exactly as for a
     * list. Quantity comes from the caption, defaulting to 1.
     *
     * @param  array<string, mixed>  $classifyRaw
     */
    private function handleSingleProduct(Shop $shop, ?Customer $customer, string $channel, ?string $caption, string $imagePath, array $classifyRaw): Order
    {
        $top = [];
        $embedError = null;
        try {
            $vector = $this->worker->embed($imagePath, true)->embedding;
            $top = (new MemoryMatcher($shop->id))->topTwo($vector);
        } catch (Throwable $e) {
            $embedError = substr($e->getMessage(), 0, 500);
        }

        $threshold = (float) config('spatialshelf.match_distance', 0.15);
        $best = $top[0] ?? null;
        if ($best === null || $best['distance'] > $threshold) {
            return $this->modelFallback($shop, $customer, $channel, $caption, $imagePath, $classifyRaw, $embedError);
        }

        $runnerUp = $top[1] ?? null;
        $ambiguous = $runnerUp !== null && ($runnerUp['distance'] - $best['distance']) <= 0.03;
        $alternativeIds = [];
        if ($ambiguous && $runnerUp !== null) {
            $alternativeIds = [(string) $best['product_id'], (string) $runnerUp['product_id']];
        }
        $qty = $this->qtyFromCaption($shop, $caption, $qtyRaw);
        $line = [
            'requested_text' => $caption ?? '',
            'qty' => $qty,
            'qty_unit' => '',
            'product_id' => $ambiguous ? 'none' : (string) $best['product_id'],
            'status' => $ambiguous ? 'ambiguous' : 'matched',
            'alternative_ids' => $alternativeIds,
            'confidence' => max(0.0, round(1.0 - $best['distance'], 3)),
        ];
        $parsed = [
            'lines' => [$line],
            'intent' => 'new_order',
            'channel' => $channel,
            'input_text' => $caption,
            'match_path' => 'memory',
            'classify_raw' => $classifyRaw,
            'memory' => [
                'distances' => array_map(
                    fn (array $row): array => ['product_id' => $row['product_id'], 'distance' => $row['distance']],
                    $top
                ),
                'ambiguous' => $ambiguous,
            ],
        ];
        if ($qtyRaw !== null) {
            $parsed['qty_raw'] = $qtyRaw;
        }
        $order = $this->builder->build($shop, $customer, 'photo', $parsed, $imagePath, null);

        return $this->simulatedIfTry($order, $channel);
    }

    /**
     * @param  array<string, mixed>  $classifyRaw
     */
    private function modelFallback(Shop $shop, ?Customer $customer, string $channel, ?string $caption, string $imagePath, array $classifyRaw, ?string $embedError): Order
    {
        $result = $this->spark->parseOrder($shop, $caption, $imagePath);
        $parsed = array_merge($result['parsed'], [
            'raw' => $result['raw'],
            'channel' => $channel,
            'input_text' => $caption,
            'match_path' => 'model',
            'classify_raw' => $classifyRaw,
        ]);
        if ($embedError !== null) {
            $parsed['embed_error'] = $embedError;
        }
        $order = $this->builder->build($shop, $customer, 'photo', $parsed, $imagePath, null);

        return $this->simulatedIfTry($order, $channel);
    }

    /**
     * Quantity from the caption ("2 ta lagbe" -> 2); 1 when there is no
     * caption, no lines, or the qty call itself fails.
     *
     * @param  array<string, mixed>|null  $qtyRaw  filled with the raw qty response, if any
     */
    private function qtyFromCaption(Shop $shop, ?string $caption, ?array &$qtyRaw): int
    {
        $qtyRaw = null;
        if ($caption === null || trim($caption) === '') {
            return 1;
        }
        try {
            $result = $this->spark->parseOrder($shop, $caption, null);
        } catch (MuseSparkException $e) {
            Log::warning('OrderIntake qty parse failed; defaulting to 1.', ['error' => $e->getMessage()]);

            return 1;
        }
        $qtyRaw = $result['raw'];
        $lines = $result['parsed']['lines'] ?? null;
        $first = is_array($lines) ? ($lines[0] ?? null) : null;
        if (! is_array($first)) {
            return 1;
        }

        return max(1, (int) round((float) ($first['qty'] ?? 1)));
    }

    private function simulatedIfTry(Order $order, string $channel): Order
    {
        if ($channel === 'try') {
            $order->update(['status' => OrderStatus::Simulated]);
        }

        return $order;
    }

    private function present(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return $value;
    }
}
