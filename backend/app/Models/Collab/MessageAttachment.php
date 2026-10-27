<?php

namespace App\Models\Collab;

use App\Models\TenantModel;

/** Adjunto de un mensaje (spec 10 §3.8). */
class MessageAttachment extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    protected $table = 'collab_message_attachments';
}
