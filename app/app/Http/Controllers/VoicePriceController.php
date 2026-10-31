<?php

namespace App\Http\Controllers;

use App\Enums\ScanItemDecision;
use App\Exceptions\VoiceTranscriberException;
use App\Models\Product;
use App\Models\Scan;
use App\Models\ScanItem;
use App\Services\Meta\MuseSpark;
use App\Services\Meta\VoiceTranscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class VoicePriceController extends Controller
{
    private const KEYWORDS = ['taka', 'tk', 'gram', 'kg', 'ml', 'litre', 'piece'];

    public function store(Request $request, ScanItem $scanItem): JsonResponse
    {
        $file = $this->validatedAudio($request);
        $path = $file->store('voice-prices', 'shared');
        $name = self::cardName($scanItem);

        $transcript = $this->transcribe($path, $scanItem->scan, $name !== null ? [$name] : []);
        if ($transcript === null) {
            return response()->json(['message' => 'No speech detected — hold the button and try again.'], 422);
        }
        $parsed = (new MuseSpark)->parseVoicePrice($transcript, $name);

        return response()->json([
            'transcript' => $transcript,
            'price' => $parsed['price'],
            'size_value' => $parsed['size_value'],
            'size_unit' => $parsed['size_unit'],
            'confidence' => $parsed['confidence'],
        ]);
    }

    public function storeAll(Request $request, Scan $scan): JsonResponse
    {
        $file = $this->validatedAudio($request);
        $items = $scan->items()
            ->where('decision', ScanItemDecision::Pending)
            ->orderBy('shelf_row')
            ->orderBy('shelf_order')
            ->get();
        if ($items->isEmpty()) {
            return response()->json(['message' => 'No pending cards to price.'], 422);
        }
        $path = $file->store('voice-prices', 'shared');
        $names = [];
        $cards = [];
        foreach ($items as $item) {
            $name = self::cardName($item);
            if ($name !== null) {
                $names[] = $name;
            }
            $cards[] = ['id' => $item->id, 'name' => $name ?? "Card {$item->id}"];
        }

        $transcript = $this->transcribe($path, $scan, $names);
        if ($transcript === null) {
            return response()->json(['message' => 'No speech detected — hold the button and try again.'], 422);
        }
        $parsed = (new MuseSpark)->parseVoicePrices($transcript, $cards);

        return response()->json([
            'transcript' => $transcript,
            'items' => $parsed['items'],
        ]);
    }

    private function validatedAudio(Request $request): UploadedFile
    {
        $request->validate([
            // finfo sniffs phone recordings by container: WebM audio arrives as
            // video/webm and MP4 audio as video/mp4. ffmpeg still converts them.
            'audio' => ['required', 'file', 'mimetypes:audio/webm,audio/ogg,audio/mp4,audio/x-m4a,audio/mpeg,audio/wav,audio/x-wav,video/webm,video/mp4', 'max:10240'],
        ]);
        $file = $request->file('audio');
        if (! $file instanceof UploadedFile) {
            abort(422);
        }

        return $file;
    }

    /**
     * @param  list<string>  $names
     */
    private function transcribe(string $path, Scan $scan, array $names): ?string
    {
        try {
            return (new VoiceTranscriber)->transcribe($path, $scan->shop, [...self::KEYWORDS, ...$names]);
        } catch (VoiceTranscriberException $e) {
            if (! str_contains($e->getMessage(), 'empty transcript')) {
                throw $e;
            }

            return null;
        }
    }

    private static function cardName(ScanItem $item): ?string
    {
        $name = trim((string) (($item->extracted ?? [])['name'] ?? ''));
        $matched = $item->matchedProduct;
        if (($name === '' || $name === 'Unnamed product') && $matched instanceof Product) {
            $name = trim((string) $matched->name);
        }

        return $name === '' ? null : $name;
    }
}
