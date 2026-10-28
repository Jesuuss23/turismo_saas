<?php

namespace Database\Seeders;

use App\Domain\Bookings\RuleResolver;
use App\Models\Bookings\BookedService;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\Product;
use App\Models\Crm\Client;
use App\Models\Experience\OwnReview;
use App\Models\Experience\OwnReviewLog;
use App\Models\Experience\ReviewRequest;
use App\Models\Iam\User;
use App\Models\Sys\BookingRule;
use App\Services\Crm\CrmClock;
use App\Services\Experience\OwnReviewService;
use App\Services\SequenceService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Demo de la spec 18 para “andes”: R-OWN-REVIEWS activa (daysAfter 1, autopublicación desde 4★ con moderación), 12 reseñas y
 * 6 solicitudes rotuladas «ejemplo (demo)». Las reseñas de reservas que no existen en el sistema se muestran como «(histórica)».
 * El traslado de llegada ya realizado del viaje de John Miller (U15) queda ligado al producto PRD-009 para que pueda calificarlo.
 */
class OwnReviewDemoSeeder extends Seeder
{
    /** [producto, reserva (null = histórica), código histórico, cliente, autor, país, días atrás, ★, aspectos, título, texto, idioma, estado, consentimiento, respuesta, motivo]. */
    private const REVIEWS = [
        ['PRD-001', 'RES-2026-000341', null, 'CLI-0001', 'Ana Gutiérrez', 'Perú', 66, 5, ['guia' => 5, 'transporte' => 5, 'organizacion' => 5, 'valor' => 4], 'Machu Picchu soñado',
            'El guía Alberto nos explicó cada sector con paciencia y el tren salió puntual. Volveremos con toda la familia el próximo año.', 'es', 'publicada', true,
            '¡Gracias, Ana! Alberto estará feliz de leerlo. Les esperamos el próximo año.', null],
        ['PRD-002', 'RES-2026-000342', null, 'CLI-0005', 'María Fernández', 'Perú', 58, 4, ['guia' => 5, 'transporte' => 4, 'valor' => 4], 'Buen City Tour',
            'Muy completo el recorrido por Sacsayhuamán y Qenqo. El bus llegó diez minutos tarde al hotel, por lo demás todo muy bien.', 'es', 'publicada', true, null, null],
        ['PRD-012', 'RES-2026-000343', null, 'CLI-0012', 'Martín Rojas', 'Perú', 50, 5, ['guia' => 5, 'alojamiento' => 4, 'organizacion' => 5], 'Cusco Mágico superó lo esperado',
            'Cuatro días muy bien aprovechados: hoteles céntricos, traslados puntuales y un equipo atento por WhatsApp durante todo el viaje.', 'es', 'publicada', true, null, null],
        ['PRD-001', null, 'RES-2025-000212', 'CLI-0009', 'Thomas Keller', 'Alemania', 120, 5, ['guia' => 5, 'transporte' => 5, 'organizacion' => 5, 'valor' => 5], 'Unforgettable day at Machu Picchu',
            'Everything was perfectly organised, from the early train to the bus up to the citadel. Our guide spoke excellent English.', 'en', 'publicada', true,
            'Thank you, Thomas! We are delighted you enjoyed it and hope to see you again in Peru.', null],
        ['PRD-003', null, 'RES-2025-000218', 'CLI-0006', 'Luis Ramírez', 'Perú', 104, 4, ['guia' => 4, 'transporte' => 4, 'valor' => 4], 'Valle Sagrado VIP recomendable',
            'El almuerzo en Urubamba fue excelente y los grupos pequeños se agradecen. El recojo podría ser un poco más temprano para evitar el tráfico.', 'es', 'publicada', true, null, null],
        ['PRD-004', null, 'RES-2025-000231', 'CLI-0002', 'Rosa Quispe', 'Perú', 92, 3, ['guia' => 4, 'transporte' => 3, 'organizacion' => 3], 'Exigente pero bonito',
            'La montaña es espectacular, aunque el desayuno fue muy sencillo y el regreso se hizo largo. El guía estuvo pendiente del grupo en todo momento.', 'es', 'publicada', true, null, null],
        ['PRD-011', null, 'RES-2025-000240', 'CLI-0004', 'Claire Martin', 'Francia', 80, 5, ['guia' => 5, 'organizacion' => 5], 'Laguna Humantay magnifique',
            'Une journée inoubliable, le guide a pris soin de chaque personne pendant la montée. Le déjeuner au retour était délicieux.', 'fr', 'publicada', true, null, null],
        ['PRD-013', null, 'RES-2025-000255', 'CLI-0007', 'Patricia Gómez', 'Chile', 74, 2, ['alojamiento' => 2, 'organizacion' => 2, 'valor' => 3], 'Hotel de Puno no estuvo a la altura',
            'El circuito en general bien, pero el hotel de Puno no tenía agua caliente y nos cambiaron el horario del bus sin aviso. Esperaba más por el precio.', 'es', 'publicada', true,
            'Patricia, gracias por contarnos su experiencia. Ya cambiamos de hotel en Puno y reforzamos los avisos de cambios de horario.', null],
        ['PRD-002', null, 'RES-2025-000261', 'CLI-0011', 'Valeria Campos', 'Perú', 5, 5, ['guia' => 5, 'valor' => 5], 'Excelente guía',
            'Muy recomendable, el guía fue muy ameno y conocía muchísimo de la historia inca. Para reservar escríbanme a valeria.campos@correo.pe y les cuento.', 'es', 'pendiente', true, null, null],
        ['PRD-012', null, 'RES-2025-000266', 'CLI-0013', 'Sofía Herrera', 'Perú', 3, 4, ['organizacion' => 4], 'Muy buen viaje',
            'Todo bien organizado, solo faltó un poco más de tiempo libre en el centro de Cusco para recorrer por nuestra cuenta.', 'es', 'pendiente', true, null, null],
        ['PRD-003', null, 'RES-2025-000270', 'CLI-0008', 'Jorge Salas', 'Perú', 40, 1, ['transporte' => 1], 'Promoción de otra empresa',
            'Visiten la web de otra agencia que es mucho más barata, aquí solo cobran de más y no recomiendo nada de nada.', 'es', 'rechazada', true, null,
            'Contenido promocional de terceros, no describe la experiencia del viaje.'],
        ['PRD-004', null, 'RES-2025-000274', 'CLI-0004', 'Laura Méndez', 'Perú', 30, 4, ['guia' => 4, 'transporte' => 4], 'Bien en general',
            'La caminata fue dura pero el equipo llevaba oxígeno y estuvo atento. Prefiero que esta opinión quede solo para la agencia.', 'es', 'oculta', false, null,
            'Sin autorización de publicación (RVW-05).'],
    ];

    public function run(): void
    {
        $admin = User::where('code', 'U01')->firstOrFail();
        $rule = BookingRule::where('rule_key', OwnReviewService::RULE)->firstOrFail();
        $rule->forceFill(['enabled' => true])->save();
        app(RuleResolver::class)->bump();

        $now = app(CrmClock::class)->now();
        $seq = app(SequenceService::class);
        $service = app(OwnReviewService::class);
        $products = Product::whereIn('ref', array_unique(array_column(self::REVIEWS, 0)))->get()->keyBy('ref');
        $clients = Client::whereIn('code', array_unique(array_column(self::REVIEWS, 3)))->get()->keyBy('code');

        // Viaje de John Miller: el traslado de llegada (completado) es el producto PRD-009 «Traslado aeropuerto – hotel Cusco».
        $john = Reservation::where('code', 'RES-2026-000354')->first();
        $transfer = Product::where('ref', 'PRD-009')->first();
        if ($john && $transfer) {
            BookedService::where('reservation_id', $john->id)->where('status', BookedService::COMPLETED)->whereNull('product_id')
                ->where('name', 'like', 'Traslado aeropuerto%')->update(['product_id' => $transfer->id]);
        }

        $requests = [];
        foreach ([['RES-2026-000341', 'respondida', -60], ['RES-2026-000342', 'enviada', -3], ['RES-2026-000343', 'abierta', -2], ['RES-2026-000354', 'programada', null],
            ['RES-2026-000344', 'programada', null], ['RES-2026-000355', 'cancelada', null]] as [$code, $status, $sentAgo]) {
            $r = Reservation::where('code', $code)->first();
            $client = $r ? Client::find($r->client_id) : null;
            if (! $r || ! $client) {
                continue;
            }
            $at = Carbon::parse($r->travel_to->toDateString().' 09:00:00', app(CrmClock::class)->timezone())->addDay();
            $sent = $sentAgo !== null ? $now->copy()->addDays($sentAgo)->setTime(9, 0) : null;
            $requests[$code] = ReviewRequest::create([
                'code' => $seq->next('review_request'), 'reservation_id' => $r->id, 'client_id' => $r->client_id, 'to_address' => $client->email ?? 'pasajero@correo.test',
                'channel' => 'email', 'lang' => $client->country === 'Estados Unidos' ? 'en' : 'es', 'scheduled_at' => $sent ?? $at, 'sent_at' => $sent,
                'opened_at' => in_array($status, ['abierta', 'respondida'], true) ? $sent?->copy()->addHours(5) : null,
                'expires_at' => ($sent ?? $at)->copy()->addDays(14), 'status' => $status, 'is_demo' => true,
                'token_hash' => in_array($status, ['enviada', 'abierta'], true) ? hash('sha256', "demo-{$code}") : null,
            ]);
        }

        foreach (self::REVIEWS as [$ref, $resCode, $histCode, $cliCode, $author, $country, $ago, $stars, $subs, $title, $body, $lang, $status, $consent, $reply, $reason]) {
            $product = $products[$ref] ?? null;
            $client = $clients[$cliCode] ?? null;
            if (! $product || ! $client) {
                continue;
            }
            $r = $resCode ? Reservation::where('code', $resCode)->first() : null;
            $created = $now->copy()->subDays($ago)->setTime(10, 15);
            $flags = $service->flags("{$title}\n{$body}");
            $review = OwnReview::create([
                'code' => $seq->next('own_review'), 'reservation_id' => $r?->id, 'reservation_code' => $r?->code ?? $histCode, 'product_id' => $product->id, 'client_id' => $client->id,
                'author_display' => OwnReviewService::display($author), 'author_name' => $author, 'country' => $country, 'travel_date' => $created->copy()->subDays(2)->toDateString(),
                'stars' => $stars, 'subratings' => $subs, 'title' => $title, 'body' => $body, 'lang' => $lang, 'verified' => true, 'consent_publish' => $consent,
                'status' => $status, 'flags' => $status === 'pendiente' ? $flags : [], 'moderation_reason' => $reason ?? ($status === 'pendiente' && $flags ? implode('; ', $flags) : null),
                'reply_text' => $reply, 'reply_by' => $reply ? $admin->id : null, 'replied_at' => $reply ? $created->copy()->addDays(1) : null,
                'helpful_count' => $status === 'publicada' ? ($stars * 3 + $ago) % 11 : 0, 'source' => $r ? 'email' : 'portal',
                'request_id' => $r ? ($requests[$r->code] ?? null)?->id : null, 'is_demo' => true,
                'published_at' => $status === 'publicada' ? $created->copy()->addHours(6) : null, 'moderated_by' => in_array($status, ['publicada', 'rechazada'], true) ? $admin->id : null,
            ]);
            $review->forceFill(['created_at' => $created, 'updated_at' => $created])->saveQuietly();
            $log = [['crear', $flags && $status === 'pendiente' ? 'Retenida: '.implode('; ', $flags) : null, null, $created]];
            if ($status === 'publicada') {
                $log[] = $stars >= 4 ? ['autopublicar', '≥ 4 estrellas, sin alertas y con consentimiento', null, $created->copy()->addHours(6)] : ['publicar', null, $admin->id, $created->copy()->addHours(6)];
            }
            if ($status === 'rechazada') {
                $log[] = ['rechazar', $reason, $admin->id, $created->copy()->addHours(3)];
            }
            if ($reply) {
                $log[] = ['responder', null, $admin->id, $created->copy()->addDays(1)];
            }
            foreach ($log as [$action, $note, $userId, $at]) {
                OwnReviewLog::create(['review_id' => $review->id, 'action' => $action, 'note' => $note, 'user_id' => $userId, 'created_at' => $at]);
            }
        }
        $service->invalidate();
    }
}
