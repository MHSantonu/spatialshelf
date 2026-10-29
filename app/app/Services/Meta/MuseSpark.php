<?php

namespace App\Services\Meta;

use App\Exceptions\MuseSparkException;
use App\Models\Shop;
use App\Services\Orders\CatalogSnapshot;
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
     * @return array{items: array<int, array<string, mixed>>, raw: list<array<string, mixed>>, retries: list<int>, solos: list<int>}
     */
    public function readCrops(array $cropPaths): array
    {
        if ($cropPaths === []) {
            return ['items' => [], 'raw' => [], 'retries' => [], 'solos' => []];
        }
        if ($this->apiKey === '') {
            throw new MuseSparkException('MODEL_API_KEY is not configured.');
        }

        $paths = array_map($this->resolve(...), $cropPaths);
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
            $pooled = $responses["batch{$index}"];
            if ($pooled instanceof \Throwable) {
                throw new MuseSparkException(
                    "Muse Spark batch {$index} failed: {$pooled->getMessage()}.", 0, $pooled
                );
            }
            $raw = $this->rawBody($pooled, "batch {$index}");
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
     * Parse a customer order with muse-spark-1.3 (spec contract 2).
     *
     * Ported from spikes/parse_order.py: the shop's catalog table goes first
     * in the system message so prompt caching reuses it across messages,
     * then the versioned parse_order instructions; product_id's "<live ids>"
     * enum placeholder is replaced with the shop's real catalog ids plus
     * "none". The user message carries the text (a typed message or voice
     * transcript) and/or the list photo with EXIF rotation fixed. Audio
     * transcription is not part of this port; callers pass transcripts as
     * $text. Prices are never read from the model; PHP prices the lines.
     *
     * @param  string|null  $imagePath  absolute path or shared-storage-relative
     * @return array{parsed: array<string, mixed>, raw: array<string, mixed>}
     */
    public function parseOrder(Shop $shop, ?string $text, ?string $imagePath): array
    {
        $text = trim((string) $text);
        if ($text === '' && $imagePath === null) {
            throw new MuseSparkException('parseOrder needs text, an image, or both.');
        }
        if ($this->apiKey === '') {
            throw new MuseSparkException('MODEL_API_KEY is not configured.');
        }

        $snapshot = new CatalogSnapshot($shop->id);
        $payload = $this->orderPayload($snapshot, $text, $imagePath);

        $response = Http::timeout($this->timeout)
            ->withToken($this->apiKey)
            ->post($this->url(), $payload);
        $raw = $this->rawBody($response, 'parse_order');

        $content = $raw['choices'][0]['message']['content'] ?? null;
        $parsed = is_string($content) ? json_decode($content, true) : null;
        if (! is_array($parsed)) {
            throw new MuseSparkException('Muse Spark parse_order returned an unexpected payload.');
        }

        return ['parsed' => $parsed, 'raw' => $raw];
    }

    /**
     * Sort one customer photo: a written list, a single product, or other.
     *
     * Anything outside the three kinds (including an undecodable payload)
     * fails open to 'list', which keeps the previous parse-everything path.
     *
     * @return array{kind: string, raw: array<string, mixed>}
     */
    public function classifyImage(string $imagePath): array
    {
        if ($this->apiKey === '') {
            throw new MuseSparkException('MODEL_API_KEY is not configured.');
        }

        $response = Http::timeout($this->timeout)
            ->withToken($this->apiKey)
            ->post($this->url(), [
                'model' => self::MODEL,
                'messages' => [
                    ['role' => 'system', 'content' => Prompts::text('classify_image')],
                    ['role' => 'user', 'content' => [
                        ['type' => 'text', 'text' => 'What does this photo show?'],
                        [
                            'type' => 'image_url',
                            'image_url' => ['url' => $this->imageDataUrl($imagePath)],
                        ],
                    ]],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => ['name' => 'classify_image', 'schema' => Prompts::schema('classify_image')],
                ],
            ]);
        $raw = $this->rawBody($response, 'classify_image');

        $content = $raw['choices'][0]['message']['content'] ?? null;
        $parsed = is_string($content) ? json_decode($content, true) : null;
        $kind = is_array($parsed) ? ($parsed['kind'] ?? null) : null;
        if (! in_array($kind, ['list', 'single_product', 'other'], true)) {
            $kind = 'list';
        }

        return ['kind' => $kind, 'raw' => $raw];
    }

    /**
     * Read one spoken price with muse-spark-1.3: English, Bangla or Banglish
     * numbers ("chhoy sho ponchash" = 650, "dedh kg" = 1.5 kg) into a price
     * and an optional pack size. Nulls pass through untouched: the model
     * never guesses, and neither does PHP.
     *
     * @return array{price: float|null, size_value: float|null, size_unit: string, confidence: float|null, raw: array<string, mixed>}
     */
    public function parseVoicePrice(string $transcript, ?string $productName = null): array
    {
        $transcript = trim($transcript);
        if ($transcript === '') {
            throw new MuseSparkException('parseVoicePrice needs a transcript.');
        }
        if ($this->apiKey === '') {
            throw new MuseSparkException('MODEL_API_KEY is not configured.');
        }

        $heard = "Spoken price: {$transcript}";
        if (is_string($productName) && trim($productName) !== '') {
            $heard = 'Product: '.trim($productName)."\n{$heard}";
        }
        $response = Http::timeout($this->timeout)
            ->withToken($this->apiKey)
            ->post($this->url(), [
                'model' => self::MODEL,
                'messages' => [
                    ['role' => 'system', 'content' => Prompts::text('voice_price')],
                    ['role' => 'user', 'content' => $heard],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => ['name' => 'voice_price', 'schema' => Prompts::schema('voice_price')],
                ],
            ]);
        $raw = $this->rawBody($response, 'voice_price');

        $content = $raw['choices'][0]['message']['content'] ?? null;
        $parsed = is_string($content) ? json_decode($content, true) : null;
        if (! is_array($parsed)) {
            throw new MuseSparkException('Muse Spark voice_price returned an unexpected payload.');
        }

        return [
            'price' => self::voiceNumber($parsed['price'] ?? null),
            'size_value' => self::voiceNumber($parsed['size_value'] ?? null),
            'size_unit' => self::voiceUnit($parsed['size_unit'] ?? null),
            'confidence' => self::voiceNumber($parsed['confidence'] ?? null),
            'raw' => $raw,
        ];
    }

    /**
     * Read one long spoken price run ("Horlicks six fifty, Tang four
     * fifty") into per-card prices. Cards map by scan-item id with the
     * same live-id enum trick as parse_order; model rows for unknown ids
     * are dropped, "none" rows skipped.
     *
     * @param  list<array{id: int, name: string}>  $cards
     * @return array{items: list<array{item_id: int, price: float|null, size_value: float|null, size_unit: string, confidence: float|null}>, raw: array<string, mixed>}
     */
    public function parseVoicePrices(string $transcript, array $cards): array
    {
        $transcript = trim($transcript);
        if ($transcript === '') {
            throw new MuseSparkException('parseVoicePrices needs a transcript.');
        }
        if ($cards === []) {
            throw new MuseSparkException('parseVoicePrices needs at least one card.');
        }
        if ($this->apiKey === '') {
            throw new MuseSparkException('MODEL_API_KEY is not configured.');
        }

        $ids = [];
        $lines = [];
        foreach ($cards as $card) {
            $id = (string) $card['id'];
            $ids[] = $id;
            $lines[] = "{$id}: ".trim((string) $card['name']);
        }
        // PHP arrays copy on write, so this leaves the cached template alone.
        $schema = Prompts::schema('voice_prices');
        $schema['properties']['items']['items']['properties']['item_id']['enum'] = [...$ids, 'none'];

        $response = Http::timeout($this->timeout)
            ->withToken($this->apiKey)
            ->post($this->url(), [
                'model' => self::MODEL,
                'messages' => [
                    ['role' => 'system', 'content' => "Cards:\n".implode("\n", $lines)."\n\n".Prompts::text('voice_prices')],
                    ['role' => 'user', 'content' => "Spoken prices: {$transcript}"],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => ['name' => 'voice_prices', 'schema' => $schema],
                ],
            ]);
        $raw = $this->rawBody($response, 'voice_prices');

        $content = $raw['choices'][0]['message']['content'] ?? null;
        $parsed = is_string($content) ? json_decode($content, true) : null;
        $rows = is_array($parsed) ? ($parsed['items'] ?? null) : null;
        if (! is_array($rows)) {
            throw new MuseSparkException('Muse Spark voice_prices returned an unexpected payload.');
        }
        $known = array_fill_keys($ids, true);
        $items = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['item_id'] ?? 'none'));
            if ($id === 'none' || ! isset($known[$id]) || isset($items[$id])) {
                continue;
            }
            $items[$id] = [
                'item_id' => (int) $id,
                'price' => self::voiceNumber($row['price'] ?? null),
                'size_value' => self::voiceNumber($row['size_value'] ?? null),
                'size_unit' => self::voiceUnit($row['size_unit'] ?? null),
                'confidence' => self::voiceNumber($row['confidence'] ?? null),
            ];
        }

        return ['items' => array_values($items), 'raw' => $raw];
    }

    private static function voiceNumber(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }
        $number = (float) $value;

        return $number < 0 ? null : $number;
    }

    private static function voiceUnit(mixed $value): string
    {
        $unit = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($unit, ['g', 'kg', 'ml', 'l', 'pcs'], true) ? $unit : 'unknown';
    }

    /**
     * Re-read one failed batch: whole batch once more, then image by image.
     *
     * @param  list<string>  $batch  absolute crop paths
     * @param  list<array<string, mixed>>  $raws
     * @param  list<int>  $solos
     * @return array<int, array<string, mixed>>
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
        foreach ($batch as $i => $path) {
            $solo = $this->postBatch([$path], $index);
            $raws[] = $solo;
            $keyed[$i + 1] = $this->soloItem($solo, $index, $i + 1);
        }

        return $keyed;
    }

    /**
     * @param  list<string>  $batch
     * @return array<string, mixed>
     */
    private function postBatch(array $batch, int $index): array
    {
        $response = Http::timeout($this->timeout)
            ->withToken($this->apiKey)
            ->post($this->url(), $this->payload($batch));

        return $this->rawBody($response, "batch {$index}");
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
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

    /**
     * @param  list<string>  $batch
     * @return array<string, mixed>
     */
    private function payload(array $batch): array
    {
        $content = [];
        foreach ($batch as $i => $path) {
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

    /** @return array<string, mixed> */
    private function rawBody(Response $response, string $what): array
    {
        if ($response->failed()) {
            throw new MuseSparkException(
                "Muse Spark {$what} failed with HTTP {$response->status()}: {$this->errorExcerpt($response)}"
            );
        }
        $raw = $response->json();
        if (! is_array($raw)) {
            throw new MuseSparkException("Muse Spark {$what} returned an empty response.");
        }

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<int, array<string, mixed>>
     */
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

    /** @return array<string, mixed> */
    private function orderPayload(CatalogSnapshot $snapshot, string $text, ?string $imagePath): array
    {
        $table = $snapshot->table();
        $instructions = Prompts::text('parse_order');
        $system = $table === '' ? $instructions : $table."\n\n".$instructions;

        // PHP arrays copy on write, so this leaves the cached template alone.
        $schema = Prompts::schema('parse_order');
        $schema['properties']['lines']['items']['properties']['product_id']['enum'] =
            [...$snapshot->productIds(), 'none'];

        $content = [];
        if ($text !== '') {
            $content[] = ['type' => 'text', 'text' => "Customer order:\n{$text}"];
        }
        if ($imagePath !== null) {
            $content[] = [
                'type' => 'text',
                'text' => "Read the customer's handwritten order in this photo and match each item to the catalog.",
            ];
            $content[] = [
                'type' => 'image_url',
                'image_url' => ['url' => $this->imageDataUrl($imagePath)],
            ];
        }

        return [
            'model' => self::MODEL,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $content],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'parse_order', 'schema' => $schema],
            ],
        ];
    }

    /**
     * Image bytes with EXIF rotation applied, as a JPEG data URL.
     *
     * Mirrors the spike's Pillow exif_transpose: the image is rotated into
     * its upright orientation and re-encoded at quality 92. Bytes GD cannot
     * decode are sent as-is so an unusual format never breaks the call.
     */
    private function imageDataUrl(string $path): string
    {
        $resolved = $this->resolve($path);
        $bytes = is_file($resolved) ? file_get_contents($resolved) : false;
        if ($bytes === false) {
            throw new MuseSparkException("Image not found: {$resolved}.");
        }

        $fixed = $this->orientedJpeg($resolved, $bytes);

        return 'data:image/jpeg;base64,'.base64_encode($fixed ?? $bytes);
    }

    private function orientedJpeg(string $path, string $bytes): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }

        $oriented = $this->applyExifOrientation($path, $image);
        if ($oriented !== $image) {
            imagedestroy($image);
            $image = $oriented;
        }

        $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
        if ($flat === false) {
            imagedestroy($image);

            return null;
        }
        $white = imagecolorallocate($flat, 255, 255, 255);
        if ($white === false) {
            imagedestroy($flat);
            imagedestroy($image);

            return null;
        }
        imagefill($flat, 0, 0, $white);
        imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        imagedestroy($image);

        ob_start();
        $ok = imagejpeg($flat, null, 92);
        $jpeg = ob_get_clean();
        imagedestroy($flat);

        return $ok && is_string($jpeg) && $jpeg !== '' ? $jpeg : null;
    }

    private function applyExifOrientation(string $path, \GdImage $image): \GdImage
    {
        $orientation = 1;
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        }

        // imagerotate() turns counter-clockwise for positive angles.
        switch ($orientation) {
            case 2:
                imageflip($image, IMG_FLIP_HORIZONTAL);

                return $image;
            case 3:
                $rotated = imagerotate($image, 180, 0);
                break;
            case 4:
                imageflip($image, IMG_FLIP_VERTICAL);

                return $image;
            case 5:
                $rotated = imagerotate($image, 270, 0);
                if ($rotated !== false) {
                    imageflip($rotated, IMG_FLIP_HORIZONTAL);
                }
                break;
            case 6:
                $rotated = imagerotate($image, -90, 0);
                break;
            case 7:
                $rotated = imagerotate($image, 90, 0);
                if ($rotated !== false) {
                    imageflip($rotated, IMG_FLIP_HORIZONTAL);
                }
                break;
            case 8:
                $rotated = imagerotate($image, 90, 0);
                break;
            default:
                return $image;
        }

        return $rotated === false ? $image : $rotated;
    }

    private function resolve(string $path): string
    {
        if (str_starts_with($path, '/') || (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path)) {
            return $path;
        }

        return rtrim(config('spatialshelf.shared_storage_path'), '/\\').'/'.$path;
    }
}
