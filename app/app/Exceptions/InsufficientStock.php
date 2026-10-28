<?php

namespace App\Exceptions;

final class InsufficientStock extends \RuntimeException
{
    /**
     * @param  list<array{product_id: int, name: string, wanted: int, available: int}>  $items
     */
    public function __construct(
        public readonly array $items,
    ) {
        parent::__construct(
            'Insufficient stock: '.implode(', ', array_map(
                fn (array $item): string => "{$item['name']} (wanted {$item['wanted']}, only {$item['available']} left)",
                $items
            )).'.'
        );
    }
}
