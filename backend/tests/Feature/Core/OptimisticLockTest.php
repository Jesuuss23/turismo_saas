<?php

use App\Exceptions\ApiException;
use App\Support\Concerns\OptimisticLock;
use App\Support\Database\Rls;
use App\Tenancy\BelongsToTenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LockedThing extends Model
{
    use BelongsToTenant, HasUuids, OptimisticLock;

    protected $table = 'test_locked_things';

    protected $guarded = ['id', 'tenant_id'];
}

beforeEach(function () {
    DB::connection('pgsql_admin')->statement('CREATE TABLE IF NOT EXISTS test_locked_things (id uuid PRIMARY KEY, tenant_id uuid NOT NULL REFERENCES tenants(id), name text, lock_version int NOT NULL DEFAULT 1, created_at timestamptz, updated_at timestamptz)');
    if (! DB::connection('pgsql_admin')->selectOne("SELECT 1 AS x FROM pg_policies WHERE tablename = 'test_locked_things'")) {
        DB::setDefaultConnection('pgsql_admin');
        Rls::tenantTable('test_locked_things');
        DB::setDefaultConnection('pgsql');
    }
    $this->tenant = $this->makeTenant();
});

it('X-14: dos actualizaciones con el mismo lock_version → la segunda 409 STALE_VERSION', function () {
    app(TenantContext::class)->run($this->tenant, function () {
        $created = LockedThing::create(['name' => 'Tour Machu Picchu']);
        $first = LockedThing::find($created->id);
        $second = LockedThing::find($created->id);

        $first->update(['name' => 'Tour Machu Picchu 2D']);
        expect($first->lock_version)->toBe(2);

        try {
            $second->update(['name' => 'Otro nombre']);
            $this->fail('Debió lanzar STALE_VERSION');
        } catch (ApiException $e) {
            expect($e->status)->toBe(409)->and($e->error)->toBe('STALE_VERSION');
        }

        expect(LockedThing::find($created->id)->name)->toBe('Tour Machu Picchu 2D');
    });
});

it('If-Match obligatorio y comparado con lock_version', function () {
    app(TenantContext::class)->run($this->tenant, function () {
        $thing = LockedThing::create(['name' => 'x'])->fresh();

        $patch = function (?string $ifMatch) {
            $request = Request::create('/', 'PATCH');
            $ifMatch === null || $request->headers->set('If-Match', $ifMatch);

            return $request;
        };

        expect(fn () => $thing->assertIfMatch($patch(null)))->toThrow(ApiException::class, 'If-Match');
        expect(fn () => $thing->assertIfMatch($patch('"7"')))->toThrow(ApiException::class, 'modificado por otro usuario');
        expect($thing->assertIfMatch($patch('W/"1"')))->toBe($thing);
    });
});
