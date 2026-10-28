<?php

namespace App\Support;

final class Money
{
    /**
     * Format a Taka amount with the ৳ sign, trimming useless decimals.
     *
     * The single place the ৳ sign is produced; every invoice, note and
     * stock figure goes through here so the output stays valid UTF-8.
     */
    public static function format(float $value): string
    {
        return '৳'.rtrim(rtrim(number_format($value, 2), '0'), '.');
    }
}
