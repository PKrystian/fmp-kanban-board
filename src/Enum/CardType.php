<?php

declare(strict_types=1);

namespace App\Enum;

enum CardType: string
{
    case Task = 'task';
    case Bug = 'bug';
    case Story = 'story';
}
