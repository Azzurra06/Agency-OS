<?php

declare(strict_types=1);

namespace App\Cron;

final class JobResult
{
    public function __construct(public readonly int $itemsOk = 0, public readonly int $itemsFailed = 0)
    {
    }

    public function status(): string
    {
        if ($this->itemsFailed === 0) {
            return 'succeeded';
        }

        return $this->itemsOk > 0 ? 'partial' : 'failed';
    }
}
