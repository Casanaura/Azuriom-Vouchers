<?php

return [
    'title' => 'Vouchers',
    'permission' => 'Manage vouchers',

    'codes' => [
        'title' => 'Voucher codes',
        'description' => 'Create codes, control who can redeem them and attach one or more rewards.',
        'create' => 'Create voucher',
        'edit' => 'Edit :voucher',
        'empty' => 'No voucher codes have been created yet.',
        'created' => 'The voucher code has been created.',
        'updated' => 'The voucher code has been updated.',
        'disabled' => 'The voucher code has been disabled.',
        'deleted' => 'The voucher code has been deleted.',
        'delete_has_redemptions' => 'A voucher with redemption history cannot be deleted. Disable it instead.',
    ],

    'fields' => [
        'name' => 'Internal name',
        'code' => 'Code',
        'status' => 'Status',
        'uses' => 'Uses',
        'rewards' => 'Rewards',
        'max_redemptions' => 'Global redemption limit',
        'max_redemptions_per_user' => 'Redemption limit per user',
        'starts_at' => 'Start date',
        'expires_at' => 'End date',
        'requires_authentication' => 'Require the user to be signed in',
        'is_enabled' => 'Voucher enabled',
    ],

    'help' => [
        'code' => 'Use 8 to 64 letters or numbers. Spaces and hyphens are ignored when redeeming.',
        'max_redemptions' => 'Use 1 for a single-use code, or leave blank for unlimited redemptions.',
        'max_redemptions_per_user' => 'Use 1 to prevent the same account from redeeming this code more than once. Leave blank for unlimited redemptions per account.',
        'requires_authentication' => 'When disabled, guests must provide the name of an existing Azuriom account.',
        'shop_package' => 'Subscriptions, packages with required variables and dynamic-value gift cards are excluded. Disabled packages remain available as hidden rewards.',
        'server_command' => 'Use {player} or {name} for the recipient. Write one command without a leading slash. Waiting for the player requires an AzLink server.',
        'internal_role' => 'Promotes the account only when this role has more power than its current role. Only one role reward is allowed per voucher. Administrative roles are excluded and Discord linked roles are not synchronized.',
    ],

    'actions' => [
        'generate' => 'Generate',
        'disable' => 'Disable',
    ],

    'rewards' => [
        'title' => 'Rewards',
        'description' => 'Every listed reward will be granted. External rewards are processed after the voucher is reserved.',
        'add' => 'Add reward',
        'reward' => 'Reward',
        'type' => 'Reward type',
        'amount' => 'Points',
        'package' => 'Shop package / product',
        'select_package' => 'Select a package',
        'package_unavailable' => 'unavailable',
        'package_disabled' => 'disabled',
        'shop_unavailable' => 'Shop unavailable',
        'shop_unavailable_help' => 'This voucher contains a Shop reward, but Shop is not enabled. Enable Shop or replace the reward before saving.',
        'server' => 'Game server',
        'command' => 'Command',
        'execution_condition' => 'Execution condition',
        'select_server' => 'Select a server',
        'server_unavailable' => 'unavailable',
        'server_unavailable_help' => 'This voucher points to a deleted server or one that can no longer execute commands. Select another server before saving.',
        'role' => 'Internal role',
        'select_role' => 'Select a role',
        'role_unavailable' => 'unavailable',
        'role_unavailable_help' => 'This voucher points to a deleted, administrative or no longer manageable role. Select another role before saving.',
        'unsupported_type' => 'Unsupported type: :type',
        'unsupported_type_unknown' => 'Unsupported type',
        'types' => [
            'money' => 'Shop points',
            'shop_package' => 'Shop package / product',
            'server_command' => 'Server command (RCON / AzLink)',
            'internal_role' => 'Internal Azuriom role',
        ],
        'conditions' => [
            'immediate' => 'Execute immediately',
            'online' => 'Wait until the player is online (AzLink only)',
        ],
    ],

    'status' => [
        'active' => ['label' => 'Active', 'color' => 'success'],
        'disabled' => ['label' => 'Disabled', 'color' => 'secondary'],
        'scheduled' => ['label' => 'Scheduled', 'color' => 'info'],
        'expired' => ['label' => 'Expired', 'color' => 'warning'],
        'exhausted' => ['label' => 'Exhausted', 'color' => 'danger'],
    ],

    'validation' => [
        'code_format' => 'The code must contain between 8 and 64 letters or numbers.',
        'code_unique' => 'This voucher code is already in use.',
        'expires_after_start' => 'The end date must be later than the start date.',
        'stale_revision' => 'This voucher was changed by another administrator. Reload the page and review their changes before saving again.',
        'package_unavailable' => 'The selected Shop package is unavailable or requires unsupported input.',
        'server_unavailable' => 'The selected server does not exist or can no longer execute commands.',
        'online_requirement_unavailable' => 'Only AzLink servers can wait until the player is online.',
        'command_format' => 'Use one command without a leading slash or control characters. Only {player} and {name} placeholders are supported.',
        'role_unavailable' => 'The selected role is unavailable, administrative or outside your authority.',
        'role_limit' => 'A voucher can contain only one internal role reward.',
        'reward_unavailable' => 'A selected reward integration changed while the voucher was being saved. Review the rewards and try again.',
    ],

    'errors' => [
        'generation_failed' => 'The code could not be generated. Please try again.',
    ],

    'unlimited' => 'Unlimited',

    'logs' => [
        'vouchers-codes' => [
            'created' => 'Created voucher code #:id.',
            'updated' => 'Updated voucher code #:id.',
            'deleted' => 'Deleted voucher code #:id.',
        ],
        'vouchers-rewards' => [
            'created' => 'Created voucher reward #:id.',
            'updated' => 'Updated voucher reward #:id.',
            'deleted' => 'Deleted voucher reward #:id.',
        ],
    ],
];
