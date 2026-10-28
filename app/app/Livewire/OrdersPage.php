<?php

namespace App\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.mobile')]
#[Title('Orders')]
class OrdersPage extends Component
{
    public function render()
    {
        return view('livewire.orders-page');
    }
}
