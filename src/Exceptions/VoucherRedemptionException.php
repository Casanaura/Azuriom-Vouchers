<?php

namespace Azuriom\Plugin\Vouchers\Exceptions;

use RuntimeException;

class VoucherRedemptionException extends RuntimeException
{
    public const UNAVAILABLE = 'unavailable';

    public const AUTHENTICATION_REQUIRED = 'authentication_required';

    public const RECIPIENT_REQUIRED = 'recipient_required';

    public const RECIPIENT_NOT_FOUND = 'recipient_not_found';

    public const USER_LIMIT_REACHED = 'user_limit_reached';

    public const INVALID_CONFIGURATION = 'invalid_configuration';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
