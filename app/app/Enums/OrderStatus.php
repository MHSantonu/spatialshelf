<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Packed = 'packed';
    case Cancelled = 'cancelled';
    case Simulated = 'simulated';
}
