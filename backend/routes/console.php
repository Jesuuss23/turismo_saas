<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('idempotency:purge')->daily();
Schedule::command('audit:partitions')->monthly();
Schedule::command('crm:activities-remind')->hourly(); // JOB-04 (spec 01)
