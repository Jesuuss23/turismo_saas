<?php

namespace App\Models\Sys;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Evento de auditoría append-only (§3.6). Visibilidad por RLS; solo lectura desde Eloquent:
 * la escritura pasa por AuditLogger.
 */
class AuditEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'audit_events';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'is_critical' => 'boolean',
            'diff' => 'array',
            'evidence' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function displayCode(): string
    {
        return 'AUD-'.$this->code;
    }

    public function save(array $options = []): bool
    {
        throw new LogicException('audit_events es append-only: use AuditLogger.');
    }

    public function delete(): ?bool
    {
        throw new LogicException('audit_events es append-only.');
    }
}
