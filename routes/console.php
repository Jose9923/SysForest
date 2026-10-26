<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('sysforest:probe-scheduler')->everyMinute()->withoutOverlapping();
Schedule::command('sysforest:cleanup-report-downloads')->hourly()->withoutOverlapping();

Schedule::command('sysforest:audit-sync')->everyTenSeconds()->withoutOverlapping(10)
    ->when(fn (): bool => config('audit.store', 'postgres') === 'mongodb');

Schedule::command('sysforest:dispatch-carbon-refreshes')->everyMinute()->withoutOverlapping();
