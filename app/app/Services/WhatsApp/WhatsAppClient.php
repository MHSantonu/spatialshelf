<?php

namespace App\Services\WhatsApp;

use App\Exceptions\MediaTooLargeException;
use App\Exceptions\WhatsAppException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Typed client for the WhatsApp Cloud API (Graph v26.0).
 *
 * Ported from spikes/wa_echo.py: the same bearer-token auth, the same
 * POST /{phone_number_id}/messages send path, and the same two-step media
 * download (GET /{media_id} for the URL, then GET the URL with the token).
 * Media bytes land in shared storage under wa-media/ so the worker can
 * read them too. Every failed Graph call logs Meta's error code and
 * message, then throws a WhatsAppException carrying both.
 */
final class WhatsAppClient
{
    public const GRAPH_BASE_URL = 'https://graph.facebook.com/v26.0';

    private const MEDIA_DIR = 'wa-media';

    private const DOWNLOAD_TIMEOUT = 60;

    private const MAX_BUTTONS = 3;

    private const MAX_BUTTON_TITLE = 20;

    private const MAX_ROWS = 10;

    private const MAX_ROW_TITLE = 24;

    private const MAX_ROW_DESCRIPTION = 72;

    /** Ported from wa_echo.py EXT_BY_MIME. */
    private const EXT_BY_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'audio/ogg' => 'ogg',
        'audio/mpeg' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/aac' => 'aac',
        'audio/amr' => 'amr',
        'audio/wav' => 'wav',
    ];

    private readonly string $accessToken;

    private readonly string $phoneNumberId;

    private readonly string $baseUrl;

    private readonly string $sharedStoragePath;

    public function __construct(
        ?string $accessToken = null,
        ?string $phoneNumberId = null,
        ?string $baseUrl = null,
        ?string $sharedStoragePath = null,
        private readonly int $timeout = 20,
    ) {
        $this->accessToken = (string) ($accessToken ?? config('spatialshelf.wa_access_token'));
        $this->phoneNumberId = (string) ($phoneNumberId ?? config('spatialshelf.wa_phone_number_id'));
        $this->baseUrl = rtrim((string) ($baseUrl ?? self::GRAPH_BASE_URL), '/');
        $this->sharedStoragePath = rtrim(
            (string) ($sharedStoragePath ?? config('spatialshelf.shared_storage_path')),
            '/\\'
        );
    }

    public function sendText(string $to, string $body): void
    {
        $this->postMessages([
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $body],
        ], 'Send text message');
    }

    /**
     * @param  list<array>  $buttons  [id, title] pairs, max 3, titles <= 20 chars
     */
    public function sendButtons(string $to, string $body, array $buttons): void
    {
        if ($buttons === []) {
            throw new InvalidArgumentException('At least one button is required.');
        }
        if (count($buttons) > self::MAX_BUTTONS) {
            throw new InvalidArgumentException('At most '.self::MAX_BUTTONS.' buttons are allowed.');
        }

        $payload = [];
        foreach ($buttons as $button) {
            [$id, $title] = [$button[0] ?? '', $button[1] ?? ''];
            if (! is_string($id) || $id === '') {
                throw new InvalidArgumentException('Every button needs a non-empty id.');
            }
            if (! is_string($title) || $title === '') {
                throw new InvalidArgumentException('Every button needs a non-empty title.');
            }
            if (mb_strlen($title) > self::MAX_BUTTON_TITLE) {
                throw new InvalidArgumentException(
                    "Button title '{$title}' exceeds ".self::MAX_BUTTON_TITLE.' characters.'
                );
            }
            $payload[] = ['type' => 'reply', 'reply' => ['id' => $id, 'title' => $title]];
        }

        $this->postMessages([
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => ['text' => $body],
                'action' => ['buttons' => $payload],
            ],
        ], 'Send button message');
    }

    /**
     * @param  list<array>  $rows  [id, title, description?] tuples, max 10
     */
    public function sendList(string $to, string $body, string $buttonText, array $rows): void
    {
        if ($rows === []) {
            throw new InvalidArgumentException('At least one list row is required.');
        }
        if (count($rows) > self::MAX_ROWS) {
            throw new InvalidArgumentException('At most '.self::MAX_ROWS.' list rows are allowed.');
        }

        $payload = [];
        foreach ($rows as $row) {
            [$id, $title] = [$row[0] ?? '', $row[1] ?? ''];
            $description = $row[2] ?? '';
            if (! is_string($id) || $id === '') {
                throw new InvalidArgumentException('Every list row needs a non-empty id.');
            }
            if (! is_string($title) || $title === '') {
                throw new InvalidArgumentException('Every list row needs a non-empty title.');
            }
            if (mb_strlen($title) > self::MAX_ROW_TITLE) {
                throw new InvalidArgumentException(
                    "List row title '{$title}' exceeds ".self::MAX_ROW_TITLE.' characters.'
                );
            }
            if (! is_string($description)) {
                throw new InvalidArgumentException('List row descriptions must be strings.');
            }
            if (mb_strlen($description) > self::MAX_ROW_DESCRIPTION) {
                throw new InvalidArgumentException(
                    'List row descriptions must be at most '.self::MAX_ROW_DESCRIPTION.' characters.'
                );
            }
            $item = ['id' => $id, 'title' => $title];
            if ($description !== '') {
                $item['description'] = $description;
            }
            $payload[] = $item;
        }

        $this->postMessages([
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'body' => ['text' => $body],
                'action' => [
                    'button' => $buttonText,
                    'sections' => [['title' => 'Options', 'rows' => $payload]],
                ],
            ],
        ], 'Send list message');
    }

    public function markRead(string $messageId): void
    {
        if ($messageId === '') {
            throw new InvalidArgumentException('A message id is required to mark a message read.');
        }

        $this->postMessages([
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $messageId,
        ], 'Mark message read');
    }

    /**
     * Download a media object into shared storage.
     *
     * @param  int|null  $maxBytes  when set, media over this size is rejected
     *                              with MediaTooLargeException, checked first
     *                              against the metadata file_size (no bytes
     *                              downloaded) and then against the body.
     * @return string path relative to shared storage, e.g. wa-media/12345.jpg
     */
    public function downloadMedia(string $mediaId, ?int $maxBytes = null): string
    {
        $this->requireConfig();
        if ($mediaId === '') {
            throw new InvalidArgumentException('A media id is required to download media.');
        }

        $meta = Http::timeout($this->timeout)
            ->withToken($this->accessToken)
            ->get("{$this->baseUrl}/{$mediaId}");
        $this->throwIfFailed($meta, 'Fetch media URL');

        $info = $meta->json();
        $info = is_array($info) ? $info : [];
        $url = $info['url'] ?? null;
        if (! is_string($url) || $url === '') {
            throw new WhatsAppException("Fetch media URL failed: Graph returned no url for media {$mediaId}.");
        }
        $declared = $info['file_size'] ?? null;
        if ($maxBytes !== null && is_numeric($declared) && (int) $declared > $maxBytes) {
            throw new MediaTooLargeException((int) $declared, $maxBytes);
        }
        $mime = $info['mime_type'] ?? 'application/octet-stream';
        $ext = self::EXT_BY_MIME[$mime] ?? self::extensionFromMime($mime);

        $data = Http::timeout(self::DOWNLOAD_TIMEOUT)
            ->withToken($this->accessToken)
            ->get($url);
        $this->throwIfFailed($data, 'Download media bytes');
        if ($maxBytes !== null && strlen($data->body()) > $maxBytes) {
            throw new MediaTooLargeException(strlen($data->body()), $maxBytes);
        }

        $dir = $this->sharedStoragePath.'/'.self::MEDIA_DIR;
        if (! is_dir($dir) && ! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new WhatsAppException("Could not create media directory: {$dir}.");
        }
        $relative = self::MEDIA_DIR."/{$mediaId}.{$ext}";
        if (file_put_contents($this->sharedStoragePath.'/'.$relative, $data->body()) === false) {
            throw new WhatsAppException("Could not save media file: {$relative}.");
        }

        return $relative;
    }

    /** @param  array<string, mixed>  $payload */
    private function postMessages(array $payload, string $action): void
    {
        $this->requireConfig();

        $response = Http::timeout($this->timeout)
            ->withToken($this->accessToken)
            ->post("{$this->baseUrl}/{$this->phoneNumberId}/messages", $payload);
        $this->throwIfFailed($response, $action);
    }

    private function throwIfFailed(Response $response, string $action): void
    {
        if ($response->successful()) {
            return;
        }

        $body = $response->json();
        $error = is_array($body) ? ($body['error'] ?? null) : null;
        $error = is_array($error) ? $error : [];
        // The token itself is never logged, only Meta's code and message.
        Log::error('WhatsApp API request failed.', [
            'action' => $action,
            'status' => $response->status(),
            'code' => $error['code'] ?? 0,
            'message' => $error['message'] ?? $response->body(),
        ]);

        throw WhatsAppException::fromResponse($response, $action);
    }

    private function requireConfig(): void
    {
        if ($this->accessToken === '') {
            throw new WhatsAppException('WA_ACCESS_TOKEN is not configured.');
        }
        if ($this->phoneNumberId === '') {
            throw new WhatsAppException('WA_PHONE_NUMBER_ID is not configured.');
        }
    }

    private static function extensionFromMime(string $mime): string
    {
        $subtype = explode('/', $mime, 2)[1] ?? '';
        $subtype = strtolower(explode(';', $subtype)[0]);
        $subtype = explode('+', $subtype)[0];
        $subtype = (string) preg_replace('/[^a-z0-9]/', '', $subtype);

        return $subtype !== '' ? $subtype : 'bin';
    }
}
