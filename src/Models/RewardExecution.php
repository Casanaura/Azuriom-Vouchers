<?php

namespace Azuriom\Plugin\Vouchers\Models;

use Azuriom\Models\Traits\HasTablePrefix;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $redemption_id
 * @property int|null $reward_id
 * @property string $reward_uuid
 * @property string $type
 * @property array $configuration
 * @property string $status
 * @property string|null $error
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $finished_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Azuriom\Plugin\Vouchers\Models\Redemption $redemption
 * @property \Azuriom\Plugin\Vouchers\Models\Reward|null $reward
 */
class RewardExecution extends Model
{
    use HasTablePrefix;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_DISPATCHED = 'dispatched';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNCERTAIN = 'uncertain';

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
        'redemption_id', 'reward_id', 'reward_uuid', 'type', 'configuration',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'configuration' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * Get the redemption that owns this execution.
     */
    public function redemption()
    {
        return $this->belongsTo(Redemption::class);
    }

    /**
     * Get the current reward definition, if it still exists.
     */
    public function reward()
    {
        return $this->belongsTo(Reward::class);
    }

    /**
     * Determine whether the execution reached a successful terminal state.
     */
    public function wasSuccessful(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCEEDED,
            self::STATUS_DISPATCHED,
        ], true);
    }

    /**
     * Determine whether the execution should never be retried automatically.
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCEEDED,
            self::STATUS_DISPATCHED,
            self::STATUS_FAILED,
            self::STATUS_UNCERTAIN,
        ], true);
    }

    /**
     * Create an immutable execution snapshot from a reward definition.
     */
    public static function fromReward(Reward $reward): self
    {
        return (new self())->forceFill([
            'reward_id' => $reward->getKey(),
            'reward_uuid' => $reward->uuid,
            'type' => $reward->type,
            'configuration' => $reward->configuration,
            'status' => self::STATUS_PENDING,
        ]);
    }
}
