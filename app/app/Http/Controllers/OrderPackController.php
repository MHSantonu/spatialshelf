<?php

namespace App\Http\Controllers;

use App\Exceptions\OrderStateException;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Orders\OrderService;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Http\RedirectResponse;
use Throwable;

class OrderPackController extends Controller
{
    public function __invoke(Order $order): RedirectResponse
    {
        try {
            $order = (new OrderService)->pack($order);
        } catch (OrderStateException $e) {
            return back()->withErrors(['pack' => $e->getMessage()]);
        }

        $notice = $this->notify($order);
        if ($notice === null) {
            return back();
        }

        return back()->with('packed_notice', $notice);
    }

    /**
     * Tell the customer their order is ready, unless the 24-hour
     * customer-service window has passed since their last message, in
     * which case Meta would reject the reply.
     *
     * @return array{type: string, text: string}|null null when there is nobody to notify
     */
    private function notify(Order $order): ?array
    {
        $customer = $order->customer;
        if ($order->channel() !== 'whatsapp' || ! $customer instanceof Customer) {
            return null;
        }
        $conversation = $customer->conversations()->orderByDesc('last_inbound_at')->first();
        $lastInbound = $conversation?->last_inbound_at;
        if (! $conversation instanceof Conversation || $lastInbound === null) {
            return [
                'type' => 'warning',
                'text' => 'Packed, but the customer was not notified: no recent WhatsApp conversation was found.',
            ];
        }
        if ($lastInbound->lt(now()->subDay())) {
            return [
                'type' => 'warning',
                'text' => 'Packed, but the customer was not notified: their last message is older than 24 hours, so WhatsApp would reject the reply.',
            ];
        }
        try {
            (new WhatsAppClient)->sendText(
                $customer->wa_id,
                "Your order #{$order->id} is packed and ready for pickup at {$order->shop->name}."
            );
        } catch (Throwable) {
            return [
                'type' => 'warning',
                'text' => 'Packed, but the WhatsApp message failed to send. The customer was not notified.',
            ];
        }

        return ['type' => 'success', 'text' => 'Packed. The customer was notified on WhatsApp.'];
    }
}
