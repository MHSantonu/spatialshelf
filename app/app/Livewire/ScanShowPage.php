<?php

namespace App\Livewire;

use App\Enums\ScanStatus;
use App\Jobs\ProcessScan;
use App\Models\Scan;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.mobile')]
#[Title('Scan')]
class ScanShowPage extends Component
{
    /** The six progress steps ProcessScan reports, in order. */
    public const STEPS = [
        'Preparing video',
        'Choosing what to look for',
        'Finding products',
        'Cutting out products',
        'Checking memory',
        'Reading labels',
    ];

    public Scan $scan;

    public function mount(Scan $scan): void
    {
        $this->scan = $scan;
    }

    public function retry(): void
    {
        $this->scan->update([
            'status' => ScanStatus::Pending,
            'stats' => ['progress' => 'Queued'],
            'started_at' => now(),
            'finished_at' => null,
        ]);

        ProcessScan::dispatch($this->scan->id);
    }

    public function render()
    {
        $this->scan->refresh();
        $stats = $this->scan->stats ?? [];

        return view('livewire.scan-show-page', [
            'steps' => self::STEPS,
            'currentStep' => $this->currentStepIndex($stats['progress'] ?? null),
            'phrases' => $stats['phrases'] ?? [],
            'elapsed' => $this->scan->started_at
                ? (int) $this->scan->started_at->diffInSeconds($this->scan->finished_at ?? now())
                : 0,
            'itemCount' => $this->scan->items()->count(),
            'knownCount' => $this->scan->items()->whereNotNull('matched_product_id')->count(),
            'memorySeconds' => $stats['timings']['memory'] ?? null,
            'readSeconds' => $stats['timings']['read'] ?? null,
            'error' => $stats['error'] ?? null,
            'hasOverlay' => $this->scan->overlaySlug() !== null,
            'finished' => in_array($this->scan->status, [ScanStatus::Completed, ScanStatus::Failed], true),
        ]);
    }

    /**
     * Index into STEPS for a progress string, or -1 when queued.
     * 'Saving results' and anything past the six steps count as done.
     */
    private function currentStepIndex(?string $progress): int
    {
        if ($progress === null) {
            return -1;
        }
        $index = array_search($progress, self::STEPS, true);

        return $index === false ? count(self::STEPS) : $index;
    }
}
