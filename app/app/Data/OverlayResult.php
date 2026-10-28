<?php

namespace App\Data;

readonly class OverlayResult
{
    public function __construct(
        public string $phrase,
        public string $status,
        public ?string $path,
        public ?float $seconds,
        public ?string $error,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            $data['phrase'],
            $data['status'],
            isset($data['path']) ? (string) $data['path'] : null,
            isset($data['seconds']) ? (float) $data['seconds'] : null,
            isset($data['error']) ? (string) $data['error'] : null,
        );
    }

    public function isDone(): bool
    {
        return $this->status === 'done';
    }
}
