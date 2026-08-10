<?php

namespace Azuriom\Plugin\Vouchers\Models;

use Azuriom\Models\Traits\HasTablePrefix;
use Azuriom\Models\Traits\Loggable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $voucher_id
 * @property string $type
 * @property array $configuration
 * @property int $position
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Azuriom\Plugin\Vouchers\Models\Voucher $voucher
 * @property \Illuminate\Support\Collection|\Azuriom\Plugin\Vouchers\Models\RewardExecution[] $executions
 */
class Reward extends Model
{
    use HasTablePrefix;
    use Loggable;

    public const TYPE_MONEY = 'money';

    public const TYPE_SHOP_PACKAGE = 'shop_package';

    public const TYPE_SERVER_COMMAND = 'server_command';

    public const TYPES = [
        self::TYPE_MONEY,
        self::TYPE_SHOP_PACKAGE,
        self::TYPE_SERVER_COMMAND,
    ];

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
        'type', 'configuration', 'position',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'configuration' => 'array',
        'position' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $reward) {
            $reward->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * Get the voucher that owns this reward.
     */
    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }

    /**
     * Get the execution ledger entries for this reward.
     */
    public function executions()
    {
        return $this->hasMany(RewardExecution::class);
    }

    /**
     * Read a value from the reward configuration.
     */
    public function configuration(string $key, mixed $default = null): mixed
    {
        return data_get($this->configuration, $key, $default);
    }
}
