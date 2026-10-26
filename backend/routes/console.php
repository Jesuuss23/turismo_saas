<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('idempotency:purge')->daily();
Schedule::command('audit:partitions')->monthly();
Schedule::command('crm:activities-remind')->hourly(); // JOB-04 (spec 01)
Schedule::command('catalog:expire-holds')->everyFiveMinutes()->withoutOverlapping(); // JOB-01 (spec 02)
Schedule::command('catalog:daily')->dailyAt('00:10'); // contratos vencidos y alertas de vigencia (spec 02 §8.2)
