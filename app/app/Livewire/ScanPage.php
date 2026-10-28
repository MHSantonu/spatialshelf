<?php

namespace App\Livewire;

use App\Enums\ScanStatus;
use App\Jobs\ProcessScan;
use App\Models\Scan;
use App\Models\Shop;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.mobile')]
#[Title('Scan')]
class ScanPage extends Component
{
    use WithFileUploads;

    #[Validate(['required', 'file', 'mimetypes:video/*', 'max:204800'])]
    public $video;

    public function updatedVideo(): void
    {
        $this->validateOnly('video');
        $path = $this->video->store('uploads', 'shared');

        $scan = Scan::create([
            'shop_id' => Shop::query()->firstOrFail()->id,
            'video_path' => $path,
            'status' => ScanStatus::Pending,
        ]);

        ProcessScan::dispatch($scan->id);

        $this->redirect(route('scans.show', $scan), navigate: true);
    }

    public function render()
    {
        return view('livewire.scan-page', [
            'scans' => Scan::query()->latest()->take(5)->withCount('items')->get(),
        ]);
    }
}
