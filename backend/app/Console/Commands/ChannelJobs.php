<?php

namespace App\Console\Commands;

use App\Models\Channel\B2bAgency;
use App\Models\Sys\NotifMessage;
use App\Services\AuditLogger;
use App\Services\Catalog\CatalogRecipients;
use App\Services\Channels\B2bCredit;
use App\Services\Channels\EcomAdmin;
use App\Services\Crm\CrmClock;
use App\Services\Notify;
use App\Support\OperatingTenants;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Jobs de canales (spec 08 §8.2):
 * - `holds` (cada 5 min): ExpireEcomHolds — pedidos web sin pago aprobado al vencer el hold (ECO-BR-11).
 * - `agreements` (diario): convenios B2B vencidos o por vencer (≤ 30 días) avisan a la agencia y a gerencia.
 * - `credit` (diario): conciliación de `credit_used` con el libro de movimientos; las diferencias se auditan y avisan a Finanzas.
 */
class ChannelJobs extends Command
{
    public const MODES = ['holds', 'agreements', 'credit'];

    protected $signature = 'channels:run {mode : holds|agreements|credit} {--tenant= : solo este tenant (Ejecutar ahora)}';

    protected $description = 'Canales: expira holds de pedidos web, avisa vencimiento de convenios B2B y concilia el crédito B2B';

    public function handle(TenantContext $context): int
    {
        $mode = $this->argument('mode');
        if (! in_array($mode, self::MODES, true)) {
            $this->error('Modo inválido.');

            return self::INVALID;
        }
        foreach (OperatingTenants::ids($this->option('tenant'), null) as $tenantId) {
            $context->run($tenantId, fn () => $this->line("{$mode}: ".$this->{$mode}()));
        }

        return self::SUCCESS;
    }

    private function holds(): string
    {
        $done = app(EcomAdmin::class)->expireHolds();

        return count($done).' pedido(s) expirado(s)'.($done ? ': '.implode(', ', $done) : '');
    }

    private function agreements(): string
    {
        $today = app(CrmClock::class)->today();
        $sent = 0;
        foreach (B2bAgency::whereNotNull('valid_to')->where('status', '<>', 'inactiva')->whereDate('valid_to', '<=', $today->copy()->addDays(30)->toDateString())->get() as $agency) {
            $expired = $agency->valid_to->toDateString() < $today->toDateString();
            $ref = "{$agency->id}:{$agency->valid_to->toDateString()}:".($expired ? 'vencido' : 'por_vencer');
            if (NotifMessage::where('event', 'b2b.convenio')->where('data->ref', $ref)->exists()) {
                continue;
            }
            $subject = $expired ? "Convenio B2B vencido · {$agency->name}" : "Convenio B2B por vencer el {$agency->valid_to->format('d/m/Y')} · {$agency->name}";
            $body = $expired ? 'La agencia no puede reservar hasta renovar el convenio (Regla 14).' : 'Renueve la vigencia para que la agencia pueda seguir reservando.';
            $data = ['related_type' => 'b2b_agency', 'related_id' => $agency->id, 'client_id' => $agency->client_id, 'ref' => $ref];
            if ($email = $agency->booking_email) {
                app(Notify::class)->push('b2b.convenio', 'email', $email, $subject, $body, $data);
            }
            foreach (CatalogRecipients::managers() as $user) {
                app(Notify::class)->push('b2b.convenio', 'portal', $user, $subject, $body, $data);
            }
            $sent++;
        }

        return "{$sent} aviso(s) de convenio";
    }

    private function credit(): string
    {
        $diffs = app(B2bCredit::class)->verify();
        foreach ($diffs as $d) {
            app(AuditLogger::class)->log('Agencia B2B', $d['agency'], 'conciliar_credito', "credit_used {$d['credit_used']}", "libro {$d['ledger']}", system: true);
            foreach (CatalogRecipients::role('finance') as $user) {
                app(Notify::class)->push('b2b.credito_descuadre', 'portal', $user, "Descuadre de crédito B2B · {$d['agency']}", "Saldo {$d['credit_used']} vs. libro {$d['ledger']}.",
                    ['related_type' => 'b2b_agency']);
            }
        }

        return count($diffs).' descuadre(s)';
    }
}
