<?php

namespace Azuriom\Plugin\Vouchers\Services;

use Azuriom\Models\User;
use Azuriom\Plugin\Vouchers\Models\Reward;
use Azuriom\Plugin\Vouchers\Models\RewardExecution;
use Illuminate\Support\Facades\DB;
use LogicException;
use UnexpectedValueException;

class PointRewardService
{
    private const MAX_BALANCE_CENTS = 99999999999999;

    private const MAX_REWARD_CENTS = 99999999900;

    /**
     * Credit points exactly once inside the redemption transaction.
     */
    public function deliver(RewardExecution $execution, User $recipient): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Point rewards must be delivered inside a database transaction.');
        }

        if ($execution->type !== Reward::TYPE_MONEY) {
            throw new LogicException('The reward execution is not a point reward.');
        }

        if ($execution->status !== RewardExecution::STATUS_PENDING) {
            if ($execution->wasSuccessful()) {
                return;
            }

            throw new LogicException('The point reward execution cannot be delivered from its current state.');
        }

        $amountInCents = $this->parseAmountInCents(data_get($execution->configuration, 'amount'));

        $execution->forceFill([
            'status' => RewardExecution::STATUS_PROCESSING,
            'started_at' => now(),
            'error' => null,
        ])->save();

        $lockedRecipient = User::query()
            ->registered()
            ->lockForUpdate()
            ->findOrFail($recipient->getKey());

        $currentBalanceInCents = (int) round($lockedRecipient->money * 100);

        if ($currentBalanceInCents + $amountInCents > self::MAX_BALANCE_CENTS) {
            throw new UnexpectedValueException('The point reward exceeds the supported account balance.');
        }

        $lockedRecipient->addMoney($amountInCents / 100);

        $execution->forceFill([
            'status' => RewardExecution::STATUS_SUCCEEDED,
            'finished_at' => now(),
        ])->save();
    }

    /**
     * Parse a positive amount with no more than two decimal places.
     */
    private function parseAmountInCents(mixed $amount): int
    {
        if (is_int($amount)) {
            $normalized = (string) $amount;
        } elseif (is_float($amount) && is_finite($amount)) {
            $normalized = (string) $amount;
        } elseif (is_string($amount)) {
            $normalized = trim($amount);
        } else {
            throw new UnexpectedValueException('The point reward amount is invalid.');
        }

        if (! preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D', $normalized)) {
            throw new UnexpectedValueException('The point reward amount is invalid.');
        }

        [$units, $decimals] = array_pad(explode('.', $normalized, 2), 2, '');
        $amountInCents = ((int) $units * 100) + (int) str_pad($decimals, 2, '0');

        if ($amountInCents < 1 || $amountInCents > self::MAX_REWARD_CENTS) {
            throw new UnexpectedValueException('The point reward amount is invalid.');
        }

        return $amountInCents;
    }
}
