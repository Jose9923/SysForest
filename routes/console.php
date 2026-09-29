<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('sysforest:probe-scheduler')->everyMinute()->withoutOverlapping();
Schedule::command('sysforest:cleanup-report-downloads')->hourly()->withoutOverlapping();
