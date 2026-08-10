<?php

namespace Azuriom\Plugin\Vouchers\Services;

use Azuriom\Plugin\Vouchers\Models\Redemption;
use Azuriom\Plugin\Vouchers\Models\RewardExecution;
use Illuminate\Support\Facades\DB;
use LogicException;

class RedemptionStatusService
{
    /**
     * Recalculate a redemption while serializing against reward transitions.
     */
    public function refresh(Redemption $redemption): void
    {
        DB::transaction(function () use ($redemption) {
            $lockedRedemption = Redemption::query()->lockForUpdate()->find($redemption->getKey());

            if ($lockedRedemption !== null) {
                $this->refreshLocked($lockedRedemption);
            }
        }, 3);
    }

    /**
     * Derive the aggregate state while the parent redemption is already locked.
     */
    public function refreshLocked(Redemption $redemption): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('A redemption must be locked inside a database transaction.');
        }

        $statuses = $redemption->executions()->pluck('status');
        $terminalAt = $redemption->completed_at ?? now();
        $hasPending = $statuses->contains(fn (string $status) => in_array($status, [
            RewardExecution::STATUS_PENDING,
            RewardExecution::STATUS_PROCESSING,
        ], true));
        $successful = $statuses->filter(fn (string $status) => in_array($status, [
            RewardExecution::STATUS_SUCCEEDED,
            RewardExecution::STATUS_DISPATCHED,
        ], true))->count();
        $needsReview = $statuses->contains(RewardExecution::STATUS_UNCERTAIN);

        if ($hasPending) {
            $status = Redemption::STATUS_PROCESSING;
            $completedAt = null;
        } elseif ($needsReview) {
            $status = Redemption::STATUS_REVIEW_REQUIRED;
            $completedAt = $terminalAt;
        } elseif ($statuses->isNotEmpty() && $successful === $statuses->count()) {
            $status = Redemption::STATUS_COMPLETED;
            $completedAt = $terminalAt;
        } elseif ($successful > 0) {
            $status = Redemption::STATUS_PARTIAL;
            $completedAt = $terminalAt;
        } else {
            $status = Redemption::STATUS_FAILED;
            $completedAt = $terminalAt;
        }

        $redemption->forceFill([
            'status' => $status,
            'completed_at' => $completedAt,
        ])->save();
    }
}
