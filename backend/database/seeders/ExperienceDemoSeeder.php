<?php

namespace Database\Seeders;

use App\Models\Experience\Currency;
use App\Models\Experience\WidgetDomain;
use App\Models\Finance\FxRate;
use App\Models\Iam\User;
use App\Services\Crm\CrmClock;
use App\Services\Experience\PaxMessageService;
use App\Services\Experience\ReviewService;
use App\Services\Experience\WidgetService;
use Illuminate\Database\Seeder;

/**
 * Demo de la spec 17 para “andes”: 30 días de historial de TC de demostración por moneda activa (variación determinista ±0,6 %),
 * Google y TripAdvisor conectados con reseñas de ejemplo, un dominio verificado para el widget y la programación de mensajes al
 * pasajero de las reservas vigentes.
 */
class ExperienceDemoSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('code', 'U01')->firstOrFail();
        $today = app(CrmClock::class)->today();

        foreach (Currency::where('active', true)->where('is_base', false)->get() as $c) {
            $code = trim($c->code);
            $rate = (float) $c->rate_pen;
            $series = [];
            // Hacia atrás desde el TC vigente: cada día difiere del siguiente entre −0,6 % y +0,6 %.
            for ($ago = 0; $ago <= 30; $ago++) {
                $series[$ago] = round($rate, 6);
                $rate /= 1 + ((crc32("{$code}-{$ago}") % 1201) - 600) / 100000;
            }
            for ($ago = 30; $ago >= 0; $ago--) {
                $date = $today->copy()->subDays($ago)->toDateString();
                if (FxRate::where('currency', $code)->whereDate('rate_date', $date)->exists()) {
                    continue;
                }
                $prev = $series[$ago + 1] ?? null;
                $change = $prev ? ($series[$ago] - $prev) / $prev * 100 : null;
                FxRate::create(['rate_date' => $date, 'currency' => $code, 'buy' => (string) $series[$ago], 'sell' => (string) $series[$ago], 'source' => 'Simulador de demostración',
                    'previous_rate' => $prev, 'daily_change_pct' => $change !== null ? round($change, 3) : null, 'alert_raised' => false, 'is_demo' => true, 'effective_at' => $today->copy()->subDays($ago)]);
            }
        }

        $reviews = app(ReviewService::class);
        $reviews->connect('google', ['account_ref' => 'Andes Travel · Cusco (Google Business Profile)', 'secret' => 'demo-google-oauth-7d6c', 'contract_ack' => true], $admin);
        $reviews->connect('tripadvisor', ['account_ref' => 'Andes Travel (location 1234567)', 'secret' => 'demo-tripadvisor-key-41ab', 'contract_ack' => true], $admin);

        $widget = app(WidgetService::class);
        $widget->addDomain(['domain' => 'www.andestravel.pe', 'cms' => 'WordPress'], $admin);
        $widget->verifyDomain(WidgetDomain::where('domain', 'www.andestravel.pe')->firstOrFail(), $admin);

        app(PaxMessageService::class)->schedule();
    }
}
