<?php

namespace Azuriom\Plugin\Vouchers\Services;

use Azuriom\Models\Role;
use Azuriom\Models\User;
use Azuriom\Plugin\Vouchers\Models\Reward;
use Azuriom\Plugin\Vouchers\Models\RewardExecution;
use Illuminate\Support\Facades\DB;
use LogicException;
use UnexpectedValueException;

class InternalRoleRewardService
{
    public function __construct(private readonly InternalRoleCatalog $roles)
    {
    }

    /**
     * Promote the recipient exactly once inside the redemption transaction.
     */
    public function deliver(RewardExecution $execution, User $recipient): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Internal role rewards must be delivered inside a database transaction.');
        }

        if ($execution->type !== Reward::TYPE_INTERNAL_ROLE) {
            throw new LogicException('The reward execution is not an internal role reward.');
        }

        if ($execution->status !== RewardExecution::STATUS_PENDING) {
            if ($execution->wasSuccessful()) {
                return;
            }

            throw new LogicException('The internal role reward execution cannot be delivered from its current state.');
        }

        $roleId = filter_var(data_get($execution->configuration, 'role_id'), FILTER_VALIDATE_INT);

        if ($roleId === false || $roleId < 1) {
            throw new UnexpectedValueException('The internal role reward configuration is invalid.');
        }

        $execution->forceFill([
            'status' => RewardExecution::STATUS_PROCESSING,
            'started_at' => now(),
            'error' => null,
        ])->save();

        $lockedRecipient = User::query()
            ->registered()
            ->lockForUpdate()
            ->findOrFail($recipient->getKey());
        $lockedRoles = Role::query()
            ->with('permissions')
            ->whereKey(array_unique([$lockedRecipient->role_id, (int) $roleId]))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $currentRole = $lockedRoles->get($lockedRecipient->role_id);
        $targetRole = $lockedRoles->get((int) $roleId);

        if ($currentRole === null) {
            throw new UnexpectedValueException('The voucher recipient has an invalid internal role.');
        }

        if ($targetRole === null || ! $this->roles->isDeliverable($targetRole)) {
            throw new UnexpectedValueException('The internal role reward targets an unavailable role.');
        }

        $execution->forceFill([
            'configuration' => $this->roles->snapshot($targetRole),
        ])->save();

        $currentRoleHasAdminAccess = $currentRole->is_admin
            || $currentRole->hasRawPermission('admin.access');

        if (! $currentRoleHasAdminAccess && (int) $currentRole->power < (int) $targetRole->power) {
            $lockedRecipient->role()->associate($targetRole);

            // This reward is intentionally local: avoid Discord HTTP calls inside the DB transaction.
            $lockedRecipient->saveQuietly();
            $lockedRecipient->setRelation('role', $targetRole);
        }

        $execution->forceFill([
            'status' => RewardExecution::STATUS_SUCCEEDED,
            'finished_at' => now(),
        ])->save();
    }
}
