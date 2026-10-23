<?php

use App\Models\Iam\PlatformUser;
use Tests\TestCase;

uses(TestCase::class)->in('Feature');

/** Lee una tabla markdown de la especificación (docs/specs) bajo el encabezado dado. */
function specTable(string $heading, string $file = '00-transversal.md'): array
{
    $lines = file(base_path("../docs/specs/{$file}"), FILE_IGNORE_NEW_LINES);
    $start = null;
    foreach ($lines as $i => $line) {
        if (str_starts_with($line, '#') && str_contains($line, $heading)) {
            $start = $i;
            break;
        }
    }
    expect($start)->not->toBeNull("Encabezado {$heading} no encontrado en la spec");

    $rows = [];
    $inTable = false;
    for ($i = $start + 1; $i < count($lines); $i++) {
        $line = $lines[$i];
        if (str_starts_with($line, '|')) {
            $inTable = true;
            $cells = array_map('trim', explode('|', trim($line, '|')));
            if (! preg_match('/^-+$/', $cells[0])) {
                $rows[] = $cells;
            }
        } elseif ($inTable) {
            break;
        }
    }

    return $rows;
}

function createPlatformUser(string $email = 'sa@turismo.test', string $secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'): PlatformUser
{
    return PlatformUser::create([
        'name' => 'Super Admin', 'email' => $email, 'password' => 'Secreta123!', 'mfa_secret' => $secret, 'status' => 'activo',
    ]);
}
