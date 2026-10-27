<?php

namespace Database\Seeders;

use App\Models\Bookings\Reservation;
use App\Models\Catalog\Provider;
use App\Models\Finance\BankAccount;
use App\Models\Finance\BankLine;
use App\Models\Finance\Commission;
use App\Models\Finance\FxRate;
use App\Models\Finance\Payable;
use App\Models\Finance\Payment;
use App\Models\Finance\Payout;
use App\Models\Finance\Receivable;
use App\Models\Iam\User;
use App\Services\Crm\CrmClock;
use App\Services\Finance\CommissionService;
use App\Services\Finance\Fin;
use App\Services\Finance\FinanceProvisioner;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\PayableManager;
use App\Services\Finance\PaymentService;
use App\Services\SequenceService;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Demo de Finanzas y Contabilidad (spec 07): cuentas bancarias y TC, cobros con y sin comprobante, un pago web por validar con su
 * abono en el extracto, un pago rechazado, una boleta de exportación observada por el OSE simulado, comisiones en distintos estados,
 * un costo adicional por aprobar y el payout de la liquidación ya pagada al hotel.
 */
class FinanceDemoSeeder extends Seeder
{
    /** @var array<string, User> */
    private array $u = [];

    public function run(): void
    {
        $tenant = app(TenantContext::class)->tenant();
        FinanceProvisioner::seedTenant($tenant->id, DB::connection(), $tenant->settings ?? []);
        $this->u = User::whereIn('code', ['U01', 'U07', 'U08'])->get()->keyBy('code')->all();
        $today = app(CrmClock::class)->today();

        $pen = BankAccount::create(['bank' => 'BCP', 'account_type' => 'cta_cte', 'currency' => 'PEN', 'number' => '193-2245871-0-45', 'cci' => '00219300224587104512',
            'label' => 'BCP Cta. Cte. PEN 193-2245871', 'active' => true]);
        BankAccount::create(['bank' => 'BCP', 'account_type' => 'cta_cte', 'currency' => 'USD', 'number' => '193-2245903-1-12', 'cci' => '00219300224590311218',
            'label' => 'BCP Cta. Cte. USD 193-2245903', 'active' => true]);
        BankAccount::create(['bank' => 'Interbank', 'account_type' => 'cta_cte', 'currency' => 'PEN', 'number' => '200-3001456789', 'label' => 'Interbank Cta. Cte. PEN 200-3001456',
            'active' => true]);
        foreach ([[1, '3.732000', '3.748000'], [0, '3.735000', '3.750000']] as [$ago, $buy, $sell]) {
            FxRate::create(['rate_date' => $today->copy()->subDays($ago)->toDateString(), 'currency' => 'USD', 'buy' => $buy, 'sell' => $sell, 'source' => 'SBS']);
        }

        $payments = app(PaymentService::class);
        $invoices = app(InvoiceService::class);
        $cxc = fn (string $code) => Receivable::where('code', $code)->firstOrFail();

        $this->as('U07', function () use ($payments, $invoices, $cxc) {
            // Cobro facturado (boleta aceptada y entregada) del anticipo de Ana Gutiérrez.
            $this->invoiceFor($invoices, $cxc('CXC-00715'), deliver: true);
            // Anticipo de la familia Fernández cobrado sin comprobante: lo verá Contabilidad (FIN-E2E-B).
            $payments->register($cxc('CXC-00732'), ['method' => 'Transferencia BCP', 'amount' => '119.70', 'reference' => 'OP-778812'], $this->u['U07']);
            // Anticipo de John Miller con tarjeta: boleta de exportación que el OSE simulado observa.
            $payments->register($cxc('CXC-00724'), ['method' => 'Tarjeta', 'amount' => '79.80', 'reference' => 'VISA-4417'], $this->u['U07']);
            $this->invoiceFor($invoices, $cxc('CXC-00724'), domiciled: false);
        });

        // Pagos del portal por validar: uno con abono en el extracto (FIN-E2E-A) y uno rechazado por voucher ilegible.
        $sequences = app(SequenceService::class);
        $web = $this->pendingPayment($sequences, $cxc('CXC-00731'), 'Yape', '88123456', $today->toDateString());
        $rejected = $this->pendingPayment($sequences, $cxc('CXC-00726'), 'Plin', '55002211', $today->copy()->subDay()->toDateString());
        $this->as('U07', fn () => $payments->reject($rejected, 'Voucher ilegible: el cliente debe reenviar la constancia.', $this->u['U07']));
        BankLine::create(['code' => $sequences->next('bank_line'), 'bank' => 'BCP', 'value_date' => $today->toDateString(), 'description' => 'ABONO YAPE 88123456 GUTIERREZ ANA',
            'amount' => $web->amount, 'currency' => 'PEN', 'matched_payment_id' => $web->id, 'status' => 'sugerido']);

        // Payout de la liquidación ya pagada al hotel (spec 06 la creó pagada sin el detalle del pago).
        foreach (Payable::where('status', Payable::PAID)->doesntHave('payouts')->get() as $p) {
            Payout::create(['code' => $sequences->next('payout'), 'payable_id' => $p->id, 'paid_on' => $p->paid_at?->toDateString() ?? $today->toDateString(),
                'amount' => $p->amount, 'fx_rate' => $p->currency === 'PEN' ? null : $p->fx_rate, 'method' => 'transferencia',
                'bank_account_id' => BankAccount::where('currency', $p->currency)->value('id'), 'operation_ref' => 'OP-114455', 'paid_by' => $this->u['U07']->id]);
        }

        $this->as('U07', function () {
            // Comisiones: calculadas para todas las reservas vigentes; una aprobada y otra pagada.
            $commissions = app(CommissionService::class);
            $commissions->calculatePending($this->u['U07']);
            $byRes = fn (string $code) => Commission::whereHas('reservation', fn ($q) => $q->where('code', $code))->firstOrFail();
            $commissions->approve($byRes('RES-2026-000350'), $this->u['U01']);
            $paid = $commissions->approve($byRes('RES-2026-000356'), $this->u['U01']);
            $commissions->pay($paid, ['method' => 'Planilla del mes', 'reference' => 'PLANILLA-2026-10'], $this->u['U07']);

            // Costo adicional detectado en operación, pendiente de aprobación.
            $res = Reservation::where('code', 'RES-2026-000354')->firstOrFail();
            app(PayableManager::class)->registerCost(['reservation_id' => $res->id, 'provider_id' => Provider::where('ref', 'PRV-003')->value('id'), 'cost_type' => 'gasto_operativo_destino',
                'concept' => 'Ingreso adicional al Qorikancha (2 pax)', 'amount' => 60, 'evidence_note' => 'Ticket de boletería N° 004512'], $this->u['U07']);
        });
    }

    private function invoiceFor(InvoiceService $invoices, Receivable $r, bool $domiciled = true, bool $deliver = false): void
    {
        $draft = $invoices->draft($r);
        $invoice = $invoices->issue(['receivable_id' => $r->id, 'domiciled' => $domiciled, 'amount' => $r->paid_amount] + $draft, $this->u['U07']);
        if ($deliver && $invoice->refresh()->status === 'aceptado') {
            $invoices->deliver($invoice, $this->u['U07']);
        }
    }

    private function pendingPayment(SequenceService $sequences, Receivable $r, string $method, string $reference, string $date): Payment
    {
        $payment = Payment::create(['code' => $sequences->next('payment'), 'receivable_id' => $r->id, 'reservation_id' => $r->reservation_id, 'client_id' => $r->client_id,
            'kind' => 'cobro', 'source' => 'portal_cliente', 'method' => $method, 'amount' => $r->balance(), 'currency' => $r->currency, 'paid_on' => $date,
            'reference' => $reference, 'status' => 'pendiente', 'note' => 'Constancia subida por el cliente en el portal']);
        Fin::history('payment', $payment->id, 'pendiente', 'Pago reportado desde el portal del cliente', null);

        return $payment;
    }

    private function as(string $code, callable $fn): mixed
    {
        Auth::setUser($this->u[$code]);

        return $fn();
    }
}
