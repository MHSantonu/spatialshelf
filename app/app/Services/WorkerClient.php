<?php

namespace App\Services;

use App\Data\CropsResult;
use App\Data\EmbedResult;
use App\Data\PrepareResult;
use App\Data\SegmentResult;
use App\Exceptions\WorkerException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Typed client for the Python scan worker.
 *
 * Base URL comes from config (spatialshelf.worker_url). Transport-level
 * connection errors are retried twice; HTTP 4xx/5xx responses throw a
 * WorkerException carrying the worker's own error message.
 */
final class WorkerClient
{
    private readonly string $baseUrl;

    public function __construct(?string $baseUrl = null, private readonly int $timeout = 180)
    {
        $this->baseUrl = $baseUrl ?? config('spatialshelf.worker_url');
    }

    public function prepare(string $scanId, string $videoPath): PrepareResult
    {
        return PrepareResult::fromArray($this->post('/scan/prepare', [
            'scan_id' => $scanId,
            'video_path' => $videoPath,
        ]));
    }

    /** @param  list<string>|null  $phrases */
    public function segment(string $scanId, ?array $phrases = null): SegmentResult
    {
        return SegmentResult::fromArray($this->post('/scan/segment', [
            'scan_id' => $scanId,
            'phrases' => $phrases,
        ]));
    }

    public function crops(string $scanId): CropsResult
    {
        return CropsResult::fromArray($this->post('/scan/crops', [
            'scan_id' => $scanId,
        ]));
    }

    public function embed(string $imagePath): EmbedResult
    {
        return EmbedResult::fromArray($this->post('/embed', [
            'image_path' => $imagePath,
        ]));
    }

    private function post(string $path, array $payload): array
    {
        // retry() counts total attempts (3 = initial + 2 retries) and
        // throw: false keeps 4xx/5xx responses on the return path so they
        // surface as WorkerException below instead of RequestException.
        $response = Http::baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->retry(3, 100, fn (Throwable $exception): bool => $exception instanceof ConnectionException, false)
            ->post($path, $payload);

        if ($response->failed()) {
            throw WorkerException::fromResponse($response);
        }

        return $response->json()
            ?? throw new WorkerException('Worker returned an empty response body.', $response->status());
    }
}
