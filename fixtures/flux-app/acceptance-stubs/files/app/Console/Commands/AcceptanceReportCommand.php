<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class AcceptanceReportCommand extends Command
{
    protected $signature = 'acceptance:report';

    protected $description = 'Acceptance reporting command';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
