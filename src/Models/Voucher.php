<?php

namespace Azuriom\Plugin\Vouchers\Models;

use Azuriom\Models\Traits\Loggable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string $code_hash
 * @property string $code_preview
 * @property bool $is_enabled
 * @property bool $requires_authentication
 * @property int|null $max_redemptions
 * @property int|null $max_redemptions_per_user
 * @property int $redemptions_count
 * @property int $revision
 * @property \Carbon\Carbon|null $starts_at
 * @property \Carbon\Carbon|null $expires_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Illuminate\Support\Collection|\Azuriom\Plugin\Vouchers\Models\Reward[] $rewards
 * @property \Illuminate\Support\Collection|\Azuriom\Plugin\Vouchers\Models\Redemption[] $redemptions
 */
class Voucher extends Model
{
    use Loggable;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_EXHAUSTED = 'exhausted';

    /**
     * The table associated with the model.
     */
    protected $table = 'vouchers_codes';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name', 'code', 'is_enabled', 'requires_authentication',
        'max_redemptions', 'max_redemptions_per_user',
        'starts_at', 'expires_at',
    ];

    /**
     * The attributes that should be hidden from serialization and action logs.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'code', 'code_hash',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_enabled' => 'boolean',
        'requires_authentication' => 'boolean',
        'max_redemptions' => 'integer',
        'max_redemptions_per_user' => 'integer',
        'redemptions_count' => 'integer',
        'revision' => 'integer',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Get the rewards attached to this voucher.
     */
    public function rewards()
    {
        return $this->hasMany(Reward::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Get the redemption history for this voucher.
     */
    public function redemptions()
    {
        return $this->hasMany(Redemption::class);
    }

    /**
     * Find a voucher from a user-provided code.
     */
    public function scopeWhereCode(Builder $query, string $code): void
    {
        $query->whereIn('code_hash', self::hashCandidates($code));
    }

    /**
     * Determine whether this voucher is enabled and inside its date window.
     */
    public function isAvailableAt(CarbonInterface $date): bool
    {
        if (! $this->is_enabled) {
            return false;
        }

        if ($this->starts_at !== null && $date->lt($this->starts_at)) {
            return false;
        }

        return $this->expires_at === null || ! $date->gt($this->expires_at);
    }

    /**
     * Determine whether another global redemption can be reserved.
     */
    public function hasRemainingRedemptions(): bool
    {
        return $this->max_redemptions === null
            || $this->redemptions_count < $this->max_redemptions;
    }

    /**
     * Get the current administration status for this voucher.
     */
    public function availabilityStatusAt(CarbonInterface $date): string
    {
        if (! $this->is_enabled) {
            return self::STATUS_DISABLED;
        }

        if ($this->starts_at !== null && $date->lt($this->starts_at)) {
            return self::STATUS_SCHEDULED;
        }

        if ($this->expires_at !== null && $date->gt($this->expires_at)) {
            return self::STATUS_EXPIRED;
        }

        return $this->hasRemainingRedemptions()
            ? self::STATUS_ACTIVE
            : self::STATUS_EXHAUSTED;
    }

    /**
     * Normalize a code for comparisons and uniqueness checks.
     */
    public static function normalizeCode(string $code): string
    {
        return Str::upper(preg_replace('/[\s-]+/', '', trim($code)) ?? '');
    }

    /**
     * Create the deterministic lookup hash for a code.
     */
    public static function hashCode(string $code, ?string $key = null): string
    {
        $key ??= config('app.key');

        if (! is_string($key) || $key === '') {
            throw new LogicException('The application key is required to hash voucher codes.');
        }

        return hash_hmac('sha256', self::normalizeCode($code), $key);
    }

    /**
     * Create lookup hashes for the current and previous application keys.
     *
     * @return array<int, string>
     */
    public static function hashCandidates(string $code): array
    {
        return collect([config('app.key'), ...config('app.previous_keys', [])])
            ->filter(fn (mixed $key) => is_string($key) && $key !== '')
            ->map(fn (string $key) => self::hashCode($code, $key))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Encrypt codes at rest and keep only a masked preview in plain text.
     */
    protected function code(): Attribute
    {
        return Attribute::make(
            get: static fn (string $value): string => Crypt::decryptString($value),
            set: static function (string $value): array {
                $displayCode = Str::upper(trim($value));
                $normalizedCode = self::normalizeCode($displayCode);

                if (! preg_match('/^[A-Z0-9]{8,64}$/', $normalizedCode)) {
                    throw new InvalidArgumentException('Voucher codes must contain between 8 and 64 letters or numbers.');
                }

                return [
                    'code' => Crypt::encryptString($displayCode),
                    'code_hash' => self::hashCode($normalizedCode),
                    'code_preview' => '****-'.Str::substr($normalizedCode, -4),
                ];
            },
        );
    }
}
