<?php

namespace App\Services\Experience;

use App\Models\Iam\User;
use Illuminate\Support\Collection;

/** Exportación CSV de «Todas las reseñas» (spec 18 §2) con el alcance del rol (Ventas: su cartera). */
class OwnReviewExporter
{
    public const RESOURCES = ['reviews.own' => ['channels.ecommerce.manage', 'crm.clients.manage']];

    public function __construct(private readonly OwnReviewService $reviews) {}

    /** @return array{0: list<string>, 1: Collection<int, list<mixed>>} */
    public function rows(string $resource, User $user, array $filters = []): array
    {
        $rows = collect($this->reviews->list(array_filter($filters, fn ($v) => $v !== null && $v !== ''), $user));

        return [
            ['Código', 'Estado', 'Estrellas', 'Título', 'Reseña', 'Autor', 'País', 'Producto', 'Reserva', 'Cliente', 'Fecha de viaje', 'Idioma', 'Consentimiento', 'Alertas', 'Respuesta', 'Publicada', 'Demo'],
            $rows->map(fn (array $r) => [
                $r['code'], $r['status_label'], $r['stars'], $r['title'], $r['body'], $r['author_name'], $r['country'], $r['product'] ? "{$r['product']['ref']} · {$r['product']['name']}" : '',
                $r['reservation'].($r['historic'] ? ' (histórica)' : ''), $r['client']['name'] ?? '', $r['travel_date'], $r['lang'], $r['consent'] ? 'sí' : 'no', implode('; ', $r['flags']),
                $r['reply'] ?? '', $r['published_at'] ? substr($r['published_at'], 0, 10) : '', $r['is_demo'] ? 'ejemplo (demo)' : '',
            ]),
        ];
    }
}
