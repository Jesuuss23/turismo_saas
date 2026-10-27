<?php

use Illuminate\Support\Facades\Artisan;
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
Schedule::command('treasury:cash-deposit-alerts')->hourly(); // JOB-12 TES-BR-09 (spec 12 §8)
Schedule::command('treasury:open-session-check')->dailyAt('10:00'); // JOB-13 TES-BR-05
Schedule::command('treasury:cash-flow-snapshot')->dailyAt('06:00'); // JOB-14 flujo proyectado
Schedule::command('claims:deadline-alerts')->dailyAt('08:00'); // JOB-15 plazos del libro de reclamaciones

// Spec 13 §8.2: plataforma SaaS (alias de la spec → platform:run {job}).
foreach (['billing:renewals' => 'renewals', 'billing:dunning' => 'dunning', 'billing:trials' => 'trials', 'billing:expire-cards' => 'expire-cards',
    'usage:consolidate' => 'usage-consolidate', 'platform:metrics-snapshot' => 'metrics-snapshot', 'api-keys:expire' => 'api-keys-expire',
    'webhooks:deliver' => 'webhooks-deliver', 'webhooks:purge' => 'webhooks-purge'] as $alias => $job) {
    Artisan::command($alias, fn () => $this->call('platform:run', ['job' => $job]))->purpose("Plataforma SaaS: {$job}");
}
Schedule::command('billing:renewals')->dailyAt('06:00')->withoutOverlapping();
Schedule::command('billing:dunning')->dailyAt('10:00')->withoutOverlapping();
Schedule::command('billing:trials')->dailyAt('08:00');
Schedule::command('billing:expire-cards')->monthly();
Schedule::command('usage:consolidate')->dailyAt('00:15');
Schedule::command('platform:metrics-snapshot')->dailyAt('01:00');
Schedule::command('api-keys:expire')->daily();
Schedule::command('webhooks:deliver')->everyMinute()->withoutOverlapping();
Schedule::command('webhooks:purge')->daily();

// Spec 14 §8: marketing y metas comerciales.
Schedule::command('marketing:run dispatch-scheduled')->everyMinute()->withoutOverlapping(); // JOB-16 DispatchScheduledCampaigns
Schedule::command('marketing:run automations')->everyFifteenMinutes()->withoutOverlapping(); // JOB-17 RunAutomations
Schedule::command('marketing:run attribution')->hourly(); // JOB-18 RecomputeAttribution
Schedule::command('marketing:run targets-pending-close')->dailyAt('08:00'); // JOB-19 SalesTargetsPendingClose
