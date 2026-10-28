<?php

namespace App\Enums;

enum ScanItemDecision: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Merged = 'merged';
}
