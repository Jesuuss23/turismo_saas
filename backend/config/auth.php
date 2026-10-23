<?php

use App\Models\Iam\PlatformUser;
use App\Models\Iam\User;

return [

    'defaults' => [
        'guard' => 'api',
        'passwords' => 'iam_users',
    ],

    /*
    | api      → usuarios de tenant (iam_users), tokens Sanctum.
    | platform → usuarios de plataforma (platform_users), Super Admin SaaS.
    | Ambos usan el guard de Sanctum; el tipo de tokenable distingue la experiencia.
    */
    'guards' => [
        'api' => [
            'driver' => 'sanctum',
            'provider' => 'iam_users',
        ],
        'platform' => [
            'driver' => 'sanctum',
            'provider' => 'platform_users',
        ],
    ],

    'providers' => [
        'iam_users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],
        'platform_users' => [
            'driver' => 'eloquent',
            'model' => PlatformUser::class,
        ],
    ],

    'passwords' => [
        'iam_users' => [
            'provider' => 'iam_users',
            'table' => 'iam_password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,

];
