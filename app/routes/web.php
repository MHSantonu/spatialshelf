<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::livewire('try', App\Livewire\TryPage::class)->name('try');

Route::get('webhooks/whatsapp', [App\Http\Controllers\WhatsAppWebhookController::class, 'verify'])->name('webhooks.whatsapp.verify');
Route::post('webhooks/whatsapp', [App\Http\Controllers\WhatsAppWebhookController::class, 'receive'])->name('webhooks.whatsapp.receive');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('dashboard', 'scan')->name('dashboard');
    Route::livewire('scan', App\Livewire\ScanPage::class)->name('scan');
    Route::livewire('scans/{scan}', App\Livewire\ScanShowPage::class)->name('scans.show');
    Route::livewire('scans/{scan}/review', App\Livewire\ScanReviewPage::class)->name('scans.review');
    Route::livewire('scans/{scan}/watch', App\Livewire\ScanWatchPage::class)->name('scans.watch');
    Route::get('scans/{scan}/clip', [App\Http\Controllers\ScanMediaController::class, 'clip'])->name('scans.clip');
    Route::get('scans/{scan}/crops/{name}', [App\Http\Controllers\ScanMediaController::class, 'crop'])
        ->where('name', '[A-Za-z0-9_-]+\.jpg')->name('scans.crop');
    Route::get('product-images/{image}', [App\Http\Controllers\ScanMediaController::class, 'image'])->name('product-images.show');
    Route::get('scans/{scan}/sam/{name}', [App\Http\Controllers\ScanMediaController::class, 'sam'])
        ->where('name', '[A-Za-z0-9_.-]+')->name('scans.sam');
    Route::livewire('stock', App\Livewire\StockPage::class)->name('stock');
    Route::livewire('orders', App\Livewire\OrdersPage::class)->name('orders');
});

require __DIR__.'/settings.php';
