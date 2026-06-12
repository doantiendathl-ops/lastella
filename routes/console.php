<?php

use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Support\Facades\Artisan;

Artisan::command('about:pms', function (ClosureCommand $command): void {
    $command->info('Lastella PMS core system');
})->purpose('Display PMS application information');
