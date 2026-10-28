<?php

namespace App\Services\Site;

use App\Tenancy\TenantContext;
use Closure;
use WeakMap;

/**
 * Memo por solicitud y tenant: las lecturas repetidas del catálogo (publicados, salidas, traducciones) se calculan una vez por request,
 * sin quedar vivas entre solicitudes (el controlador queda cacheado en la ruta) ni cruzar tenants.
 */
trait RequestMemo
{
    private ?WeakMap $requestMemo = null;

    protected function memo(string $key, Closure $fn): mixed
    {
        $this->requestMemo ??= new WeakMap;
        $request = request();
        $bag = $this->requestMemo[$request] ?? [];
        $key = app(TenantContext::class)->id().':'.$key;
        if (! array_key_exists($key, $bag)) {
            $bag[$key] = $fn();
            $this->requestMemo[$request] = $bag;
        }

        return $bag[$key];
    }
}
