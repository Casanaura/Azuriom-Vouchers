<?php

return [
    'title' => 'Canjear un código',
    'description' => 'Ingresa tu código para recibir todas sus recompensas.',
    'logged_as' => 'Las recompensas se entregarán a la cuenta con la sesión iniciada: :user.',
    'redeemed' => 'El código fue canjeado correctamente para :user.',
    'redeemed_guest' => 'El código fue canjeado correctamente para la cuenta indicada.',
    'delivery_processing' => 'El código fue reservado y sus recompensas siguen procesándose. Referencia: :reference.',
    'delivery_issue' => 'El código fue reservado, pero al menos una recompensa requiere revisión del equipo. Referencia: :reference.',

    'fields' => [
        'code' => 'Código de canje',
        'username' => 'Nombre de usuario',
    ],

    'help' => [
        'guest' => 'Ingresa el identificador de una cuenta existente. Los códigos que requieran autenticación te pedirán iniciar sesión.',
    ],

    'actions' => [
        'redeem' => 'Canjear código',
    ],

    'errors' => [
        'unavailable' => 'Este código no es válido o no está disponible.',
        'authentication_required' => 'Debes iniciar sesión antes de canjear este código.',
        'recipient_required' => 'Indica la cuenta que recibirá las recompensas.',
        'recipient_not_found' => 'No se encontró una cuenta que coincida.',
        'user_limit_reached' => 'Esta cuenta ya alcanzó el límite de canjes para este código.',
        'invalid_configuration' => 'No se puede entregar este código. Comunícate con un miembro del equipo.',
    ],
];
