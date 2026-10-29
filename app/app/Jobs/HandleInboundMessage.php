<?php

namespace App\Jobs;

use App\Enums\ConversationState;
use App\Enums\OrderStatus;
use App\Exceptions\InsufficientStock;
use App\Exceptions\MediaTooLargeException;
use App\Exceptions\OrderStateException;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Shop;
use App\Services\Orders\OrderIntake;
use App\Services\Orders\OrderService;
use App\Services\WhatsApp\InvoiceFormatter;
use App\Services\WhatsApp\WhatsAppClient;
use App\Support\Money;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class HandleInboundMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    private const ACK = 'Got it, checking the shelf…';

    private const FALLBACK = 'Send me your list as text, a photo, or a voice note.';

    private const SORRY = 'Sorry, something went wrong. Please try again.';

    private const STALE = 'That order is no longer active. Send your list again to start a new one.';

    private const TOO_LARGE = 'That file is over the 16 MB limit. Please send a smaller photo or a shorter voice note.';

    private const MAX_MEDIA_BYTES = 16 * 1024 * 1024;

    private const NOTHING_MATCHED = "Sorry, we couldn't find any of those items on our shelf. Please check the list and send it again.";

    /** @param array<string, mixed> $message */
    public function __construct(
        public readonly array $message,
        public readonly ?string $contactName = null,
    ) {
        $this->queue = 'whatsapp';
    }

    public function handle(): void
    {
        $from = $this->message['from'] ?? null;
        if (! is_string($from) || $from === '') {
            Log::warning('HandleInboundMessage dropped a message without a sender.');

            return;
        }

        try {
            $this->process($from);
        } catch (Throwable $e) {
            Log::error('HandleInboundMessage failed.', [
                'message_id' => $this->message['id'] ?? null,
                'error' => $e->getMessage(),
            ]);
            try {
                (new WhatsAppClient)->sendText($from, self::SORRY);
            } catch (Throwable) {
                // Best effort only; the failure is already logged above.
            }
        }
    }

    private function process(string $from): void
    {
        $shop = Shop::query()->firstOrFail();
        $customer = $this->findCustomer($shop, $from);
        $conversation = Conversation::firstOrCreate(
            ['customer_id' => $customer->id],
            ['state' => ConversationState::Idle]
        );
        $conversation->update(['last_inbound_at' => now()]);

        $wa = new WhatsAppClient;
        $id = $this->message['id'] ?? null;
        if (is_string($id) && $id !== '') {
            $wa->markRead($id);
        }

        $type = $this->message['type'] ?? null;
        if ($type === 'text' || $type === 'image' || $type === 'audio') {
            $this->handleOrderMessage($wa, $from, $shop, $customer, $conversation, $type);
        } elseif ($type === 'interactive') {
            $this->handleInteractive($wa, $from, $customer, $conversation);
        } else {
            $wa->sendText($from, self::FALLBACK);
        }
    }

    private function findCustomer(Shop $shop, string $from): Customer
    {
        $customer = Customer::firstOrCreate(
            ['shop_id' => $shop->id, 'wa_id' => $from],
            ['name' => $this->contactName]
        );
        if (is_string($this->contactName) && $this->contactName !== '' && $customer->name !== $this->contactName) {
            $customer->update(['name' => $this->contactName]);
        }

        return $customer;
    }

    private function handleOrderMessage(WhatsAppClient $wa, string $from, Shop $shop, Customer $customer, Conversation $conversation, string $type): void
    {
        $wa->sendText($from, self::ACK);

        $text = null;
        $imagePath = null;
        $audioPath = null;
        if ($type === 'text') {
            $text = $this->nonEmpty($this->message['text']['body'] ?? null);
        } elseif ($type === 'image') {
            $mediaId = $this->nonEmpty($this->message['image']['id'] ?? null);
            if ($mediaId !== null) {
                try {
                    $imagePath = $wa->downloadMedia($mediaId, self::MAX_MEDIA_BYTES);
                } catch (MediaTooLargeException) {
                    $wa->sendText($from, self::TOO_LARGE);

                    return;
                }
            }
            $text = $this->nonEmpty($this->message['image']['caption'] ?? null);
        } else {
            $mediaId = $this->nonEmpty($this->message['audio']['id'] ?? null);
            if ($mediaId !== null) {
                try {
                    $audioPath = $wa->downloadMedia($mediaId, self::MAX_MEDIA_BYTES);
                } catch (MediaTooLargeException) {
                    $wa->sendText($from, self::TOO_LARGE);

                    return;
                }
            }
        }

        $order = (new OrderIntake)->handle($shop, $customer, 'whatsapp', $text, $imagePath, $audioPath);
        $this->cancelPendingDraft($conversation, (int) $order->id);
        $this->resolveDraft($wa, $from, $conversation, $order);
    }

    private function handleInteractive(WhatsAppClient $wa, string $from, Customer $customer, Conversation $conversation): void
    {
        $interactive = $this->message['interactive'] ?? null;
        if (! is_array($interactive)) {
            $wa->sendText($from, self::FALLBACK);

            return;
        }
        $kind = $interactive['type'] ?? null;
        $replyId = null;
        if ($kind === 'button_reply') {
            $replyId = $interactive['button_reply']['id'] ?? null;
        } elseif ($kind === 'list_reply') {
            $replyId = $interactive['list_reply']['id'] ?? null;
        }
        if (! is_string($replyId) || $replyId === '') {
            $wa->sendText($from, self::FALLBACK);

            return;
        }

        [$action, $first, $second] = array_pad(explode(':', $replyId, 3), 3, null);
        if ($action === 'pick') {
            $this->handlePick($wa, $from, $conversation, $first, $second);
        } elseif ($action === 'skip') {
            $this->handleSkip($wa, $from, $conversation, $first);
        } elseif ($action === 'confirm') {
            $this->handleConfirm($wa, $from, $customer, $conversation, $first);
        } elseif ($action === 'cancel') {
            $this->handleCancel($wa, $from, $customer, $conversation, $first);
        } elseif ($action === 'change') {
            $wa->sendText($from, 'Send the full list again, with your changes.');
        } else {
            $wa->sendText($from, self::FALLBACK);
        }
    }

    private function handlePick(WhatsAppClient $wa, string $from, Conversation $conversation, mixed $lineId, mixed $productId): void
    {
        $order = $this->pendingDraft($conversation);
        $line = $order instanceof Order ? $this->orderLine($order, $lineId) : null;
        if (! $order instanceof Order || ! $line instanceof OrderLine) {
            $wa->sendText($from, self::FALLBACK);

            return;
        }
        $product = Product::query()
            ->where('shop_id', $order->shop_id)
            ->where('active', true)
            ->find($productId);
        if (! $product instanceof Product) {
            $this->markNotFound($line);
            $this->recount($order);
            $this->resolveDraft($wa, $from, $conversation, $order);

            return;
        }

        $unit = round((float) $product->price, 2);
        $stock = (int) $product->stock_qty;
        $wanted = max(1, (int) $line->qty);
        $qty = min($wanted, $stock);
        $note = null;
        if ($qty < $wanted) {
            $note = $stock === 0
                ? 'Out of stock at '.Money::format($unit)." each; wanted {$wanted}."
                : "Only {$stock} in stock at ".Money::format($unit)." each; wanted {$wanted}.";
        }
        $line->update([
            'product_id' => $product->id,
            'qty' => $qty,
            'unit_price' => $unit,
            'line_total' => round($qty * $unit, 2),
            'status' => 'matched',
            'note' => $note,
        ]);
        $this->recount($order);
        $this->resolveDraft($wa, $from, $conversation, $order);
    }

    private function handleSkip(WhatsAppClient $wa, string $from, Conversation $conversation, mixed $lineId): void
    {
        $order = $this->pendingDraft($conversation);
        $line = $order instanceof Order ? $this->orderLine($order, $lineId) : null;
        if (! $order instanceof Order || ! $line instanceof OrderLine) {
            $wa->sendText($from, self::FALLBACK);

            return;
        }

        $this->markNotFound($line);
        $this->recount($order);
        $this->resolveDraft($wa, $from, $conversation, $order);
    }

    private function handleConfirm(WhatsAppClient $wa, string $from, Customer $customer, Conversation $conversation, mixed $orderId): void
    {
        $order = $this->customerOrder($customer, $orderId);
        // Larastan reads the string column, but casts() makes this an enum at runtime.
        /** @var OrderStatus|null $status */
        $status = $order?->status;
        if (! $order instanceof Order || $status !== OrderStatus::Draft) {
            $wa->sendText($from, self::STALE);

            return;
        }

        try {
            $confirmed = (new OrderService)->confirm($order);
        } catch (InsufficientStock $e) {
            $this->applyShortages($order, $e->items);
            $wa->sendText($from, $this->shortagesText($e->items));
            $conversation->update([
                'pending_order_id' => $order->id,
                'state' => ConversationState::AwaitingConfirmation,
            ]);
            $this->sendInvoice($wa, $from, $order->refresh());

            return;
        } catch (OrderStateException) {
            $wa->sendText($from, self::STALE);

            return;
        }

        if ((int) $conversation->pending_order_id === (int) $confirmed->id) {
            $conversation->update(['pending_order_id' => null, 'state' => ConversationState::Idle]);
        }
        $wa->sendText($from, "Order #{$confirmed->id} confirmed. We'll message you when it's packed.");
    }

    private function handleCancel(WhatsAppClient $wa, string $from, Customer $customer, Conversation $conversation, mixed $orderId): void
    {
        $order = $this->customerOrder($customer, $orderId);
        /** @var OrderStatus|null $status */
        $status = $order?->status;
        if (! $order instanceof Order || $status !== OrderStatus::Draft) {
            $wa->sendText($from, self::STALE);

            return;
        }

        try {
            $cancelled = (new OrderService)->cancel($order);
        } catch (OrderStateException) {
            $wa->sendText($from, self::STALE);

            return;
        }

        if ((int) $conversation->pending_order_id === (int) $cancelled->id) {
            $conversation->update(['pending_order_id' => null, 'state' => ConversationState::Idle]);
        }
        $wa->sendText($from, 'Order cancelled.');
    }

    /**
     * Clarify the first ambiguous line, or invoice when none remain. Lines
     * with no live alternatives cannot be asked about, so they drop
     * straight to not_found instead of stalling the conversation.
     */
    private function resolveDraft(WhatsAppClient $wa, string $from, Conversation $conversation, Order $order): void
    {
        $conversation->update(['pending_order_id' => $order->id]);

        while (($line = $this->firstAmbiguousLine($order)) instanceof OrderLine) {
            $options = $this->alternatives($order, $line);
            if ($options === []) {
                $this->markNotFound($line);
                $this->recount($order);

                continue;
            }
            $conversation->update(['state' => ConversationState::AwaitingClarification]);
            $this->sendClarification($wa, $from, $line, $options);

            return;
        }

        if (! $order->lines()->where('status', 'matched')->exists()) {
            (new OrderService)->cancel($order);
            $conversation->update(['pending_order_id' => null, 'state' => ConversationState::Idle]);
            $wa->sendText($from, self::NOTHING_MATCHED);

            return;
        }

        $conversation->update(['state' => ConversationState::AwaitingConfirmation]);
        $this->sendInvoice($wa, $from, $order);
    }

    private function firstAmbiguousLine(Order $order): ?OrderLine
    {
        return $order->lines()->where('status', 'ambiguous')->orderBy('id')->first();
    }

    /** @return list<Product> */
    private function alternatives(Order $order, OrderLine $line): array
    {
        $ids = $this->cleanIds($line->alternative_ids ?? []);
        if ($ids === []) {
            return [];
        }
        $products = Product::query()
            ->where('shop_id', $order->shop_id)
            ->where('active', true)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy(fn (Product $product): string => (string) $product->id);

        $options = [];
        foreach ($ids as $id) {
            $product = $products->get($id);
            if ($product instanceof Product) {
                $options[] = $product;
            }
        }

        return array_slice($options, 0, 9);
    }

    /** @return list<string> */
    private function cleanIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(fn ($id): string => trim((string) $id), $ids),
            fn (string $id): bool => $id !== ''
        )));
    }

    /** @param list<Product> $options */
    private function sendClarification(WhatsAppClient $wa, string $from, OrderLine $line, array $options): void
    {
        $rows = [];
        foreach ($options as $product) {
            $title = trim((string) $product->name);
            $title = $title === '' ? "Item #{$product->id}" : mb_substr($title, 0, 24, 'UTF-8');
            $size = InvoiceFormatter::compactSize($product);
            $description = $size === ''
                ? Money::format((float) $product->price)
                : $size.' — '.Money::format((float) $product->price);
            $rows[] = [
                "pick:{$line->id}:{$product->id}",
                $title,
                mb_substr($description, 0, 72, 'UTF-8'),
            ];
        }
        $rows[] = ["skip:{$line->id}", 'Skip this item', 'Remove it from your order'];

        $requested = trim((string) ($line->requested_text ?? ''));
        $wa->sendList(
            $from,
            'Which '.($requested === '' ? 'item' : $requested).' do you want?',
            'Choose',
            $rows
        );
    }

    private function sendInvoice(WhatsAppClient $wa, string $from, Order $order): void
    {
        $invoice = (new InvoiceFormatter)->format($order);
        $wa->sendButtons($from, $invoice['body'], $invoice['buttons']);
    }

    /**
     * @param  list<array{product_id: int, name: string, wanted: int, available: int}>  $items
     */
    private function applyShortages(Order $order, array $items): void
    {
        foreach ($items as $item) {
            $line = $order->lines()->where('product_id', $item['product_id'])->first();
            if (! $line instanceof OrderLine) {
                continue;
            }
            $unit = round((float) $line->unit_price, 2);
            $available = max(0, $item['available']);
            $wanted = max(1, $item['wanted']);
            $qty = min((int) $line->qty, $available);
            $line->update([
                'qty' => $qty,
                'line_total' => round($qty * $unit, 2),
                'note' => $available === 0
                    ? 'Out of stock at '.Money::format($unit)." each; wanted {$wanted}."
                    : "Only {$available} in stock at ".Money::format($unit)." each; wanted {$wanted}.",
            ]);
        }
        $this->recount($order);
    }

    /** @param list<array{product_id: int, name: string, wanted: int, available: int}> $items */
    private function shortagesText(array $items): string
    {
        $out = ['Short on stock:'];
        foreach ($items as $item) {
            $available = max(0, $item['available']);
            $wanted = max(1, $item['wanted']);
            $name = trim($item['name']);
            $out[] = $available === 0
                ? "• {$name}: wanted {$wanted}, none left"
                : "• {$name}: wanted {$wanted}, only {$available} left";
        }

        return implode("\n", $out);
    }

    private function markNotFound(OrderLine $line): void
    {
        $line->update([
            'product_id' => null,
            'unit_price' => 0,
            'line_total' => 0,
            'status' => 'not_found',
            'note' => null,
        ]);
    }

    private function recount(Order $order): void
    {
        $order->update(['total' => round((float) $order->lines()->sum('line_total'), 2)]);
    }

    private function cancelPendingDraft(Conversation $conversation, int $exceptId): void
    {
        $pendingId = $conversation->pending_order_id;
        if ($pendingId === null || (int) $pendingId === $exceptId) {
            return;
        }
        $order = Order::query()->find($pendingId);
        /** @var OrderStatus|null $status */
        $status = $order?->status;
        if (! $order instanceof Order || $status !== OrderStatus::Draft) {
            return;
        }
        try {
            (new OrderService)->cancel($order);
        } catch (OrderStateException) {
            // Lost a race with another tap; nothing left to cancel.
        }
    }

    private function pendingDraft(Conversation $conversation): ?Order
    {
        $pendingId = $conversation->pending_order_id;
        if ($pendingId === null) {
            return null;
        }
        $order = Order::query()->find($pendingId);
        /** @var OrderStatus|null $status */
        $status = $order?->status;
        if (! $order instanceof Order || $status !== OrderStatus::Draft) {
            return null;
        }
        if ((int) $order->customer_id !== (int) $conversation->customer_id) {
            return null;
        }

        return $order;
    }

    private function orderLine(Order $order, mixed $lineId): ?OrderLine
    {
        if ((! is_string($lineId) && ! is_int($lineId)) || ! ctype_digit((string) $lineId)) {
            return null;
        }

        return $order->lines()->whereKey($lineId)->first();
    }

    private function customerOrder(Customer $customer, mixed $orderId): ?Order
    {
        if ((! is_string($orderId) && ! is_int($orderId)) || ! ctype_digit((string) $orderId)) {
            return null;
        }

        return Order::query()->whereKey($orderId)->where('customer_id', $customer->id)->first();
    }

    private function nonEmpty(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
