<?php

namespace Azuriom\Plugin\Vouchers\Services;

use Azuriom\Models\User;
use Azuriom\Plugin\Vouchers\Models\Redemption;
use Azuriom\Plugin\Vouchers\Models\Reward;
use Azuriom\Plugin\Vouchers\Models\RewardExecution;
use UnexpectedValueException;

class RewardDeliveryService
{
    public function __construct(private readonly PointRewardService $points)
    {
    }

    /**
     * Deliver rewards which can participate in the reservation transaction.
     */
    public function deliverTransactional(RewardExecution $execution, User $recipient): void
    {
        if ($execution->type === Reward::TYPE_MONEY) {
            $this->points->deliver($execution, $recipient);

            return;
        }

        throw new UnexpectedValueException('The voucher reward type is not available for redemption.');
    }

    /**
     * Derive and persist the aggregate redemption state from its executions.
     */
    public function refreshRedemptionStatus(Redemption $redemption): void
    {
        $statuses = $redemption->executions()->pluck('status');
        $hasPending = $statuses->contains(fn (string $status) => in_array($status, [
            RewardExecution::STATUS_PENDING,
            RewardExecution::STATUS_PROCESSING,
        ], true));
        $successful = $statuses->filter(fn (string $status) => in_array($status, [
            RewardExecution::STATUS_SUCCEEDED,
            RewardExecution::STATUS_DISPATCHED,
        ], true))->count();

        if ($hasPending) {
            $status = Redemption::STATUS_PROCESSING;
            $completedAt = null;
        } elseif ($successful === $statuses->count()) {
            $status = Redemption::STATUS_COMPLETED;
            $completedAt = now();
        } elseif ($successful > 0) {
            $status = Redemption::STATUS_PARTIAL;
            $completedAt = now();
        } else {
            $status = Redemption::STATUS_FAILED;
            $completedAt = now();
        }

        $redemption->forceFill([
            'status' => $status,
            'completed_at' => $completedAt,
        ])->save();
    }
}
