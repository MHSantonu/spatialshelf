<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Shop;
use App\Services\Meta\MuseSpark;
use App\Services\Meta\VoiceTranscriber;

/**
 * Takes a customer order from any surface to a priced draft.
 *
 * The single entry point both /try and WhatsApp use: audio is
 * transcribed first, then the text and/or image go to Muse Spark for
 * parsing, then the parsed lines become a draft order. The channel is
 * validated and recorded in orders.context; the source_type comes from
 * whichever input carried the order (voice beats photo beats text).
 * Orders from /try land as simulated, never draft, so demos and judges
 * cannot move real stock; only WhatsApp orders await confirmation.
 */
final class OrderIntake
{
    private readonly VoiceTranscriber $transcriber;

    private readonly MuseSpark $spark;

    private readonly DraftOrderBuilder $builder;

    public function __construct(?VoiceTranscriber $transcriber = null, ?MuseSpark $spark = null, ?DraftOrderBuilder $builder = null)
    {
        $this->transcriber = $transcriber ?? new VoiceTranscriber;
        $this->spark = $spark ?? new MuseSpark;
        $this->builder = $builder ?? new DraftOrderBuilder;
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
        $texts = array_filter(
            [trim((string) $text), trim((string) $transcript)],
            fn (string $part): bool => $part !== ''
        );
        $result = $this->spark->parseOrder($shop, $texts === [] ? null : implode("\n", $texts), $imagePath);

        $order = $this->builder->build(
            $shop,
            $customer,
            $audioPath !== null ? 'voice' : ($imagePath !== null ? 'photo' : 'text'),
            array_merge($result['parsed'], ['raw' => $result['raw'], 'channel' => $channel]),
            $audioPath ?? $imagePath,
            $transcript,
        );
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
