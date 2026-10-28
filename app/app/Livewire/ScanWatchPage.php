<?php

namespace App\Livewire;

use App\Models\Scan;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.mobile')]
#[Title('Watch scan')]
class ScanWatchPage extends Component
{
    public Scan $scan;

    public string $overlaySlug;

    public function mount(Scan $scan): void
    {
        $this->scan = $scan;
        $this->overlaySlug = $scan->overlaySlug() ?? abort(404);
    }

    public function render()
    {
        return view('livewire.scan-watch-page');
    }
}
