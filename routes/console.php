<?php

use App\Jobs\DispatchDueReminders;
use App\Jobs\DispatchPendingRemoteSocialPostDeletions;
use App\Jobs\DispatchPendingStoredFileDeletions;
use App\Jobs\DispatchScheduledPublications;
use App\Jobs\ExpireUserExports;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new DispatchScheduledPublications)
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(5);

Schedule::job(new DispatchDueReminders)
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(5);

Schedule::job(new DispatchPendingStoredFileDeletions)
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10);

Schedule::job(new DispatchPendingRemoteSocialPostDeletions)
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10);

Schedule::job(new ExpireUserExports)
    ->dailyAt('03:20')
    ->onOneServer()
    ->withoutOverlapping(60);
