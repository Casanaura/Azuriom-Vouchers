<?php

namespace Azuriom\Plugin\Vouchers\Services;

use Azuriom\Models\User;
use Azuriom\Plugin\Vouchers\Models\Redemption;
use Azuriom\Plugin\Vouchers\Models\Reward;
use Azuriom\Plugin\Vouchers\Models\RewardExecution;
use UnexpectedValueException;

class RewardDeliveryService
{
    public function __construct(
        private readonly PointRewardService $points,
        private readonly ShopPackageRewardService $shopPackages,
        private readonly RedemptionStatusService $redemptionStatuses,
    ) {
    }

    /**
     * Prepare external records within the atomic voucher reservation.
     */
    public function prepare(
        RewardExecution $execution,
        Redemption $redemption,
        User $recipient,
    ): void {
        if ($execution->type === Reward::TYPE_MONEY) {
            return;
        }

        if ($execution->type === Reward::TYPE_SHOP_PACKAGE) {
            $this->shopPackages->prepare($execution, $redemption, $recipient);

            return;
        }

        throw new UnexpectedValueException('The voucher reward type is not available for redemption.');
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

        if ($execution->type !== Reward::TYPE_SHOP_PACKAGE) {
            throw new UnexpectedValueException('The voucher reward type is not available for redemption.');
        }
    }

    /**
     * Deliver every pending external reward after the reservation commits.
     */
    public function deliverDeferred(Redemption $redemption): Redemption
    {
        $cutoff = now()->subMinutes(10);

        $redemption->executions()
            ->where('type', Reward::TYPE_SHOP_PACKAGE)
            ->where('status', RewardExecution::STATUS_PROCESSING)
            ->where(function ($query) use ($cutoff) {
                $query->whereNull('started_at')->orWhere('started_at', '<=', $cutoff);
            })
            ->orderBy('id')
            ->get()
            ->each(fn (RewardExecution $execution) => $this->shopPackages->reconcileStale($execution, $cutoff));

        $redemption->executions()
            ->where('status', RewardExecution::STATUS_PENDING)
            ->orderBy('id')
            ->get()
            ->each(function (RewardExecution $execution) {
                if ($execution->type === Reward::TYPE_SHOP_PACKAGE) {
                    $this->shopPackages->deliver($execution);
                }
            });

        $this->refreshRedemptionStatus($redemption);

        return $redemption->fresh(['executions', 'user']);
    }

    /**
     * Derive and persist the aggregate redemption state from its executions.
     */
    public function refreshRedemptionStatus(Redemption $redemption): void
    {
        $this->redemptionStatuses->refresh($redemption);
    }
}
