<?php

namespace App\Exceptions;

use Illuminate\Http\Client\Response;

final class WorkerException extends \RuntimeException
{
    public function __construct(string $message, private readonly int $status = 0)
    {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    public static function fromResponse(Response $response): self
    {
        $body = $response->json();
        $detail = is_array($body)
            ? ($body['detail'] ?? $body['Detail'] ?? $body['message'] ?? null)
            : null;
        if (is_array($detail)) {
            $detail = json_encode($detail);
        }
        $message = is_string($detail) && $detail !== ''
            ? $detail
            : "Worker request failed with HTTP {$response->status()}.";

        return new self($message, $response->status());
    }
}
