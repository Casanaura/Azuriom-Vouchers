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
    ],

    'actions' => [
        'generate' => 'Generate',
    ],

    'rewards' => [
        'title' => 'Rewards',
        'description' => 'Every listed reward will be granted in this order.',
        'add' => 'Add reward',
        'reward' => 'Reward',
        'type' => 'Reward type',
        'amount' => 'Points',
        'types' => [
            'money' => 'Shop points',
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
