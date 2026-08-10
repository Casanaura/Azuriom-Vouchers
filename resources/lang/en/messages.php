<?php

return [
    'title' => 'Redeem a voucher',
    'description' => 'Enter your code to receive all of its rewards.',
    'logged_as' => 'The rewards will be granted to your signed-in account: :user.',
    'redeemed' => 'The voucher was redeemed successfully for :user.',
    'redeemed_guest' => 'The voucher was redeemed successfully for the requested account.',
    'delivery_issue' => 'The voucher was reserved, but at least one reward needs staff review. Reference: :reference.',

    'fields' => [
        'code' => 'Voucher code',
        'username' => 'Username',
    ],

    'help' => [
        'guest' => 'Enter the identifier of an existing account. Codes that require authentication will ask you to sign in.',
    ],

    'actions' => [
        'redeem' => 'Redeem code',
    ],

    'errors' => [
        'unavailable' => 'This voucher is invalid or is not available.',
        'authentication_required' => 'You must sign in before redeeming this voucher.',
        'recipient_required' => 'Enter the account that should receive the rewards.',
        'recipient_not_found' => 'No matching account was found.',
        'user_limit_reached' => 'This account has already reached the redemption limit for this voucher.',
        'invalid_configuration' => 'This voucher cannot be delivered. Please contact a staff member.',
    ],
];
