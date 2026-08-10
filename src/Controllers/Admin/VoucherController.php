<?php

namespace Azuriom\Plugin\Vouchers\Controllers\Admin;

use Azuriom\Http\Controllers\Controller;
use Azuriom\Plugin\Vouchers\Models\Reward;
use Azuriom\Plugin\Vouchers\Models\Voucher;
use Azuriom\Plugin\Vouchers\Requests\VoucherRequest;
use Azuriom\Plugin\Vouchers\Services\VoucherCodeGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VoucherController extends Controller
{
    /**
     * Display all voucher codes.
     */
    public function index(): View
    {
        $vouchers = Voucher::query()
            ->withCount('rewards')
            ->latest()
            ->paginate();

        return view('vouchers::admin.codes.index', [
            'vouchers' => $vouchers,
        ]);
    }

    /**
     * Show the voucher creation form.
     */
    public function create(VoucherCodeGenerator $generator): View
    {
        return view('vouchers::admin.codes.create', [
            'voucher' => new Voucher([
                'code' => $generator->generate(),
                'is_enabled' => true,
                'requires_authentication' => true,
            ]),
            'formRewards' => [[
                'type' => Reward::TYPE_MONEY,
                'amount' => '',
            ]],
        ]);
    }

    /**
     * Store a newly created voucher.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(VoucherRequest $request): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request) {
                $data = $request->validated();
                $rewards = Arr::pull($data, 'rewards');
                Arr::forget($data, 'revision');
                $voucher = Voucher::create($data);

                $voucher->rewards()->createMany($this->mapRewards($rewards));
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'code' => trans('vouchers::admin.validation.code_unique'),
            ]);
        }

        return to_route('vouchers.admin.codes.index')
            ->with('success', trans('vouchers::admin.codes.created'));
    }

    /**
     * Show the voucher editing form.
     */
    public function edit(Voucher $voucher): View
    {
        $voucher->load('rewards');

        return view('vouchers::admin.codes.edit', [
            'voucher' => $voucher,
            'formRewards' => $voucher->rewards->map(fn (Reward $reward) => [
                'type' => $reward->type,
                ...$reward->configuration,
            ])->all(),
        ]);
    }

    /**
     * Update an existing voucher and replace its future reward definition.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function update(VoucherRequest $request, Voucher $voucher): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $voucher) {
                $lockedVoucher = Voucher::query()->lockForUpdate()->findOrFail($voucher->getKey());
                $data = $request->validated();
                $rewards = Arr::pull($data, 'rewards');
                $revision = (int) Arr::pull($data, 'revision');

                if ($lockedVoucher->revision !== $revision) {
                    throw ValidationException::withMessages([
                        'revision' => trans('vouchers::admin.validation.stale_revision'),
                    ]);
                }

                $lockedVoucher->forceFill([
                    ...$data,
                    'revision' => $revision + 1,
                ])->save();
                $lockedVoucher->rewards()->delete();
                $lockedVoucher->rewards()->createMany($this->mapRewards($rewards));
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'code' => trans('vouchers::admin.validation.code_unique'),
            ]);
        }

        return to_route('vouchers.admin.codes.index')
            ->with('success', trans('vouchers::admin.codes.updated'));
    }

    /**
     * Delete a voucher which has never been redeemed.
     */
    public function destroy(Voucher $voucher): RedirectResponse
    {
        $deleted = DB::transaction(function () use ($voucher) {
            $lockedVoucher = Voucher::query()->lockForUpdate()->findOrFail($voucher->getKey());

            if ($lockedVoucher->redemptions()->exists()) {
                return false;
            }

            return $lockedVoucher->delete();
        }, 3);

        if (! $deleted) {
            return to_route('vouchers.admin.codes.index')
                ->with('error', trans('vouchers::admin.codes.delete_has_redemptions'));
        }

        return to_route('vouchers.admin.codes.index')
            ->with('success', trans('vouchers::admin.codes.deleted'));
    }

    /**
     * Generate a unique code for the administration form.
     */
    public function generate(VoucherCodeGenerator $generator): JsonResponse
    {
        return response()->json([
            'code' => $generator->generate(),
        ]);
    }

    /**
     * Convert validated form rewards to persistence attributes.
     *
     * @param  array<int, array<string, mixed>>  $rewards
     * @return array<int, array<string, mixed>>
     */
    private function mapRewards(array $rewards): array
    {
        return collect($rewards)
            ->values()
            ->map(fn (array $reward, int $position) => [
                'type' => $reward['type'],
                'configuration' => match ($reward['type']) {
                    Reward::TYPE_MONEY => [
                        'amount' => $this->normalizePointAmount($reward['amount']),
                    ],
                },
                'position' => $position,
            ])
            ->all();
    }

    /**
     * Preserve an exact, canonical decimal representation for point rewards.
     */
    private function normalizePointAmount(mixed $amount): string
    {
        [$units, $decimals] = array_pad(explode('.', trim((string) $amount), 2), 2, '');
        $units = ltrim($units, '0');
        $units = $units === '' ? '0' : $units;
        $decimals = rtrim($decimals, '0');

        return $decimals === '' ? $units : $units.'.'.$decimals;
    }
}
