<?php

namespace App\Notifications\Channels;

use RuntimeException;

/** Error permanente del canal (destinatario inválido, 4xx de validación): reintentar no cambia el resultado (INT-BR-05, NTF-BR-07). */
class PermanentDeliveryFailure extends RuntimeException {}
