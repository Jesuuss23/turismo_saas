<?php

use App\Domain\Bookings\RuleCatalog;
use App\Services\Experience\OwnReviewProvisioner;
use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['crm_review_requests', 'crm_own_reviews', 'crm_own_review_log'];

    private const SECTIONS = "'general','currency','policies','operations','channels','notifications','travel_docs','ecommerce'";

    // Spec 18 §3: reseñas propias verificadas (R-OWN-REVIEWS), su bitácora de moderación y las solicitudes de reseña postviaje.
    public function up(): void
    {
        $base = function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
        };
        $conventions = function (Blueprint $table) {
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->timestampTz('deleted_at')->nullable();
            $table->integer('lock_version')->default(0);
        };
        $checks = function (string $table, array $checks) {
            foreach ($checks as $name => $check) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$name} CHECK ({$check})");
            }
        };

        // §3.3 solicitudes: enlace firmado de un solo uso (solo se guarda el hash).
        Schema::create('crm_review_requests', function (Blueprint $table) use ($base, $conventions) {
            $base($table);
            $table->string('code', 30);
            $table->foreignUuid('reservation_id')->constrained('booking_reservations');
            $table->foreignUuid('client_id')->constrained('crm_clients');
            $table->string('to_address', 160);
            $table->string('channel', 10)->default('email');
            $table->char('lang', 2)->default('es');
            $table->char('token_hash', 64)->nullable();
            $table->timestampTz('scheduled_at');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('opened_at')->nullable();
            $table->timestampTz('reminded_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->string('status', 12)->default('programada');
            $table->boolean('is_demo')->default(false);
            $conventions($table);
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'reservation_id']);
            $table->index(['tenant_id', 'status', 'scheduled_at']);
        });
        $checks('crm_review_requests', [
            'status_check' => "status IN ('programada','enviada','abierta','respondida','vencida','cancelada')",
            'channel_check' => "channel IN ('email','whatsapp')",
            'lang_check' => "lang IN ('es','en','de','fr','pt')",
        ]);

        // §3.1 reseñas. Las semillas «(histórica)» de la demo no tienen reserva en el sistema: llevan solo el código mostrado.
        Schema::create('crm_own_reviews', function (Blueprint $table) use ($base, $conventions) {
            $base($table);
            $table->string('code', 30);
            $table->foreignUuid('reservation_id')->nullable()->constrained('booking_reservations');
            $table->string('reservation_code', 30);
            $table->foreignUuid('product_id')->constrained('catalog_products');
            $table->foreignUuid('client_id')->constrained('crm_clients');
            $table->foreignUuid('passenger_id')->nullable()->constrained('booking_passengers');
            $table->string('author_display', 80);
            $table->string('author_name', 160);
            $table->string('country', 60)->nullable();
            $table->date('travel_date');
            $table->smallInteger('stars');
            $table->jsonb('subratings')->default('{}');
            $table->string('title', 120);
            $table->text('body');
            $table->jsonb('photos')->default('[]');
            $table->char('lang', 2)->default('es');
            $table->boolean('verified')->default(true);
            $table->boolean('consent_publish')->default(false);
            $table->string('status', 12)->default('pendiente');
            $table->jsonb('flags')->default('[]');
            $table->text('moderation_reason')->nullable();
            $table->text('reply_text')->nullable();
            $table->uuid('reply_by')->nullable();
            $table->timestampTz('replied_at')->nullable();
            $table->integer('helpful_count')->default(0);
            $table->string('source', 10)->default('portal');
            $table->foreignUuid('request_id')->nullable()->constrained('crm_review_requests');
            $table->boolean('is_demo')->default(false);
            $table->timestampTz('published_at')->nullable();
            $table->uuid('moderated_by')->nullable();
            $conventions($table);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'client_id']);
        });
        DB::statement('CREATE INDEX crm_own_reviews_product_published ON crm_own_reviews (tenant_id, product_id, status, published_at DESC)');
        // RVW-03: una reseña por pasajero, reserva y producto (sin pasajero identificado cuenta como uno).
        DB::statement("CREATE UNIQUE INDEX crm_own_reviews_one_per_pax ON crm_own_reviews (tenant_id, reservation_id, product_id, COALESCE(passenger_id, '00000000-0000-0000-0000-000000000000'::uuid))
            WHERE deleted_at IS NULL AND reservation_id IS NOT NULL");
        $checks('crm_own_reviews', [
            'stars_check' => 'stars BETWEEN 1 AND 5',
            'status_check' => "status IN ('pendiente','publicada','rechazada','oculta')",
            'consent_check' => "status <> 'publicada' OR consent_publish",
            'body_check' => 'char_length(body) BETWEEN 20 AND 2000',
            'lang_check' => "lang IN ('es','en','de','fr','pt')",
            'source_check' => "source IN ('portal','email','widget')",
            'reservation_check' => 'reservation_id IS NOT NULL OR is_demo',
        ]);

        // §3.2 bitácora de moderación (solo inserción).
        Schema::create('crm_own_review_log', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('review_id')->constrained('crm_own_reviews');
            $table->string('action', 20);
            $table->text('note')->nullable();
            $table->uuid('user_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['review_id', 'created_at']);
        });
        $checks('crm_own_review_log', ['action_check' => "action IN ('crear','publicar','rechazar','ocultar','responder','editar_respuesta','autopublicar')"]);

        foreach (self::TABLES as $table) {
            Rls::tenantTable($table, $table === 'crm_own_review_log' ? ['SELECT', 'INSERT'] : ['SELECT', 'INSERT', 'UPDATE']);
        }

        // §3.4 ajustes del módulo.
        DB::statement('ALTER TABLE sys_settings DROP CONSTRAINT IF EXISTS sys_settings_section_check');
        DB::statement('ALTER TABLE sys_settings ADD CONSTRAINT sys_settings_section_check CHECK (section IN ('.self::SECTIONS.",'own_reviews'))");

        // R-OWN-REVIEWS pasa a tener parámetros y override por producto (spec 18 encabezado).
        [, $name, $description, , $params, $scope, $override] = RuleCatalog::RULES['R-OWN-REVIEWS'];
        $update = ['name' => $name, 'description' => $description, 'params' => json_encode($params), 'scope' => json_encode($scope), 'product_override' => $override,
            'params_schema' => json_encode(RuleCatalog::schema($params, 'R-OWN-REVIEWS'))];
        DB::table('platform_booking_rule_templates')->where('rule_key', 'R-OWN-REVIEWS')->update($update);
        DB::table('sys_booking_rules')->where('rule_key', 'R-OWN-REVIEWS')->update($update);

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            OwnReviewProvisioner::seedTenant($tenantId, DB::connection());
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
        DB::table('sys_settings')->where('section', 'own_reviews')->delete();
        DB::statement('ALTER TABLE sys_settings DROP CONSTRAINT IF EXISTS sys_settings_section_check');
        DB::statement('ALTER TABLE sys_settings ADD CONSTRAINT sys_settings_section_check CHECK (section IN ('.self::SECTIONS.'))');
        DB::table('sys_sequences')->whereIn('doc_type', array_keys(OwnReviewProvisioner::SEQUENCES))->delete();
    }
};
