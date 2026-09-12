#!/usr/bin/env php
<?php

declare(strict_types=1);

$expectedCommand = $argv[1] ?? '';

if (! in_array($expectedCommand, ['queue:work', 'schedule:work'], true)) {
    exit(1);
}

foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $path) {
    $command = @file_get_contents($path);

    if ($command === false) {
        continue;
    }

    $arguments = explode("\0", $command);

    if (in_array('artisan', $arguments, true) && in_array($expectedCommand, $arguments, true)) {
        exit(0);
    }
}

exit(1);
