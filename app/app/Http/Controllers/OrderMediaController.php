<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OrderMediaController extends Controller
{
    public function input(Order $order): BinaryFileResponse
    {
        $path = $order->raw_input_path;
        if (! is_string($path) || trim($path) === '' || str_contains($path, '..')) {
            abort(404);
        }
        $disk = Storage::disk('shared');
        if (! $disk->exists($path)) {
            abort(404);
        }

        return response()->file($disk->path($path), ['Content-Type' => $disk->mimeType($path)]);
    }
}
