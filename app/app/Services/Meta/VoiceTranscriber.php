<?php

namespace App\Services\Meta;

use App\Exceptions\VoiceTranscriberException;
use App\Models\Product;
use App\Models\Shop;
use App\Services\WorkerClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Transcribes voice notes with muse-voice-transcribe-1.0 (spec contract 3).
 *
 * Ported from spikes/parse_order.py transcribe(): the note is converted to
 * mono 16-bit PCM WAV at 24 kHz by the worker, then posted as multipart
 * with a JSON "request" part and the "audio" part. The shop's product and
 * brand names ride along as keywords so the model favors words from the
 * shelf. Returns the transcript; an empty one throws, since there is
 * nothing to order from.
 */
final class VoiceTranscriber
{
    private const MODEL = 'muse-voice-transcribe-1.0';

    private const MAX_KEYWORDS = 50;

    private const MAX_KEYWORD_LENGTH = 30;

    private readonly string $apiKey;

    private readonly string $baseUrl;

    private readonly WorkerClient $worker;

    public function __construct(?string $apiKey = null, ?string $baseUrl = null, private readonly int $timeout = 180, ?WorkerClient $worker = null)
    {
        $this->apiKey = (string) ($apiKey ?? config('spatialshelf.model_api_key'));
        $this->baseUrl = rtrim((string) ($baseUrl ?? config('spatialshelf.meta_base_url')), '/');
        $this->worker = $worker ?? new WorkerClient(timeout: $this->timeout);
    }

    /**
     * @param  string  $path  audio path relative to shared storage
     * @param  list<string>  $extraKeywords  biased first, e.g. price words plus the card's product name
     */
    public function transcribe(string $path, Shop $shop, array $extraKeywords = []): string
    {
        if ($this->apiKey === '') {
            throw new VoiceTranscriberException('MODEL_API_KEY is not configured.');
        }

        $wav = $this->worker->toWav($path);
        $resolved = $this->resolve($wav->wavPath);
        $bytes = is_file($resolved) ? file_get_contents($resolved) : false;
        if ($bytes === false) {
            throw new VoiceTranscriberException("WAV not found: {$resolved}.");
        }

        $response = Http::timeout($this->timeout)
            ->withToken($this->apiKey)
            ->attach('request', json_encode($this->requestPayload($shop, $extraKeywords), JSON_THROW_ON_ERROR), null, ['Content-Type' => 'application/json'])
            ->attach('audio', $bytes, basename($resolved), ['Content-Type' => 'audio/wav'])
            ->post($this->url());

        if ($response->failed()) {
            throw new VoiceTranscriberException(
                "Voice transcription failed with HTTP {$response->status()}: {$this->errorExcerpt($response)}"
            );
        }
        $raw = $response->json();
        $transcript = $this->transcript(is_array($raw) ? $raw : []);
        if ($transcript === '') {
            throw new VoiceTranscriberException('Voice transcription returned an empty transcript.');
        }

        return $transcript;
    }

    /**
     * @param  list<string>  $extraKeywords
     * @return array<string, mixed>
     */
    private function requestPayload(Shop $shop, array $extraKeywords): array
    {
        return [
            'model' => self::MODEL,
            'mode' => 'PUSH_TO_TALK',
            'audioEncoding' => 'WAV',
            'languageBias' => ['English', 'Bengali'],
            'keywords' => $this->keywords($shop, $extraKeywords),
        ];
    }

    /**
     * @param  list<string>  $extraKeywords
     * @return list<string>
     */
    private function keywords(Shop $shop, array $extraKeywords = []): array
    {
        $products = Product::query()
            ->select(['name', 'brand'])
            ->where('shop_id', $shop->id)
            ->where('active', true)
            ->orderBy('name')
            ->get();

        $names = [];
        $brands = [];
        foreach ($products as $product) {
            $name = trim((string) $product->name);
            if ($name !== '') {
                $names[] = $name;
            }
            $brand = trim((string) ($product->brand ?? ''));
            if ($brand !== '') {
                $brands[] = $brand;
            }
        }

        $extra = [];
        foreach ($extraKeywords as $keyword) {
            $keyword = trim((string) $keyword);
            if ($keyword !== '') {
                $extra[] = $keyword;
            }
        }

        $keywords = [];
        foreach (array_unique([...$extra, ...$names, ...$brands]) as $candidate) {
            $keyword = $this->truncateKeyword($candidate);
            if ($keyword !== '' && ! in_array($keyword, $keywords, true)) {
                $keywords[] = $keyword;
            }
        }

        return array_slice($keywords, 0, self::MAX_KEYWORDS);
    }

    /**
     * ASR rejects any keyword over 30 characters, so long product names
     * are cut back to the last whole word that fits. A single word with
     * no space to cut at keeps its first 30 characters.
     */
    private function truncateKeyword(string $keyword): string
    {
        if (mb_strlen($keyword) <= self::MAX_KEYWORD_LENGTH) {
            return $keyword;
        }
        $cut = mb_substr($keyword, 0, self::MAX_KEYWORD_LENGTH);
        if (mb_substr($keyword, self::MAX_KEYWORD_LENGTH, 1) !== ' ' && mb_substr($cut, -1) !== ' ') {
            $space = mb_strrpos($cut, ' ');
            if ($space !== false) {
                $cut = mb_substr($cut, 0, $space);
            }
        }

        return rtrim($cut);
    }

    /** @param  array<string, mixed>  $raw */
    private function transcript(array $raw): string
    {
        $direct = trim((string) ($raw['transcript'] ?? ''));
        if ($direct !== '') {
            return $direct;
        }
        $turns = $raw['turns'] ?? [];
        if (! is_array($turns)) {
            return '';
        }
        $parts = [];
        foreach ($turns as $turn) {
            $text = is_array($turn) ? trim((string) ($turn['transcript'] ?? '')) : '';
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return implode(' ', $parts);
    }

    private function errorExcerpt(Response $response): string
    {
        $body = $response->json();
        $message = is_array($body) ? ($body['error']['message'] ?? null) : null;
        if (! is_string($message) || $message === '') {
            $message = $response->body();
        }

        return strlen($message) > 500 ? substr($message, 0, 500).'...' : $message;
    }

    private function url(): string
    {
        return $this->baseUrl.'/asr/transcribe';
    }

    private function resolve(string $path): string
    {
        if (str_starts_with($path, '/') || (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path)) {
            return $path;
        }

        return rtrim(config('spatialshelf.shared_storage_path'), '/\\').'/'.$path;
    }
}
