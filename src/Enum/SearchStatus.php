<?php

declare(strict_types=1);

namespace App\Enum;

enum SearchStatus: string
{
    case Pending = 'pending';
    case Expanding = 'expanding';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
