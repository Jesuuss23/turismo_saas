<?php

namespace App\Http\Controllers\Api\Finance;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Bookings\Reservation;
use App\Models\Iam\User;
use App\Services\Finance\FinancePresenter;
use Illuminate\Http\Request;

/** Base de los controladores de Finanzas (spec 07 §6): alcance de todo el tenant (§7), presentador y búsqueda por código. */
abstract class FinanceController extends Controller
{
    public function __construct(protected readonly FinancePresenter $present) {}

    protected function actor(Request $request): User
    {
        return $request->user();
    }

    protected function reservation(string $codeOrId): Reservation
    {
        return Reservation::where('code', $codeOrId)->orWhere(fn ($q) => preg_match('/^[0-9a-f-]{36}$/i', $codeOrId) ? $q->where('id', $codeOrId) : $q->whereRaw('false'))->first()
            ?? throw ApiException::notFound();
    }

    /** @template T of \Illuminate\Database\Eloquent\Model @param class-string<T> $model @return T */
    protected function byCode(string $model, string $code, string $column = 'code')
    {
        return $model::where($column, $code)->orWhere(fn ($q) => preg_match('/^[0-9a-f-]{36}$/i', $code) ? $q->where('id', $code) : $q->whereRaw('false'))->first()
            ?? throw ApiException::notFound();
    }

    protected function can(Request $request, string $permission): bool
    {
        return $this->actor($request)->hasPermissionTo($permission);
    }
}
