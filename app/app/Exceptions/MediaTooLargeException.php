<?php

namespace App\Exceptions;

final class MediaTooLargeException extends \RuntimeException
{
    public function __construct(
        public readonly int $bytes,
        public readonly int $maxBytes,
    ) {
        parent::__construct(
            'Media file ('.self::mb($bytes).') exceeds the '.self::mb($maxBytes).' limit.'
        );
    }

    private static function mb(int $bytes): string
    {
        return rtrim(rtrim(sprintf('%.1f', $bytes / 1024 / 1024), '0'), '.').' MB';
    }
}
