<?php

namespace App\Livewire;

use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Services\Orders\OrderIntake;
use App\Support\Money;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.visitor')]
#[Title('Try ordering')]
class TryPage extends Component
{
    use WithFileUploads;

    public string $tab = 'type';

    public string $text = '';

    /** @var mixed */
    public $photo;

    /** @var mixed */
    public $audio;

    public ?int $orderId = null;

    public bool $simulated = false;

    public function selectTab(string $tab): void
    {
        if (! in_array($tab, ['type', 'photo', 'voice'], true)) {
            return;
        }
        $this->tab = $tab;
        $this->resetValidation();
    }

    public function submit(): void
    {
        $key = 'try-order:'.(request()->ip() ?? 'unknown');
        if (RateLimiter::tooManyAttempts($key, 10)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));
            $this->addError('form', "Too many tries — please come back in {$minutes} minute".($minutes === 1 ? '' : 's').'.');

            return;
        }
        RateLimiter::hit($key, 3600);

        $text = null;
        $imagePath = null;
        $audioPath = null;
        if ($this->tab === 'type') {
            $this->validate(['text' => ['required', 'string', 'max:2000']]);
            $text = trim($this->text);
        } elseif ($this->tab === 'photo') {
            $this->validate(['photo' => ['required', 'file', 'image', 'max:10240']]);
            $imagePath = (string) $this->photo->store('try', 'shared');
        } else {
            $this->validate(['audio' => ['required', 'file', 'mimetypes:audio/webm,audio/ogg,audio/mp4,audio/x-m4a,audio/mpeg,audio/wav,audio/x-wav', 'max:10240']]);
            $audioPath = (string) $this->audio->store('try', 'shared');
        }

        $order = (new OrderIntake)->handle(
            Shop::query()->firstOrFail(), null, 'try', $text, $imagePath, $audioPath
        );
        $this->orderId = $order->id;
        $this->simulated = false;
    }

    /**
     * Show what confirming would do to stock, without writing anything:
     * /try orders stay simulated so judges cannot drain the demo shop.
     */
    public function confirmSimulated(): void
    {
        if ($this->orderId !== null) {
            $this->simulated = true;
        }
    }

    public function newOrder(): void
    {
        $this->reset(['tab', 'text', 'photo', 'audio', 'orderId', 'simulated']);
        $this->resetValidation();
    }

    public function render(): View
    {
        $order = $this->orderId !== null
            ? Order::query()->with('lines.product')->find($this->orderId)
            : null;

        return view('livewire.try-page', [
            'order' => $order,
            'invoice' => $order !== null ? $this->invoice($order) : null,
            'simulation' => $order !== null && $this->simulated ? $this->simulation($order) : null,
        ]);
    }

    /**
     * @return array{lines: list<array{text: string, note: ?string}>, total: string, unavailable: list<string>, ambiguous: list<array{requested: string, options: list<string>}>}
     */
    private function invoice(Order $order): array
    {
        $lines = [];
        $unavailable = [];
        $ambiguous = [];
        foreach ($order->lines->sortBy('id') as $line) {
            if ($line->status === 'not_found') {
                if (trim((string) $line->requested_text) !== '') {
                    $unavailable[] = trim((string) $line->requested_text);
                }

                continue;
            }
            if ($line->status === 'ambiguous' || $line->product_id === null) {
                $ambiguous[] = [
                    'requested' => (string) ($line->requested_text ?? ''),
                    'options' => $this->optionNames((int) $order->shop_id, $line->alternative_ids ?? []),
                ];

                continue;
            }
            $product = $line->product;
            if (! $product instanceof Product) {
                if (trim((string) $line->requested_text) !== '') {
                    $unavailable[] = trim((string) $line->requested_text);
                }

                continue;
            }
            $size = $this->compactSize($product);
            $lines[] = [
                'text' => "{$line->qty} × {$product->name}".($size === '' ? '' : " {$size}").' — '.Money::format((float) $line->line_total),
                'note' => $line->note !== null ? (string) $line->note : null,
            ];
        }

        return [
            'lines' => $lines,
            'total' => Money::format((float) $order->total),
            'unavailable' => $unavailable,
            'ambiguous' => $ambiguous,
        ];
    }

    /** @return list<array{name: string, before: int, after: int}> */
    private function simulation(Order $order): array
    {
        $rows = [];
        foreach ($order->lines->sortBy('id') as $line) {
            $product = $line->product;
            if ($line->product_id === null || ! $product instanceof Product) {
                continue;
            }
            $before = (int) $product->stock_qty;
            $rows[] = [
                'name' => (string) $product->name,
                'before' => $before,
                'after' => max(0, $before - (int) $line->qty),
            ];
        }

        return $rows;
    }

    /**
     * @param  mixed  $ids
     * @return list<string>
     */
    private function optionNames(int $shopId, $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }
        $ids = array_values(array_unique(array_map(
            fn ($id): string => trim((string) $id), $ids
        )));
        if ($ids === []) {
            return [];
        }
        $products = Product::query()
            ->where('shop_id', $shopId)
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get()
            ->keyBy('id');

        $options = [];
        foreach ($ids as $id) {
            $product = $products->get((int) $id);
            if (! $product instanceof Product) {
                continue;
            }
            $size = $this->compactSize($product);
            $options[] = (string) $product->name.($size === '' ? '' : " {$size}");
        }

        return $options;
    }

    private function compactSize(Product $product): string
    {
        if (is_numeric($product->size_value) && (float) $product->size_value > 0) {
            $value = rtrim(rtrim(sprintf('%.2f', (float) $product->size_value), '0'), '.');

            return $value.trim((string) $product->size_unit);
        }

        return '';
    }
}
