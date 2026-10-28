<?php

namespace App\Services\Experience;

use App\Domain\Bookings\RuleCatalog;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * Spec 17: semillas por tenant (alta y migración): correlativos, 10 monedas con TC de demostración, proveedores de TC, ajustes de
 * e-commerce, 10 plantillas de mensajes al pasajero, 6 conectores de reseñas sin conectar y la configuración del widget con su clave pública.
 */
class ExperienceProvisioner
{
    public static function seedTenant(string $tenantId, ConnectionInterface $db): void
    {
        $now = now();
        $row = fn (array $data) => ['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'created_at' => $now, 'updated_at' => $now] + $data;
        foreach (ExperienceCatalog::SEQUENCES as $docType => [$label, $prefix, $pad, $next]) {
            $db->table('sys_sequences')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'doc_type' => $docType, 'label' => $label, 'series' => 'default',
                'prefix' => $prefix, 'pad_width' => $pad, 'next_number' => $next, 'year_reset' => false, 'updated_at' => $now,
            ]);
        }

        foreach (RuleCatalog::DEPENDS_ON as $child => $parent) {
            $db->table('sys_booking_rules')->where('tenant_id', $tenantId)->where('rule_key', $child)->update(['depends_on' => $parent]);
        }

        if (! $db->table('fin_currencies')->where('tenant_id', $tenantId)->exists()) {
            $usd = (float) (json_decode((string) $db->table('tenants')->where('id', $tenantId)->value('settings'), true)['fx']['USD'] ?? 0);
            foreach (ExperienceCatalog::CURRENCIES as $code => [$name, $symbol, $rate, $active, $charge]) {
                $db->table('fin_currencies')->insert($row(['code' => $code, 'name' => $name, 'symbol' => $symbol, 'rate_pen' => $code === 'USD' && $usd > 0 ? $usd : $rate,
                    'active' => $active, 'is_base' => $code === 'PEN', 'is_charge' => $charge]));
            }
        }
        $providers = [];
        foreach (ExperienceCatalog::FX_PROVIDERS as $code => [$name, $kind, $status, $cron, $note]) {
            $id = $db->table('fx_rate_providers')->where('tenant_id', $tenantId)->where('code', $code)->value('id');
            if (! $id) {
                $data = $row(['code' => $code, 'name' => $name, 'kind' => $kind, 'status' => $status, 'schedule_cron' => $cron ?: null, 'note' => $note]);
                $db->table('fx_rate_providers')->insert($data);
                $id = $data['id'];
            }
            $providers[$code] = $id;
        }
        $db->table('sys_settings')->insertOrIgnore(['tenant_id' => $tenantId, 'section' => 'ecommerce', 'values' => json_encode([
            'rate_provider_id' => $providers['SIM'], 'fx_alert_pct' => 3, 'is_demo_rates' => true, 'rate_source' => 'Simulador de demostración',
            'rate_updated_at' => null, 'rule_template_applied' => null,
        ]), 'updated_at' => $now]);

        foreach (ExperienceCatalog::PAX_TEMPLATES as $code => [$name, $event, $offset, $fromRule, $channels, $subject, $body, $lang, $group, $conditions]) {
            $db->table('notif_pax_message_templates')->insertOrIgnore($row([
                'code' => $code, 'name' => $name, 'event' => $event, 'offset_hours' => $offset, 'offset_from_rule' => $fromRule, 'channels' => json_encode($channels),
                'subject' => $subject, 'body' => $body, 'lang' => $lang, 'group_code' => $group, 'conditions' => json_encode((object) $conditions), 'active' => true,
            ]));
        }
        foreach (ExperienceCatalog::REVIEW_PROVIDERS as $provider => [, , $reply, $invites, $scopes]) {
            $db->table('integration_review_sources')->insertOrIgnore($row([
                'provider' => $provider, 'status' => 'no_conectado', 'scopes' => json_encode($scopes), 'can_reply' => $reply, 'supports_invites' => $invites,
            ]));
        }
        $slug = $db->table('tenants')->where('id', $tenantId)->value('slug');
        $db->table('channel_widget_config')->insertOrIgnore($row([
            'tenant_slug' => $slug, 'public_key' => 'pk_live_'.Str::lower(Str::random(28)),
            'defaults' => json_encode(['method' => 'webcomponent', 'lang' => 'es', 'currency' => 'USD', 'color' => '#0369a1', 'radius' => 8, 'reviews' => true]),
            'reviews_widget' => json_encode(['sources' => ['google', 'tripadvisor', 'trustpilot'], 'min_rating' => 4, 'count' => 6, 'layout' => 'carrusel']),
        ]));
    }
}
