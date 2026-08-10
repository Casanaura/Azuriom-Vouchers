<?php

namespace Azuriom\Plugin\Vouchers\Services;

use Azuriom\Plugin\Shop\Models\Package;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnexpectedValueException;

class ShopPackageCatalog
{
    /**
     * Determine whether the optional Shop integration is loaded.
     */
    public function isAvailable(): bool
    {
        return plugins()->isEnabled('shop') && class_exists(Package::class);
    }

    /**
     * Get all packages which can be granted without collecting variables.
     *
     * @return Collection<int, Package>
     */
    public function packages(): Collection
    {
        if (! $this->isAvailable()) {
            return collect();
        }

        return $this->eligibleQuery()
            ->with('category')
            ->orderBy('category_id')
            ->orderBy('position')
            ->orderBy('name')
            ->get();
    }

    /**
     * Return the eligible IDs from a submitted set.
     *
     * @param iterable<int, mixed> $ids
     * @return Collection<int, int>
     */
    public function eligibleIds(iterable $ids): Collection
    {
        if (! $this->isAvailable()) {
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

        return $this->eligibleQuery()
            ->whereKey($ids)
            ->pluck('id')
            ->map(fn (mixed $id) => (int) $id);
    }

    /**
     * Resolve an eligible package by its immutable identifier.
     */
    public function find(int $packageId): ?Package
    {
        if (! $this->isAvailable() || $packageId < 1) {
            return null;
        }

        return $this->eligibleQuery()->find($packageId);
    }

    /**
     * Build the configuration snapshot stored on a voucher reward.
     *
     * @return array{package_id: int, package_name: string}
     */
    public function configuration(int $packageId): array
    {
        $package = $this->find($packageId);

        if ($package === null) {
            throw new UnexpectedValueException('The selected Shop package is not eligible for voucher delivery.');
        }

        return [
            'package_id' => (int) $package->getKey(),
            'package_name' => $package->name,
        ];
    }

    /**
     * Restrict grants to non-subscription packages with no required input.
     */
    private function eligibleQuery(): Builder
    {
        return Package::query()
            ->whereIn('billing_type', ['one-off', 'expiring'])
            ->whereDoesntHave('variables', fn (Builder $query) => $query->where('is_required', true))
            ->where(function (Builder $query) {
                $query->whereNull('giftcard_balance')->orWhere('giftcard_balance', '>', 0);
            });
    }
}
