<?php

namespace Azuriom\Plugin\Vouchers\Services;

use Azuriom\Models\Role;
use Azuriom\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class InternalRoleCatalog
{
    /**
     * List the non-administrative roles the current manager may grant.
     *
     * @return Collection<int, Role>
     */
    public function roles(?User $actor): Collection
    {
        if ($actor === null) {
            return collect();
        }

        return $this->manageableQuery($actor)
            ->orderByDesc('power')
            ->orderBy('name')
            ->get(['id', 'name', 'power', 'is_admin']);
    }

    /**
     * Return the manageable role IDs from a submitted set.
     *
     * @param  iterable<int, mixed>  $ids
     * @return Collection<int, int>
     */
    public function eligibleIds(iterable $ids, ?User $actor): Collection
    {
        if ($actor === null) {
            return collect();
        }

        $ids = collect($ids)
            ->filter(fn (mixed $id) => filter_var($id, FILTER_VALIDATE_INT) !== false && (int) $id > 0)
            ->map(fn (mixed $id) => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return $this->manageableQuery($actor)
            ->whereKey($ids)
            ->pluck('id')
            ->map(fn (mixed $id) => (int) $id);
    }

    /**
     * Build the audited configuration snapshot stored on a voucher reward.
     *
     * @return array{role_id: int, role_name: string, role_power: int}
     */
    public function configuration(int $roleId, ?User $actor): array
    {
        if ($actor === null || $roleId < 1) {
            throw new UnexpectedValueException('The selected internal role is not available for voucher delivery.');
        }

        $query = $this->manageableQuery($actor);

        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }

        $role = $query->find($roleId);

        if ($role === null) {
            throw new UnexpectedValueException('The selected internal role is not available for voucher delivery.');
        }

        return $this->snapshot($role);
    }

    /**
     * Build a role snapshot after runtime authorization has been rechecked.
     *
     * @return array{role_id: int, role_name: string, role_power: int}
     */
    public function snapshot(Role $role): array
    {
        return [
            'role_id' => (int) $role->getKey(),
            'role_name' => $role->name,
            'role_power' => (int) $role->power,
        ];
    }

    /**
     * Determine whether a live role is safe to grant through a bearer code.
     */
    public function isDeliverable(Role $role): bool
    {
        return ! $role->is_admin && ! $role->hasRawPermission('admin.access');
    }

    /**
     * Restrict configuration to the manager's own authority.
     */
    private function manageableQuery(User $actor): Builder
    {
        $query = $this->deliverableQuery();

        if (! $actor->isAdmin()) {
            $query->where('power', '<=', (int) $actor->role->power);
        }

        return $query;
    }

    /**
     * Never expose a role capable of entering Azuriom administration.
     */
    private function deliverableQuery(): Builder
    {
        return Role::query()
            ->where('is_admin', false)
            ->whereDoesntHave('permissions', fn (Builder $query) => $query->where('permission', 'admin.access'));
    }
}
