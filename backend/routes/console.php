<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('idempotency:purge')->daily();
Schedule::command('audit:partitions')->monthly();
