<?php

namespace App\Support\Concerns;

use App\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * SEC-BR-17: recursos con `lock_version` exigen If-Match; si la versión no coincide → 409 STALE_VERSION.
 * Además el UPDATE es condicional (WHERE lock_version = esperado), así dos escrituras
 * concurrentes con la misma versión nunca pasan las dos.
 */
trait OptimisticLock
{
    /** Valida el header If-Match contra la versión actual (llamar en el controlador antes de modificar). */
    public function assertIfMatch(Request $request): static
    {
        $header = $request->header('If-Match');
        if ($header === null || $header === '') {
            throw new ApiException(428, 'PRECONDITION_REQUIRED', 'SEC-BR-17', 'Este recurso exige el header If-Match con su lock_version');
        }

        $version = (int) trim(preg_replace('/^W\//', '', $header), '"');
        if ($version !== (int) $this->lock_version) {
            throw self::staleVersion();
        }

        return $this;
    }

    protected function performUpdate(Builder $query)
    {
        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        $dirty = $this->getDirtyForUpdate();
        if (count($dirty) > 0) {
            $expected = (int) $this->getOriginal('lock_version');
            $dirty['lock_version'] = $expected + 1;

            $affected = $this->setKeysForSaveQuery($query)->where('lock_version', $expected)->update($dirty);
            if ($affected === 0) {
                throw self::staleVersion();
            }

            $this->setAttribute('lock_version', $expected + 1);
            $this->syncChanges();
            $this->fireModelEvent('updated', false);
        }

        return true;
    }

    private static function staleVersion(): ApiException
    {
        return ApiException::conflict('STALE_VERSION', 'El registro fue modificado por otro usuario; recargue e intente de nuevo');
    }
}
