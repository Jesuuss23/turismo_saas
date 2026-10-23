<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Normalización de contacto (CRM-BR-03): correo en minúsculas; teléfono = últimos 9 dígitos si hay ≥ 7. */
    public const EMAIL_NORM = 'lower(btrim(email))';

    public const PHONE_NORM = "CASE WHEN length(regexp_replace(coalesce(phone, ''), '\\D', '', 'g')) >= 7 THEN right(regexp_replace(phone, '\\D', '', 'g'), 9) END";

    // Spec 01 §3.4 y §3.5.
    public function up(): void
    {
        Schema::create('crm_clients', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('code', 30);
            $table->string('kind', 10);
            $table->string('name', 200);
            $table->string('doc_type', 10);
            $table->string('doc_number', 20);
            $table->string('email', 190)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('country', 80)->default('Perú');
            $table->string('city', 80)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('segment_code', 40);
            $table->foreignUuid('owner_id')->constrained('iam_users');
            $table->smallInteger('nps_last')->nullable();
            $table->uuid('source_lead_id')->nullable();
            $table->uuid('b2b_agency_id')->nullable();
            $table->integer('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'segment_code']);
            $table->index(['tenant_id', 'created_at']);
        });
        DB::statement("ALTER TABLE crm_clients ADD COLUMN tags text[] NOT NULL DEFAULT '{}'");
        DB::statement('ALTER TABLE crm_clients ADD COLUMN email_norm varchar(190) GENERATED ALWAYS AS ('.self::EMAIL_NORM.') STORED');
        DB::statement('ALTER TABLE crm_clients ADD COLUMN phone_norm varchar(9) GENERATED ALWAYS AS ('.self::PHONE_NORM.') STORED');
        DB::statement('CREATE INDEX crm_clients_email_norm_idx ON crm_clients (tenant_id, email_norm)');
        DB::statement('CREATE INDEX crm_clients_phone_norm_idx ON crm_clients (tenant_id, phone_norm)');
        DB::statement('CREATE INDEX crm_clients_tags_idx ON crm_clients USING gin (tags)');
        DB::statement('CREATE UNIQUE INDEX crm_clients_document_unique ON crm_clients (tenant_id, doc_type, doc_number) WHERE deleted_at IS NULL');
        DB::statement("ALTER TABLE crm_clients ADD CONSTRAINT crm_clients_kind_check CHECK (kind IN ('persona','empresa'))");
        DB::statement("ALTER TABLE crm_clients ADD CONSTRAINT crm_clients_doc_type_check CHECK (doc_type IN ('DNI','RUC','PASAPORTE','CE'))");
        DB::statement('ALTER TABLE crm_clients ADD CONSTRAINT crm_clients_contact_check CHECK (email IS NOT NULL OR phone IS NOT NULL)');
        DB::statement("ALTER TABLE crm_clients ADD CONSTRAINT crm_clients_company_ruc_check CHECK (kind <> 'empresa' OR country <> 'Perú' OR doc_type = 'RUC')");
        DB::statement('ALTER TABLE crm_clients ADD CONSTRAINT crm_clients_nps_check CHECK (nps_last BETWEEN 0 AND 10)');
        Rls::tenantTable('crm_clients');

        Schema::create('crm_client_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('client_id')->index()->constrained('crm_clients');
            $table->string('name', 160);
            $table->string('role', 120)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 40)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->uuid('source_lead_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX crm_client_contacts_primary_unique ON crm_client_contacts (client_id) WHERE is_primary');
        Rls::tenantTable('crm_client_contacts');

        // Vínculo del usuario de portal con su cliente (spec 00 §3.2).
        Schema::table('iam_users', fn (Blueprint $table) => $table->foreign('client_id')->references('id')->on('crm_clients'));
    }

    public function down(): void
    {
        Schema::table('iam_users', fn (Blueprint $table) => $table->dropForeign(['client_id']));
        Schema::dropIfExists('crm_client_contacts');
        Schema::dropIfExists('crm_clients');
    }
};
