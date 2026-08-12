<?php

declare(strict_types=1);

namespace App\Enum;

enum CardPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';
}
