<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    // Tablas propias de Laravel (fallos de jobs, lotes) usadas por la conexión de la app.
    public function up(): void
    {
        foreach (['jobs', 'job_batches', 'failed_jobs'] as $table) {
            Rls::grantApp($table, ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
        }
        Rls::grantSequence('jobs_id_seq');
        Rls::grantSequence('failed_jobs_id_seq');
    }

    public function down(): void {}
};
