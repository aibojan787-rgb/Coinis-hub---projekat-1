<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('app:fail-stale-photos')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('queue:prune-failed', ['--hours' => 168])->daily();
