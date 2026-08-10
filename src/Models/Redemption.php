<?php

namespace Azuriom\Plugin\Vouchers\Models;

use Azuriom\Models\Traits\HasTablePrefix;
use Azuriom\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $voucher_id
 * @property int|null $user_id
 * @property int|null $redeemer_id
 * @property string $username
 * @property string $recipient_key
 * @property string|null $ip_address
 * @property string $status
 * @property \Carbon\Carbon|null $completed_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Azuriom\Plugin\Vouchers\Models\Voucher $voucher
 * @property \Azuriom\Models\User|null $user
 * @property \Azuriom\Models\User|null $redeemer
 * @property \Illuminate\Support\Collection|\Azuriom\Plugin\Vouchers\Models\RewardExecution[] $executions
 */
class Redemption extends Model
{
    use HasTablePrefix;

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    /**
     * The table prefix associated with the model.
     */
    protected string $prefix = 'vouchers_';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'voucher_id', 'user_id', 'redeemer_id', 'username',
        'recipient_key', 'ip_address',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $redemption) {
            $redemption->uuid ??= (string) Str::uuid();
            $redemption->status ??= self::STATUS_PROCESSING;
        });
    }

    /**
     * Get the voucher redeemed by this entry.
     */
    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }

    /**
     * Get the recipient of the voucher rewards.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the authenticated actor who submitted the redemption, if any.
     */
    public function redeemer()
    {
        return $this->belongsTo(User::class, 'redeemer_id');
    }

    /**
     * Get the individual reward execution records.
     */
    public function executions()
    {
        return $this->hasMany(RewardExecution::class);
    }

    /**
     * Build a durable identity for per-recipient redemption limits.
     */
    public static function recipientKey(User $user): string
    {
        return 'user:'.$user->getKey();
    }
}
