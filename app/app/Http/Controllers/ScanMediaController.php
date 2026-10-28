<?php

namespace App\Http\Controllers;

use App\Models\ProductImage;
use App\Models\Scan;
use Illuminate\Support\Facades\Storage;

class ScanMediaController extends Controller
{
    public function clip(Scan $scan)
    {
        $path = "scans/{$scan->id}/clip.mp4";
        $disk = Storage::disk('shared');
        if (! $disk->exists($path)) {
            abort(404);
        }

        return response()->file($disk->path($path), ['Content-Type' => 'video/mp4']);
    }

    public function image(ProductImage $image)
    {
        $disk = Storage::disk('shared');
        if (! $disk->exists($image->crop_path)) {
            abort(404);
        }

        return response()->file($disk->path($image->crop_path), ['Content-Type' => $disk->mimeType($image->crop_path)]);
    }

    public function crop(Scan $scan, string $name)
    {
        if (! preg_match('/^[A-Za-z0-9_-]+\.jpg$/', $name)) {
            abort(404);
        }
        $path = "scans/{$scan->id}/crops/{$name}";
        $disk = Storage::disk('shared');
        if (! $disk->exists($path)) {
            abort(404);
        }

        return response()->file($disk->path($path), ['Content-Type' => 'image/jpeg']);
    }

    public function sam(Scan $scan, string $name)
    {
        if (! preg_match('/^[A-Za-z0-9_.-]+$/', $name) || str_contains($name, '..')) {
            abort(404);
        }
        $path = "scans/{$scan->id}/sam/{$name}.txt";
        $disk = Storage::disk('shared');
        if (! $disk->exists($path)) {
            abort(404);
        }

        return response($disk->get($path), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
