<?php

use NotificationChannels\WebPush\PushSubscription;

return [

    /**
     * Chaves de autenticação VAPID. Guardadas em env (Railway) — nunca no repo.
     * A pública é exposta ao cliente; a privada é secreta e não pode mudar.
     */
    'vapid' => [
        'subject' => env('VAPID_SUBJECT'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'pem_file' => env('VAPID_PEM_FILE'),
    ],

    'model' => PushSubscription::class,

    'table_name' => env('WEBPUSH_DB_TABLE', 'push_subscriptions'),

    'database_connection' => env('WEBPUSH_DB_CONNECTION', env('DB_CONNECTION', 'pgsql')),

    'client_options' => [],

    'automatic_padding' => env('WEBPUSH_AUTOMATIC_PADDING', true),

];
