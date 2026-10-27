<?php

namespace Tests\Concerns;

use App\Models\Catalog\Provider;
use App\Models\Finance\BankAccount;
use App\Models\Finance\Payable;
use App\Models\Finance\Payment;
use App\Services\Catalog\ProviderService;
use App\Services\SequenceService;
use Illuminate\Testing\TestResponse;

/**
 * Escenario de Finanzas sobre ReservationWorld: una reserva confirmada en USD (896.80 = CxC 358.72 + 538.08) del cliente de u03,
 * cuentas bancarias PEN/USD y un proveedor activo para CxP y costos.
 */
trait FinanceWorld
{
    /** @var array<string, mixed> */
    protected array $fres = [];

    protected Provider $vendor;

    /** @var array<string, BankAccount> */
    protected array $accounts = [];

    public function financeWorld(): void
    {
        $this->reservationWorld();
        $this->fres = $this->makeReservation('u03');
        $this->inTenant(function () {
            $this->accounts['PEN'] = BankAccount::create(['bank' => 'BCP', 'account_type' => 'cta_cte', 'currency' => 'PEN', 'number' => '193-1', 'label' => 'BCP PEN', 'active' => true]);
            $this->accounts['USD'] = BankAccount::create(['bank' => 'BCP', 'account_type' => 'cta_cte', 'currency' => 'USD', 'number' => '193-2', 'label' => 'BCP USD', 'active' => true]);
            $providers = app(ProviderService::class);
            $this->vendor = $providers->create(['name' => 'Transportes Inca', 'type' => 'Transporte', 'ruc' => '20100000077', 'contact_name' => 'Ventas', 'currency' => 'USD',
                'email' => 'pagos@transinca.test', 'contract_start' => now()->subYear()->toDateString(), 'contract_end' => now()->addYear()->toDateString()], $this->users['admin']);
            $providers->activate($this->vendor, $this->users['admin']);
        });
    }

    public function fGet(string $who, string $uri): TestResponse
    {
        return $this->qGet($who, 'finance/'.ltrim($uri, '/'));
    }

    public function fPost(string $who, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->qPost($who, 'finance/'.ltrim($uri, '/'), $data, $headers + $this->idem());
    }

    /** id y código de la CxC N (0 = anticipo, 1 = saldo) de la reserva del escenario. */
    public function cxc(int $i = 0): object
    {
        return $this->fresh('fin_receivables', $this->fres['receivables'][$i]['id']);
    }

    public function pay(int $i, string $amount, string $method = 'Efectivo', array $extra = [], string $who = 'finance'): TestResponse
    {
        return $this->fPost($who, 'payments', ['receivable_id' => $this->cxc($i)->id, 'amount' => $amount, 'method' => $method] + $extra);
    }

    /** Pago reportado por el portal (pendiente de validación). */
    public function pendingPayment(int $i, string $amount, string $reference = '7788991'): object
    {
        return $this->inTenant(function () use ($i, $amount, $reference) {
            $r = $this->cxc($i);

            return Payment::create(['code' => app(SequenceService::class)->next('payment'), 'receivable_id' => $r->id, 'reservation_id' => $r->reservation_id,
                'client_id' => $r->client_id, 'kind' => 'cobro', 'source' => 'portal_cliente', 'method' => 'Yape', 'amount' => $amount, 'currency' => $r->currency,
                'fx_rate' => $r->fx_rate, 'paid_on' => now()->toDateString(), 'reference' => $reference, 'status' => 'pendiente']);
        });
    }

    /** CxP de servicio del proveedor del escenario sobre la reserva. */
    public function makePayable(string $amount = '200.00', array $overrides = []): object
    {
        return $this->inTenant(fn () => Payable::create($overrides + ['code' => app(SequenceService::class)->next('payable'), 'provider_id' => $this->vendor->id,
            'reservation_id' => $this->fres['id'], 'kind' => 'servicio', 'concept' => 'Traslados del grupo', 'amount' => $amount, 'currency' => 'USD', 'fx_rate' => '3.750000',
            'due_date' => now()->addDays(10)->toDateString(), 'status' => 'abierta']));
    }

    /** Payload de emisión desde el prellenado de la CxC. */
    public function invoicePayload(int $i = 0, array $overrides = []): array
    {
        $draft = $this->fGet('finance', "receivables/{$this->cxc($i)->code}/invoice-draft")->assertOk()->json('data');

        return $overrides + ['receivable_id' => $this->cxc($i)->id] + $draft;
    }
}
