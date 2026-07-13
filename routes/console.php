<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Data retention (config/retention.php): prune old conversations (off unless
// RETENTION_CHAT_DAYS is set) and purge long-trashed records, daily off-hours.
Schedule::command('chat:prune')->dailyAt('03:30');
