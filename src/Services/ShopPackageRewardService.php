<?php

namespace Azuriom\Plugin\Vouchers\Services;

use Azuriom\Models\User;
use Azuriom\Plugin\Shop\Models\Package;
use Azuriom\Plugin\Shop\Models\Payment;
use Azuriom\Plugin\Shop\Models\PaymentItem;
use Azuriom\Plugin\Vouchers\Models\Redemption;
use Azuriom\Plugin\Vouchers\Models\Reward;
use Azuriom\Plugin\Vouchers\Models\RewardExecution;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Throwable;
use UnexpectedValueException;

class ShopPackageRewardService
{
    public function __construct(
        private readonly ShopPackageCatalog $catalog,
        private readonly RedemptionStatusService $redemptionStatuses,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->catalog->isAvailable();
    }

    /**
     * Create the zero-cost Shop payment atomically with the voucher reservation.
     */
    public function prepare(
        RewardExecution $execution,
        Redemption $redemption,
        User $recipient,
    ): void {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Shop package rewards must be prepared inside a database transaction.');
        }

        if ($execution->type !== Reward::TYPE_SHOP_PACKAGE) {
            throw new LogicException('The reward execution is not a Shop package reward.');
        }

        if ($execution->external_reference !== null) {
            throw new LogicException('The Shop package reward has already been prepared.');
        }

        $packageId = filter_var(data_get($execution->configuration, 'package_id'), FILTER_VALIDATE_INT);
        $package = $packageId === false ? null : $this->catalog->find((int) $packageId);

        if ($package === null) {
            throw new UnexpectedValueException('The Shop package reward configuration is invalid.');
        }

        $configuration = [
            ...$execution->configuration,
            'package_id' => (int) $package->getKey(),
            'package_name' => $package->name,
        ];
        $transactionId = $this->transactionId($redemption, $execution);

        if (Payment::query()
            ->where('gateway_type', 'manual')
            ->where('transaction_id', $transactionId)
            ->exists()) {
            throw new LogicException('A Shop payment already exists for this reward execution.');
        }

        $payment = Payment::create([
            'user_id' => $recipient->getKey(),
            'price' => 0,
            'currency' => currency(),
            'status' => 'pending',
            'gateway_type' => 'manual',
            'transaction_id' => $transactionId,
        ]);
        $item = $payment->items()->make([
            'name' => $configuration['package_name'],
            'price' => 0,
            'quantity' => 1,
            'variables' => [],
        ]);
        $item->buyable()->associate($package);
        $item->save();

        $execution->forceFill([
            'configuration' => $configuration,
            'external_reference' => 'shop-payment:'.$payment->getKey(),
        ])->save();
    }

    /**
     * Attempt an external package delivery at most once.
     */
    public function deliver(RewardExecution $execution): void
    {
        if (! $this->isAvailable()) {
            $this->failUnavailable($execution);

            return;
        }

        try {
            $claim = $this->claim($execution);
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        if ($claim === null) {
            return;
        }

        try {
            $payment = Payment::query()
                ->with(['items.buyable', 'user', 'giftcards'])
                ->find($claim['payment_id']);
            $item = $payment?->items->firstWhere('id', $claim['item_id']);

            if ($payment !== null && $payment->status !== 'pending') {
                $this->finish(
                    $execution,
                    $claim['payment_id'],
                    RewardExecution::STATUS_UNCERTAIN,
                    new UnexpectedValueException('The Shop payment changed after its delivery was claimed.'),
                );

                return;
            }

            if ($payment?->items->count() !== 1
                || ! $this->isPreparedItemValid($payment, $item, $claim)) {
                throw new UnexpectedValueException('The prepared Shop payment item is no longer valid.');
            }
        } catch (Throwable $exception) {
            $this->finish($execution, $claim['payment_id'], RewardExecution::STATUS_FAILED, $exception);
            report($exception);

            return;
        }

        try {
            // Shop owns package semantics (events, notifications and expirations), so the
            // canonical payment operation is the external side-effect boundary.
            $payment->deliver();
        } catch (Throwable $exception) {
            $this->finish($execution, $claim['payment_id'], RewardExecution::STATUS_UNCERTAIN, $exception);
            report($exception);

            return;
        }

        try {
            $this->succeed($execution, $claim['payment_id']);
        } catch (Throwable $exception) {
            $this->finish($execution, $claim['payment_id'], RewardExecution::STATUS_UNCERTAIN, $exception);
            report($exception);
        }
    }

    /**
     * Convert an abandoned processing claim to review without invoking it again.
     */
    public function reconcileStale(RewardExecution $execution, CarbonInterface $cutoff): bool
    {
        try {
            return DB::transaction(function () use ($execution, $cutoff) {
                $lockedRedemption = Redemption::query()->lockForUpdate()->find($execution->redemption_id);
                $lockedExecution = $lockedRedemption?->executions()
                    ->whereKey($execution->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($lockedRedemption === null
                    || $lockedExecution === null
                    || $lockedExecution->type !== Reward::TYPE_SHOP_PACKAGE
                    || $lockedExecution->status !== RewardExecution::STATUS_PROCESSING
                    || ($lockedExecution->started_at !== null && $lockedExecution->started_at->gt($cutoff))) {
                    return false;
                }

                $payment = $this->findPaymentForUpdate($lockedExecution);

                $this->finishLocked(
                    $lockedExecution,
                    $payment,
                    RewardExecution::STATUS_UNCERTAIN,
                    'The Shop delivery attempt was interrupted and will not be retried automatically.',
                );
                $this->markPendingPaymentError($lockedExecution);
                $this->redemptionStatuses->refreshLocked($lockedRedemption);

                return true;
            }, 3);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * Claim a pending execution before crossing the external side-effect boundary.
     *
     * @return array{payment_id: int, item_id: int, package_id: int, user_id: int, transaction_id: string}|null
     */
    private function claim(RewardExecution $execution): ?array
    {
        return DB::transaction(function () use ($execution) {
            $lockedRedemption = Redemption::query()
                ->lockForUpdate()
                ->find($execution->redemption_id);
            $lockedExecution = $lockedRedemption?->executions()
                ->whereKey($execution->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedRedemption === null
                || $lockedExecution === null
                || $lockedExecution->status !== RewardExecution::STATUS_PENDING) {
                return null;
            }

            $hasActiveSibling = $lockedRedemption->executions()
                ->whereKeyNot($lockedExecution->getKey())
                ->where('status', RewardExecution::STATUS_PROCESSING)
                ->exists();
            $hasPriorPending = $lockedRedemption->executions()
                ->where('id', '<', $lockedExecution->getKey())
                ->whereIn('status', [
                    RewardExecution::STATUS_PENDING,
                    RewardExecution::STATUS_PROCESSING,
                ])
                ->exists();

            if ($hasActiveSibling || $hasPriorPending) {
                return null;
            }

            $paymentId = $this->paymentId($lockedExecution->external_reference);
            $payment = $paymentId === null
                ? null
                : Payment::query()->lockForUpdate()->find($paymentId);
            $expectedTransactionId = $this->transactionId($lockedRedemption, $lockedExecution);
            $packageId = filter_var(data_get($lockedExecution->configuration, 'package_id'), FILTER_VALIDATE_INT);

            if ($payment === null
                || $packageId === false
                || (int) $payment->user_id !== $lockedRedemption->user_id
                || (float) $payment->price !== 0.0
                || $payment->gateway_type !== 'manual'
                || $payment->transaction_id !== $expectedTransactionId) {
                $this->finishLocked(
                    $lockedExecution,
                    $payment,
                    RewardExecution::STATUS_FAILED,
                    'The prepared Shop payment does not match its voucher execution.',
                );
                $this->redemptionStatuses->refreshLocked($lockedRedemption);

                return null;
            }

            if ($payment->status !== 'pending') {
                $this->finishLocked(
                    $lockedExecution,
                    $payment,
                    RewardExecution::STATUS_UNCERTAIN,
                    'The prepared Shop payment was already modified before delivery.',
                );
                $this->redemptionStatuses->refreshLocked($lockedRedemption);

                return null;
            }

            $items = $payment->items()->with('buyable')->get();

            if ($items->count() !== 1) {
                $this->finishLocked(
                    $lockedExecution,
                    $payment,
                    RewardExecution::STATUS_FAILED,
                    'The prepared Shop payment must contain exactly one item.',
                );
                $this->redemptionStatuses->refreshLocked($lockedRedemption);

                return null;
            }

            $item = $items->first();

            if (! $item->buyable instanceof Package
                || (int) $item->buyable->getKey() !== (int) $packageId
                || (float) $item->price !== 0.0
                || (int) $item->quantity !== 1
                || ! empty($item->variables)
                || $this->catalog->find((int) $packageId) === null) {
                $this->finishLocked(
                    $lockedExecution,
                    $payment,
                    RewardExecution::STATUS_FAILED,
                    'The Shop package is no longer eligible for voucher delivery.',
                );
                $this->redemptionStatuses->refreshLocked($lockedRedemption);

                return null;
            }

            $lockedExecution->forceFill([
                'status' => RewardExecution::STATUS_PROCESSING,
                'attempts' => $lockedExecution->attempts + 1,
                'error' => null,
                'started_at' => now(),
                'finished_at' => null,
            ])->save();

            return [
                'payment_id' => (int) $payment->getKey(),
                'item_id' => (int) $item->getKey(),
                'package_id' => (int) $packageId,
                'user_id' => (int) $payment->user_id,
                'transaction_id' => $expectedTransactionId,
            ];
        }, 3);
    }

    /**
     * Confirm the payment item again immediately before invoking the package.
     *
     * @param array{payment_id: int, item_id: int, package_id: int, user_id: int, transaction_id: string} $claim
     */
    private function isPreparedItemValid(?Payment $payment, ?PaymentItem $item, array $claim): bool
    {
        return $payment !== null
            && $item !== null
            && $payment->status === 'pending'
            && (int) $payment->user_id === $claim['user_id']
            && (float) $payment->price === 0.0
            && $payment->gateway_type === 'manual'
            && $payment->transaction_id === $claim['transaction_id']
            && (int) $payment->getKey() === $claim['payment_id']
            && (int) $item->payment_id === $claim['payment_id']
            && (float) $item->price === 0.0
            && (int) $item->quantity === 1
            && empty($item->variables)
            && $item->buyable instanceof Package
            && (int) $item->buyable->getKey() === $claim['package_id'];
    }

    /**
     * Mark a normally returned package delivery as completed.
     */
    private function succeed(RewardExecution $execution, int $paymentId): void
    {
        DB::transaction(function () use ($execution, $paymentId) {
            $lockedRedemption = Redemption::query()->lockForUpdate()->find($execution->redemption_id);
            $lockedExecution = $lockedRedemption?->executions()
                ->whereKey($execution->getKey())
                ->lockForUpdate()
                ->first();
            $payment = Payment::query()->lockForUpdate()->find($paymentId);

            if ($lockedRedemption === null
                || $lockedExecution === null
                || $lockedExecution->status !== RewardExecution::STATUS_PROCESSING) {
                return;
            }

            if ($payment === null || $payment->status !== 'completed') {
                throw new UnexpectedValueException('Shop did not confirm the package payment as completed.');
            }

            $lockedExecution->forceFill([
                'status' => RewardExecution::STATUS_SUCCEEDED,
                'error' => null,
                'finished_at' => now(),
            ])->save();
            $this->redemptionStatuses->refreshLocked($lockedRedemption);
        }, 3);
    }

    /**
     * Persist a terminal result after a known pre-call failure or uncertain attempt.
     */
    private function finish(
        RewardExecution $execution,
        int $paymentId,
        string $status,
        Throwable $exception,
    ): void {
        try {
            DB::transaction(function () use ($execution, $paymentId, $status, $exception) {
                $lockedRedemption = Redemption::query()->lockForUpdate()->find($execution->redemption_id);
                $lockedExecution = $lockedRedemption?->executions()
                    ->whereKey($execution->getKey())
                    ->lockForUpdate()
                    ->first();
                $payment = Payment::query()->lockForUpdate()->find($paymentId);

                if ($lockedRedemption === null
                    || $lockedExecution === null
                    || $lockedExecution->status !== RewardExecution::STATUS_PROCESSING) {
                    return;
                }

                $this->finishLocked(
                    $lockedExecution,
                    $payment,
                    $status,
                    $this->errorMessage($exception),
                );
                $this->redemptionStatuses->refreshLocked($lockedRedemption);
            }, 3);
        } catch (Throwable $finishException) {
            report($finishException);
        }
    }

    private function finishLocked(
        RewardExecution $execution,
        ?Payment $payment,
        string $status,
        string $error,
    ): void {
        if ($payment !== null && $payment->status === 'pending') {
            $payment->forceFill(['status' => 'error'])->save();
        }

        $execution->forceFill([
            'status' => $status,
            'error' => Str::limit($error, 2000, ''),
            'finished_at' => now(),
        ])->save();
    }

    /**
     * Fail a pending reward before any Shop side effect when the optional plugin is unavailable.
     */
    private function failUnavailable(RewardExecution $execution): void
    {
        try {
            DB::transaction(function () use ($execution) {
                $lockedRedemption = Redemption::query()->lockForUpdate()->find($execution->redemption_id);
                $lockedExecution = $lockedRedemption?->executions()
                    ->whereKey($execution->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($lockedRedemption === null
                    || $lockedExecution === null
                    || $lockedExecution->type !== Reward::TYPE_SHOP_PACKAGE
                    || $lockedExecution->status !== RewardExecution::STATUS_PENDING) {
                    return;
                }

                $this->finishLocked(
                    $lockedExecution,
                    $this->findPaymentForUpdate($lockedExecution),
                    RewardExecution::STATUS_FAILED,
                    'Shop is not enabled, so the package was not delivered.',
                );
                $this->markPendingPaymentError($lockedExecution);
                $this->redemptionStatuses->refreshLocked($lockedRedemption);
            }, 3);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Resolve a prepared payment without requiring Shop to be enabled.
     */
    private function findPaymentForUpdate(RewardExecution $execution): ?Payment
    {
        if (! class_exists(Payment::class) || ! Schema::hasTable('shop_payments')) {
            return null;
        }

        $paymentId = $this->paymentId($execution->external_reference);

        return $paymentId === null
            ? null
            : Payment::query()->lockForUpdate()->find($paymentId);
    }

    /**
     * Keep the Shop audit record terminal even when its model cannot be loaded.
     */
    private function markPendingPaymentError(RewardExecution $execution): void
    {
        if (! Schema::hasTable('shop_payments')) {
            return;
        }

        $paymentId = $this->paymentId($execution->external_reference);

        if ($paymentId !== null) {
            DB::table('shop_payments')
                ->where('id', $paymentId)
                ->where('status', 'pending')
                ->update([
                    'status' => 'error',
                    'updated_at' => now(),
                ]);
        }
    }

    private function paymentId(?string $reference): ?int
    {
        if (! is_string($reference) || preg_match('/^shop-payment:(\d+)$/D', $reference, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function transactionId(Redemption $redemption, RewardExecution $execution): string
    {
        return 'voucher:'.$redemption->uuid.':'.$execution->reward_uuid;
    }

    private function errorMessage(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        return class_basename($exception).($message === '' ? '' : ': '.$message);
    }
}
