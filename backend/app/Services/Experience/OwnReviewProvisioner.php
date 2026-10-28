<?php

namespace App\Services\Experience;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/** Spec 18: correlativos RVO-/SRV- y ajustes `own_reviews` por tenant (alta y migración). */
class OwnReviewProvisioner
{
    public const SEQUENCES = [
        'own_review' => ['Reseña propia', 'RVO-', 4, 1],
        'review_request' => ['Solicitud de reseña', 'SRV-', 4, 1],
    ];

    public const SUBRATINGS = ['guia' => 'Guía', 'transporte' => 'Transporte', 'alojamiento' => 'Alojamiento', 'organizacion' => 'Organización', 'valor' => 'Relación calidad-precio'];

    /** §3.4 (valores de demostración, editables). */
    public static function defaults(): array
    {
        return [
            'auto_publish' => false, 'banned_words' => ['estafa', 'estafadores', 'idiota', 'basura'], 'personal_data' => 'retener',
            'active_subratings' => array_keys(self::SUBRATINGS), 'show_on_site' => true, 'show_on_widget' => true,
            'invite' => ['channel' => 'email', 'reminder_days' => 3, 'expire_days' => 14, 'subject' => '¿Cómo estuvo su viaje, {nombre}?',
                'body' => "Hola {nombre}:\n\nGracias por viajar con {agencia}. Nos ayudaría mucho conocer su opinión sobre {producto}. Calificar le toma 2 minutos:\n{enlace}\n\nSu reseña se publica tal cual la escriba (puede ser positiva o negativa) y no hay premios ni descuentos a cambio."],
            'last_export' => null, 'is_demo' => true,
        ];
    }

    public static function seedTenant(string $tenantId, ConnectionInterface $db): void
    {
        $now = now();
        foreach (self::SEQUENCES as $docType => [$label, $prefix, $pad, $next]) {
            $db->table('sys_sequences')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'doc_type' => $docType, 'label' => $label, 'series' => 'default',
                'prefix' => $prefix, 'pad_width' => $pad, 'next_number' => $next, 'year_reset' => false, 'updated_at' => $now,
            ]);
        }
        $db->table('sys_settings')->insertOrIgnore(['tenant_id' => $tenantId, 'section' => 'own_reviews', 'values' => json_encode(self::defaults()), 'updated_at' => $now]);
    }
}
