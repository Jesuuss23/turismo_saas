<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('idempotency:purge')->daily();
Schedule::command('audit:partitions')->monthly();
Schedule::command('crm:activities-remind')->hourly(); // JOB-04 (spec 01)
Schedule::command('catalog:expire-holds')->everyFiveMinutes()->withoutOverlapping(); // JOB-01 (spec 02)
Schedule::command('catalog:daily')->dailyAt('00:10'); // contratos vencidos y alertas de vigencia (spec 02 §8.2)
Schedule::command('quotes:expire')->dailyAt('00:05'); // JOB-02 (spec 03)
Schedule::command('ops:monitor sla')->everyFiveMinutes()->withoutOverlapping(); // IncidentSlaMonitor (spec 05 §8.3)
Schedule::command('ops:monitor watch')->everyThirtyMinutes()->withoutOverlapping(); // JOB-09 y JOB-06
Schedule::command('ops:monitor daily')->dailyAt('05:00'); // JOB-05 y alertas de licencias/SOAT
Schedule::command('ops:monitor purge')->dailyAt('03:30'); // PurgeMobileSyncLog
Schedule::command('supplier:monitor remind')->everyTwoHours()->withoutOverlapping(); // RemindPendingSupplierRequests (spec 06 §8)
Schedule::command('supplier:monitor daily')->dailyAt('00:20'); // bloqueos vencidos, contratos por vencer y vigencias de tarifa
Schedule::command('fin:mark-overdue')->dailyAt('00:05'); // CxC/CxP vencidas (spec 07 §8.2)
Schedule::command('fin:payment-reminders')->dailyAt('09:00'); // recordatorios de pago (DOC-PROC-001 §26.3)
Schedule::command('fin:run fiscal-poll')->everyFiveMinutes()->withoutOverlapping(); // PollFiscalStatus
Schedule::command('channels:run holds')->everyFiveMinutes()->withoutOverlapping(); // ExpireEcomHolds (spec 08 §8.2)
Schedule::command('channels:run agreements')->dailyAt('06:00'); // convenios B2B vencidos/por vencer
Schedule::command('channels:run credit')->dailyAt('02:30'); // conciliación crédito B2B vs. libro
Schedule::command('approvals:sla-check')->everyFiveMinutes()->withoutOverlapping(); // SLA de aprobaciones (spec 10 §8)
Schedule::command('inbox:first-response-sla')->everyMinute()->withoutOverlapping(); // SLA de 1.ª respuesta (INB-BR-01)
Schedule::command('allotments:release')->dailyAt('00:15'); // JOB-10 ReleaseAllotments (spec 11 §8)
Schedule::command('travel-docs:remind')->dailyAt('08:00'); // JOB-11 TravelDocReminders
