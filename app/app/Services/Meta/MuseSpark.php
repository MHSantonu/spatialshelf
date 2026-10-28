<?php

namespace App\Services\Meta;

use App\Exceptions\MuseSparkException;
use App\Services\Prompts;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Reads shelf crops with muse-spark-1.3 (spec contract 1).
 *
 * Ported from spikes/read_crops.py read_variant(): up to 8 crops per chat
 * completion, each sent as an "Image <i>" text part plus a base64 JPEG
 * data URL, decoded with the read_crops JSON schema. Batches run
 * concurrently through Http::pool. Returned items are keyed by global
 * 1-based position across $cropPaths; item['image_index'] stays as the
 * model returned it (1-based within its batch).
 *
 * The model sometimes returns duplicate or missing image_index values; a
 * batch that fails validation is retried once, then re-read one image at
 * a time (mapped positionally, so indices cannot mismatch). Every attempt
 * lands in 'raw'; retried batch indices land in 'retries'/'solos'. HTTP
 * failures still throw immediately.
 */
final class MuseSpark
{
    private const MODEL = 'muse-spark-1.3';

    private const BATCH_SIZE = 8;

    private readonly string $apiKey;

    private readonly string $baseUrl;

    public function __construct(?string $apiKey = null, ?string $baseUrl = null, private readonly int $timeout = 180)
    {
        $this->apiKey = (string) ($apiKey ?? config('spatialshelf.model_api_key'));
        $this->baseUrl = rtrim((string) ($baseUrl ?? config('spatialshelf.meta_base_url')), '/');
    }

    /**
     * @param  list<string>  $cropPaths  absolute paths or shared-storage-relative
     * @return array{items: array<int, array>, raw: list<array>, retries: list<int>, solos: list<int>}
     */
    public function readCrops(array $cropPaths): array
    {
        if ($cropPaths === []) {
            return ['items' => [], 'raw' => [], 'retries' => [], 'solos' => []];
        }
        if ($this->apiKey === '') {
            throw new MuseSparkException('MODEL_API_KEY is not configured.');
        }

        $paths = array_map($this->resolve(...), array_values($cropPaths));
        foreach ($paths as $path) {
            if (! is_file($path)) {
                throw new MuseSparkException("Crop not found: {$path}.");
            }
        }

        $batches = array_chunk($paths, self::BATCH_SIZE);
        $payloads = array_map($this->payload(...), $batches);
        $url = $this->url();
        $responses = Http::pool(function (Pool $pool) use ($payloads, $url) {
            foreach ($payloads as $index => $payload) {
                $pool->as("batch{$index}")
                    ->timeout($this->timeout)
                    ->withToken($this->apiKey)
                    ->post($url, $payload);
            }
        });

        $items = [];
        $raws = [];
        $retries = [];
        $solos = [];
        $offset = 0;
        foreach ($batches as $index => $batch) {
            $raw = $this->rawBody($responses["batch{$index}"], $index);
            $raws[] = $raw;
            try {
                $keyed = $this->items($raw, count($batch), $index);
            } catch (MuseSparkException) {
                $retries[] = $index;
                $keyed = $this->recoverBatch($batch, $index, $raws, $solos);
            }
            foreach ($keyed as $imageIndex => $item) {
                $items[$offset + $imageIndex] = $item;
            }
            $offset += count($batch);
        }
        ksort($items);

        return ['items' => $items, 'raw' => $raws, 'retries' => $retries, 'solos' => $solos];
    }

    /**
     * Re-read one failed batch: whole batch once more, then image by image.
     *
     * @param  list<string>  $batch  absolute crop paths
     * @param  list<array>  $raws
     * @param  list<int>  $solos
     * @return array<int, array>
     */
    private function recoverBatch(array $batch, int $index, array &$raws, array &$solos): array
    {
        $raw = $this->postBatch($batch, $index);
        $raws[] = $raw;
        try {
            return $this->items($raw, count($batch), $index);
        } catch (MuseSparkException) {
            // Fall through to solo reads.
        }
        $solos[] = $index;
        $keyed = [];
        foreach (array_values($batch) as $i => $path) {
            $solo = $this->postBatch([$path], $index);
            $raws[] = $solo;
            $keyed[$i + 1] = $this->soloItem($solo, $index, $i + 1);
        }

        return $keyed;
    }

    /** @param  list<string>  $batch */
    private function postBatch(array $batch, int $index): array
    {
        $response = Http::timeout($this->timeout)
            ->withToken($this->apiKey)
            ->post($this->url(), $this->payload($batch));

        return $this->rawBody($response, $index);
    }

    private function soloItem(array $raw, int $batch, int $position): array
    {
        $content = $raw['choices'][0]['message']['content'] ?? null;
        $parsed = is_string($content) ? json_decode($content, true) : null;
        $list = is_array($parsed) ? ($parsed['items'] ?? null) : null;
        $first = is_array($list) ? (array_values($list)[0] ?? null) : null;
        if (! is_array($first)) {
            throw new MuseSparkException(
                "Muse Spark batch {$batch} solo image {$position} returned an unexpected payload."
            );
        }

        return $first;
    }

    private function url(): string
    {
        return $this->baseUrl.'/chat/completions';
    }

    private function payload(array $batch): array
    {
        $content = [];
        foreach (array_values($batch) as $i => $path) {
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                throw new MuseSparkException("Could not read crop: {$path}.");
            }
            $content[] = ['type' => 'text', 'text' => 'Image '.($i + 1)];
            $content[] = [
                'type' => 'image_url',
                'image_url' => ['url' => 'data:image/jpeg;base64,'.base64_encode($bytes)],
            ];
        }

        return [
            'model' => self::MODEL,
            'messages' => [
                ['role' => 'system', 'content' => Prompts::text('read_crops')],
                ['role' => 'user', 'content' => $content],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'read_crops', 'schema' => Prompts::schema('read_crops')],
            ],
        ];
    }

    private function rawBody(Response $response, int $batch): array
    {
        if ($response->failed()) {
            throw new MuseSparkException(
                "Muse Spark batch {$batch} failed with HTTP {$response->status()}: {$this->errorExcerpt($response)}"
            );
        }
        $raw = $response->json();
        if (! is_array($raw)) {
            throw new MuseSparkException("Muse Spark batch {$batch} returned an empty response.");
        }

        return $raw;
    }

    private function items(array $raw, int $expected, int $batch): array
    {
        $content = $raw['choices'][0]['message']['content'] ?? null;
        $parsed = is_string($content) ? json_decode($content, true) : null;
        $list = is_array($parsed) ? ($parsed['items'] ?? null) : null;
        if (! is_array($list)) {
            throw new MuseSparkException("Muse Spark batch {$batch} returned an unexpected payload.");
        }
        $keyed = [];
        foreach ($list as $item) {
            if (is_array($item) && isset($item['image_index'])) {
                $keyed[(int) $item['image_index']] = $item;
            }
        }
        ksort($keyed);
        if (count($list) !== $expected || array_keys($keyed) !== range(1, $expected)) {
            throw new MuseSparkException(
                "Muse Spark batch {$batch} must return exactly image_index 1..{$expected}."
            );
        }

        return $keyed;
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

    private function resolve(string $path): string
    {
        if (str_starts_with($path, '/') || (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path)) {
            return $path;
        }

        return rtrim(config('spatialshelf.shared_storage_path'), '/\\').'/'.$path;
    }
}
