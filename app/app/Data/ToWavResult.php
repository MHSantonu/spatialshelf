<?php

namespace App\Data;

readonly class ToWavResult
{
    public function __construct(
        public string $audioPath,
        public string $wavPath,
        public int $sampleRate,
        public int $channels,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['audio_path'],
            (string) $data['wav_path'],
            (int) $data['sample_rate'],
            (int) $data['channels'],
        );
    }
}
