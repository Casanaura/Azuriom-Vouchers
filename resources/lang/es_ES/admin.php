<?php

return [
    'title' => 'Códigos de canje',
    'permission' => 'Administrar códigos de canje',

    'codes' => [
        'title' => 'Códigos de canje',
        'description' => 'Crea códigos, controla quién puede canjearlos y asigna una o varias recompensas.',
        'create' => 'Crear código',
        'edit' => 'Editar :voucher',
        'empty' => 'Todavía no se ha creado ningún código de canje.',
        'created' => 'El código de canje fue creado.',
        'updated' => 'El código de canje fue actualizado.',
        'disabled' => 'El código de canje fue desactivado.',
        'deleted' => 'El código de canje fue eliminado.',
        'delete_has_redemptions' => 'No se puede eliminar un código con historial de canjes. Desactívalo en su lugar.',
    ],

    'fields' => [
        'name' => 'Nombre interno',
        'code' => 'Código',
        'status' => 'Estado',
        'uses' => 'Usos',
        'rewards' => 'Recompensas',
        'max_redemptions' => 'Límite global de canjes',
        'max_redemptions_per_user' => 'Límite de canjes por usuario',
        'starts_at' => 'Fecha de inicio',
        'expires_at' => 'Fecha de finalización',
        'requires_authentication' => 'Requerir que el usuario inicie sesión',
        'is_enabled' => 'Código habilitado',
    ],

    'help' => [
        'code' => 'Usa entre 8 y 64 letras o números. Los espacios y guiones se ignoran al canjear.',
        'max_redemptions' => 'Usa 1 para un código de un solo uso o déjalo vacío para permitir canjes ilimitados.',
        'max_redemptions_per_user' => 'Usa 1 para impedir que la misma cuenta canjee este código más de una vez. Déjalo vacío para permitir canjes ilimitados por cuenta.',
        'requires_authentication' => 'Si se desactiva, los invitados deberán indicar el nombre de una cuenta existente en Azuriom.',
        'shop_package' => 'Se excluyen suscripciones, paquetes con variables obligatorias y giftcards de valor dinámico. Los paquetes deshabilitados siguen disponibles como recompensas ocultas.',
        'server_command' => 'Usa {player} o {name} para el destinatario. Escribe un solo comando sin / inicial. Esperar al jugador requiere un servidor AzLink.',
    ],

    'actions' => [
        'generate' => 'Generar',
        'disable' => 'Desactivar',
    ],

    'rewards' => [
        'title' => 'Recompensas',
        'description' => 'Se entregarán todas las recompensas. Las recompensas externas se procesan después de reservar el voucher.',
        'add' => 'Agregar recompensa',
        'reward' => 'Recompensa',
        'type' => 'Tipo de recompensa',
        'amount' => 'Puntos',
        'package' => 'Paquete / producto de Shop',
        'select_package' => 'Selecciona un paquete',
        'package_unavailable' => 'no disponible',
        'package_disabled' => 'deshabilitado',
        'shop_unavailable' => 'Shop no disponible',
        'shop_unavailable_help' => 'Este voucher contiene una recompensa de Shop, pero Shop no está habilitado. Habilita Shop o reemplaza la recompensa antes de guardar.',
        'server' => 'Servidor de juego',
        'command' => 'Comando',
        'execution_condition' => 'Condición de ejecución',
        'select_server' => 'Selecciona un servidor',
        'server_unavailable' => 'no disponible',
        'server_unavailable_help' => 'Este voucher apunta a un servidor eliminado o que ya no puede ejecutar comandos. Selecciona otro servidor antes de guardar.',
        'unsupported_type' => 'Tipo no compatible: :type',
        'unsupported_type_unknown' => 'Tipo no compatible',
        'types' => [
            'money' => 'Puntos de Shop',
            'shop_package' => 'Paquete / producto de Shop',
            'server_command' => 'Comando de servidor (RCON / AzLink)',
        ],
        'conditions' => [
            'immediate' => 'Ejecutar inmediatamente',
            'online' => 'Esperar a que el jugador esté conectado (solo AzLink)',
        ],
    ],

    'status' => [
        'active' => ['label' => 'Activo', 'color' => 'success'],
        'disabled' => ['label' => 'Desactivado', 'color' => 'secondary'],
        'scheduled' => ['label' => 'Programado', 'color' => 'info'],
        'expired' => ['label' => 'Vencido', 'color' => 'warning'],
        'exhausted' => ['label' => 'Agotado', 'color' => 'danger'],
    ],

    'validation' => [
        'code_format' => 'El código debe contener entre 8 y 64 letras o números.',
        'code_unique' => 'Este código de canje ya está en uso.',
        'expires_after_start' => 'La fecha de finalización debe ser posterior a la fecha de inicio.',
        'stale_revision' => 'Otro administrador modificó este código. Recarga la página y revisa sus cambios antes de guardar de nuevo.',
        'package_unavailable' => 'El paquete de Shop seleccionado no está disponible o requiere datos no compatibles.',
        'server_unavailable' => 'El servidor seleccionado no existe o ya no puede ejecutar comandos.',
        'online_requirement_unavailable' => 'Solo los servidores AzLink pueden esperar a que el jugador esté conectado.',
        'command_format' => 'Usa un solo comando sin / inicial ni caracteres de control. Solo se admiten las variables {player} y {name}.',
        'reward_unavailable' => 'Una integración de recompensa cambió mientras se guardaba el voucher. Revisa las recompensas e inténtalo de nuevo.',
    ],

    'errors' => [
        'generation_failed' => 'No se pudo generar el código. Inténtalo de nuevo.',
    ],

    'unlimited' => 'Ilimitado',

    'logs' => [
        'vouchers-codes' => [
            'created' => 'Creó el código de canje #:id.',
            'updated' => 'Actualizó el código de canje #:id.',
            'deleted' => 'Eliminó el código de canje #:id.',
        ],
        'vouchers-rewards' => [
            'created' => 'Creó la recompensa de código #:id.',
            'updated' => 'Actualizó la recompensa de código #:id.',
            'deleted' => 'Eliminó la recompensa de código #:id.',
        ],
    ],
];
