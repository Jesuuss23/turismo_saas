<?php

namespace App\Services\Integrations;

use App\Models\Bookings\Reservation;
use App\Models\Crm\Lead;
use App\Models\Finance\Invoice;
use App\Models\Finance\Payment;
use App\Models\Ops\Incident;
use App\Models\Ops\Voucher;
use App\Models\Quotes\Quote;
use App\Services\Platform\UsageMeter;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Eventos de dominio → webhooks (spec 13 §8.4) y medición de reservas (§8.3). Se escucha a los modelos y se despacha al confirmar
 * la transacción; el `data` lleva solo campos públicos (sin costos ni márgenes). Nunca bloquea el documento de origen.
 */
class WebhookEventBridge
{
    public static function register(): void
    {
        Reservation::created(function (Reservation $r) {
            self::meter('reservations_month');
            self::emit('reservation.created', $r);
        });
        Reservation::updated(fn (Reservation $r) => match (true) {
            $r->wasChanged('status') && $r->status === Reservation::CONFIRMED => self::emit('reservation.confirmed', $r),
            $r->wasChanged('status') && $r->status === Reservation::CANCELLED => self::emit('reservation.cancelled', $r),
            default => null,
        });
        Quote::updated(fn (Quote $q) => match (true) {
            $q->wasChanged('status') && $q->status === Quote::SENT => self::emit('quote.sent', $q),
            $q->wasChanged('status') && $q->status === Quote::APPROVED => self::emit('quote.approved', $q),
            default => null,
        });
        Lead::created(fn (Lead $l) => self::emit('lead.created', $l));
        Incident::created(fn (Incident $i) => self::emit('incident.created', $i));
        Incident::updated(fn (Incident $i) => $i->wasChanged('status') && $i->status === Incident::RESOLVED ? self::emit('incident.resolved', $i) : null);
        Payment::saved(fn (Payment $p) => match (true) {
            ($p->wasRecentlyCreated || $p->wasChanged('status')) && $p->status === 'aplicado' => self::emit('payment.applied', $p),
            $p->wasChanged('status') && $p->status === 'revertido' => self::emit('payment.reversed', $p),
            default => null,
        });
        Voucher::saved(fn (Voucher $v) => ($v->wasRecentlyCreated || $v->wasChanged('status')) && $v->status === Voucher::ISSUED ? self::emit('voucher.issued', $v) : null);
        Invoice::saved(function (Invoice $i) {
            $issued = ['emitido', 'aceptado'];
            if (in_array($i->status, $issued, true) && ($i->wasRecentlyCreated || ($i->wasChanged('status') && ! in_array($i->getOriginal('status'), $issued, true)))) {
                self::emit('invoice.issued', $i);
            }
        });
    }

    /** Hay webhooks activos en el tenant (caché de 60 s para no consultar en cada evento). */
    public static function hasHooks(string $tenantId): bool
    {
        return (bool) Cache::remember(self::flagKey($tenantId), 60, fn () => DB::table('integration_webhooks')->where('status', 'active')->whereNull('deleted_at')->exists());
    }

    public static function flagKey(string $tenantId): string
    {
        return "wh:active:{$tenantId}";
    }

    private static function emit(string $type, Model $model): void
    {
        $context = app(TenantContext::class);
        $tenant = $context->id();
        if ($tenant === null) {
            return;
        }
        try {
            if (! self::hasHooks($tenant)) {
                return;
            }
            $data = self::data($type, $model);
            $code = $model->getAttribute('code') ?? $model->getAttribute('number');
            DB::afterCommit(function () use ($context, $tenant, $type, $code, $data) {
                try {
                    $context->run($tenant, fn () => app(WebhookService::class)->dispatch($type, $code, $data));
                } catch (Throwable $e) {
                    Log::warning('Webhooks: no se pudo despachar el evento', ['type' => $type, 'error' => $e->getMessage()]);
                }
            });
        } catch (Throwable $e) {
            Log::warning('Webhooks: evento no despachado', ['type' => $type, 'error' => $e->getMessage()]);
        }
    }

    private static function meter(string $metric): void
    {
        $tenant = app(TenantContext::class)->id();
        if ($tenant !== null) {
            try {
                app(UsageMeter::class)->increment($tenant, $metric);
            } catch (Throwable) {
                // La medición nunca bloquea la operación.
            }
        }
    }

    /** Campos públicos del recurso por tipo de evento. */
    private static function data(string $type, Model $m): array
    {
        $date = fn ($v) => $v ? (string) (is_object($v) ? $v->format('Y-m-d') : substr((string) $v, 0, 10)) : null;

        return match (explode('.', $type)[0]) {
            'reservation' => ['code' => $m->code, 'title' => $m->title, 'status' => $m->status, 'channel' => $m->channel, 'travel_from' => $date($m->travel_from),
                'travel_to' => $date($m->travel_to), 'currency' => $m->currency, 'total' => (float) $m->total_amount],
            'quote' => ['code' => $m->code, 'title' => $m->title, 'status' => $m->status, 'travel_from' => $date($m->travel_from), 'travel_to' => $date($m->travel_to),
                'currency' => $m->currency, 'valid_until' => $date($m->valid_until)],
            'lead' => ['code' => $m->code, 'full_name' => $m->full_name, 'email' => $m->email, 'phone' => $m->phone, 'channel' => $m->channel_code, 'status' => $m->status],
            'incident' => ['code' => $m->code, 'type' => $m->type, 'severity' => $m->severity, 'title' => $m->public_title ?? $m->title, 'status' => $m->status],
            'payment' => ['code' => $m->code, 'amount' => (float) $m->amount, 'currency' => $m->currency, 'method' => $m->method, 'status' => $m->status, 'paid_on' => $date($m->paid_on)],
            'voucher' => ['code' => $m->code, 'service' => $m->service_label, 'service_date' => $date($m->service_date), 'pax' => $m->pax, 'status' => $m->status],
            'invoice' => ['number' => $m->number, 'doc_type' => $m->doc_type, 'issue_date' => $date($m->issue_date), 'currency' => $m->currency, 'total' => (float) $m->total_amount,
                'status' => $m->status],
            default => ['code' => $m->getAttribute('code')],
        };
    }
}
