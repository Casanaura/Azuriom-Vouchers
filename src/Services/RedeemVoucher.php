<?php

namespace Azuriom\Plugin\Vouchers\Services;

use Azuriom\Models\User;
use Azuriom\Plugin\Vouchers\Exceptions\VoucherRedemptionException;
use Azuriom\Plugin\Vouchers\Models\Redemption;
use Azuriom\Plugin\Vouchers\Models\Reward;
use Azuriom\Plugin\Vouchers\Models\RewardExecution;
use Azuriom\Plugin\Vouchers\Models\Voucher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use UnexpectedValueException;

class RedeemVoucher
{
    public function __construct(private readonly RewardDeliveryService $delivery)
    {
    }

    /**
     * Reserve a voucher and deliver every transactional reward exactly once.
     */
    public function redeem(
        string $code,
        ?User $authenticatedUser,
        ?string $recipientIdentifier,
        string $requestToken,
        ?string $ipAddress,
    ): Redemption {
        $requestFingerprints = $this->requestFingerprints(
            $code,
            $authenticatedUser,
            $recipientIdentifier,
        );
        $existing = Redemption::query()->firstWhere('request_token', $requestToken);

        if ($existing !== null) {
            $redemption = $this->existingRedemption($existing, $authenticatedUser, $requestFingerprints);

            return $this->delivery->deliverDeferred($redemption);
        }

        try {
            $redemption = DB::transaction(function () use (
                $code,
                $authenticatedUser,
                $recipientIdentifier,
                $requestToken,
                $requestFingerprints,
                $ipAddress,
            ) {
                $voucher = Voucher::query()
                    ->whereCode($code)
                    ->with('rewards')
                    ->lockForUpdate()
                    ->first();

                if ($voucher === null) {
                    throw new VoucherRedemptionException(VoucherRedemptionException::UNAVAILABLE);
                }

                $existing = Redemption::query()->firstWhere('request_token', $requestToken);

                if ($existing !== null) {
                    return $this->existingRedemption($existing, $authenticatedUser, $requestFingerprints);
                }

                if (! $voucher->isAvailableAt(now()) || ! $voucher->hasRemainingRedemptions()) {
                    throw new VoucherRedemptionException(VoucherRedemptionException::UNAVAILABLE);
                }

                $recipient = $this->resolveRecipient($voucher, $authenticatedUser, $recipientIdentifier);
                $recipientKey = Redemption::recipientKey($recipient);

                if ($voucher->max_redemptions_per_user !== null
                    && $voucher->redemptions()->where('recipient_key', $recipientKey)->count()
                        >= $voucher->max_redemptions_per_user) {
                    throw new VoucherRedemptionException(VoucherRedemptionException::USER_LIMIT_REACHED);
                }

                if ($voucher->rewards->isEmpty()) {
                    throw new VoucherRedemptionException(VoucherRedemptionException::INVALID_CONFIGURATION);
                }

                if ($voucher->rewards->where('type', Reward::TYPE_INTERNAL_ROLE)->count() > 1) {
                    throw new VoucherRedemptionException(VoucherRedemptionException::INVALID_CONFIGURATION);
                }

                $redemption = new Redemption();
                $redemption->forceFill([
                    'request_token' => $requestToken,
                    'request_fingerprint' => $requestFingerprints[0],
                    'user_id' => $recipient->getKey(),
                    'redeemer_id' => $authenticatedUser?->getKey(),
                    'username' => $recipient->name,
                    'recipient_key' => $recipientKey,
                    'ip_address' => $ipAddress === null ? null : Str::limit($ipAddress, 45, ''),
                    'status' => Redemption::STATUS_PROCESSING,
                ]);
                $voucher->redemptions()->save($redemption);

                $internalRoleExecution = null;

                foreach ($voucher->rewards as $reward) {
                    $execution = RewardExecution::fromReward($reward);
                    $redemption->executions()->save($execution);
                    $this->delivery->prepare($execution, $redemption, $recipient);

                    if ($reward->type === Reward::TYPE_INTERNAL_ROLE) {
                        $internalRoleExecution = $execution;

                        continue;
                    }

                    $this->delivery->deliverTransactional($execution, $recipient);
                }

                // Role rows are shared by many users, so hold their locks only at the end.
                if ($internalRoleExecution !== null) {
                    $this->delivery->deliverTransactional($internalRoleExecution, $recipient);
                }

                $voucher->increment('redemptions_count');
                $this->delivery->refreshRedemptionStatus($redemption);

                return $redemption->fresh(['executions', 'user']);
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = Redemption::query()->firstWhere('request_token', $requestToken);

            if ($existing === null) {
                throw $exception;
            }

            $redemption = $this->existingRedemption($existing, $authenticatedUser, $requestFingerprints);
        } catch (UnexpectedValueException $exception) {
            report($exception);

            throw new VoucherRedemptionException(VoucherRedemptionException::INVALID_CONFIGURATION);
        } catch (ModelNotFoundException) {
            throw new VoucherRedemptionException(VoucherRedemptionException::RECIPIENT_NOT_FOUND);
        }

        return $this->delivery->deliverDeferred($redemption);
    }

    /**
     * Resolve the only recipient types supported by Azuriom rewards and bridges.
     */
    private function resolveRecipient(
        Voucher $voucher,
        ?User $authenticatedUser,
        ?string $recipientIdentifier,
    ): User {
        if ($authenticatedUser !== null) {
            return $authenticatedUser;
        }

        if ($voucher->requires_authentication) {
            throw new VoucherRedemptionException(VoucherRedemptionException::AUTHENTICATION_REQUIRED);
        }

        if (! is_string($recipientIdentifier) || trim($recipientIdentifier) === '') {
            throw new VoucherRedemptionException(VoucherRedemptionException::RECIPIENT_REQUIRED);
        }

        $column = game()->userPrimaryAttribute()->value;
        $recipient = User::query()
            ->registered()
            ->where($column, trim($recipientIdentifier))
            ->first();

        if ($recipient === null) {
            throw new VoucherRedemptionException(VoucherRedemptionException::RECIPIENT_NOT_FOUND);
        }

        return $recipient;
    }

    /**
     * Return the original result for a safely repeated HTTP request.
     */
    private function existingRedemption(
        Redemption $redemption,
        ?User $authenticatedUser,
        array $requestFingerprints,
    ): Redemption {
        $fingerprintMatches = is_string($redemption->request_fingerprint)
            && collect($requestFingerprints)->contains(
                fn (string $fingerprint) => hash_equals($redemption->request_fingerprint, $fingerprint)
            );

        if ($redemption->redeemer_id !== $authenticatedUser?->getKey() || ! $fingerprintMatches) {
            throw new VoucherRedemptionException(VoucherRedemptionException::UNAVAILABLE);
        }

        return $redemption->loadMissing(['executions', 'user']);
    }

    /**
     * Bind an idempotency token to the submitted code and intended actor.
     *
     * @return array<int, string>
     */
    private function requestFingerprints(
        string $code,
        ?User $authenticatedUser,
        ?string $recipientIdentifier,
    ): array {
        $actor = $authenticatedUser === null
            ? 'guest:'.Str::lower(trim((string) $recipientIdentifier))
            : Redemption::recipientKey($authenticatedUser);
        $intent = Voucher::normalizeCode($code)."\0".$actor;
        $keys = collect([config('app.key'), ...config('app.previous_keys', [])])
            ->filter(fn (mixed $key) => is_string($key) && $key !== '')
            ->unique()
            ->values();

        if ($keys->isEmpty()) {
            throw new LogicException('The application key is required to protect redemption requests.');
        }

        return $keys
            ->map(fn (string $key) => hash_hmac('sha256', $intent, $key))
            ->all();
    }
}
