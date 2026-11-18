<?php

declare(strict_types=1);

namespace App\Message;

final readonly class RunDueCronTasksMessage
{
    public function __construct(
        public bool $forceAll = false,
    ) {
    }
}
