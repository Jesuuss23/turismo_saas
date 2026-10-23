<?php

use App\Services\SequenceService;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->tenant = $this->makeTenant();
});

it('asigna correlativos con el formato del prototipo (Seq.next)', function () {
    $numbers = app(TenantContext::class)->run($this->tenant, fn () => [
        app(SequenceService::class)->next('quote'),
        app(SequenceService::class)->next('quote'),
        app(SequenceService::class)->next('reservation'),
        app(SequenceService::class)->next('ops_order'),
        app(SequenceService::class)->next('voucher'),
        app(SequenceService::class)->next('invoice', 'F001'),
        app(SequenceService::class)->next('receipt', 'B001'),
    ]);

    $year = now()->year;
    expect($numbers)->toBe([
        "COT-{$year}-00192", "COT-{$year}-00193", "RES-{$year}-000350", "OP-{$year}-0415",
        "VOU-{$year}-01205", 'F001-00001484', 'B001-00008813',
    ]);
});

it('los correlativos son por tenant', function () {
    $other = $this->makeTenant('inka');
    $a = app(TenantContext::class)->run($this->tenant, fn () => app(SequenceService::class)->next('quote'));
    $b = app(TenantContext::class)->run($other, fn () => app(SequenceService::class)->next('quote'));

    expect($a)->toBe($b);
});

it('un rollback no consume número (sin huecos en documentos fiscales)', function () {
    app(TenantContext::class)->run($this->tenant, function () {
        try {
            DB::transaction(function () {
                app(SequenceService::class)->next('invoice', 'F001');
                throw new RuntimeException('falla al confirmar');
            });
        } catch (RuntimeException) {
        }

        expect(app(SequenceService::class)->next('invoice', 'F001'))->toBe('F001-00001484');
    });
});

it('SEC-BR-20: un correlativo nunca retrocede', function () {
    expect(fn () => $this->admin()->table('sys_sequences')->where('tenant_id', $this->tenant->id)->where('doc_type', 'invoice')->update(['next_number' => 10]))
        ->toThrow(QueryException::class, 'no puede retroceder');
});

it('X-15: 50 asignaciones concurrentes dan 50 números consecutivos sin duplicados', function () {
    $env = array_merge(getenv(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'turismo_test', 'DB_CONNECTION' => 'pgsql', 'CACHE_STORE' => 'array']);
    $procs = [];
    foreach (range(1, 50) as $i) {
        $procs[] = proc_open(
            [PHP_BINARY, base_path('artisan'), 'sequences:next', $this->tenant->id, 'reservation'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env,
        );
        $outputs[$i] = $pipes;
    }

    $numbers = [];
    foreach ($procs as $i => $proc) {
        $numbers[] = trim(stream_get_contents($outputs[$i + 1][1]));
        $errors = stream_get_contents($outputs[$i + 1][2]);
        expect(proc_close($proc))->toBe(0, $errors);
    }

    sort($numbers);
    $year = now()->year;
    $expected = array_map(fn ($n) => "RES-{$year}-".str_pad((string) $n, 6, '0', STR_PAD_LEFT), range(350, 399));

    expect($numbers)->toBe($expected);
});
