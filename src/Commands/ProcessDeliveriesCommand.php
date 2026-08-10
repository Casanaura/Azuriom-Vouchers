<?php

namespace Azuriom\Plugin\Vouchers\Commands;

use Azuriom\Plugin\Vouchers\Models\Reward;
use Azuriom\Plugin\Vouchers\Models\RewardExecution;
use Azuriom\Plugin\Vouchers\Models\Redemption;
use Azuriom\Plugin\Vouchers\Services\RewardDeliveryService;
use Azuriom\Plugin\Vouchers\Services\ShopPackageRewardService;
use Illuminate\Console\Command;

class ProcessDeliveriesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vouchers:deliveries';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process pending voucher rewards and reconcile interrupted attempts.';

    /**
     * Execute the console command.
     */
    public function handle(
        ShopPackageRewardService $shopPackages,
        RewardDeliveryService $delivery,
    ): int {
        $cutoff = now()->subMinutes(10);
        $reconciled = 0;
        $processed = 0;
        $repaired = 0;

        RewardExecution::query()
            ->where('type', Reward::TYPE_SHOP_PACKAGE)
            ->where('status', RewardExecution::STATUS_PROCESSING)
            ->where(function ($query) use ($cutoff) {
                $query->whereNull('started_at')->orWhere('started_at', '<=', $cutoff);
            })
            ->chunkById(100, function ($executions) use ($shopPackages, $cutoff, &$reconciled) {
                foreach ($executions as $execution) {
                    if ($shopPackages->reconcileStale($execution, $cutoff)) {
                        $reconciled++;
                    }
                }
            });

        RewardExecution::query()
            ->where('type', Reward::TYPE_SHOP_PACKAGE)
            ->where('status', RewardExecution::STATUS_PENDING)
            ->chunkById(100, function ($executions) use ($shopPackages, &$processed) {
                foreach ($executions as $execution) {
                    $shopPackages->deliver($execution);
                    $processed++;
                }
            });

        Redemption::query()
            ->where('status', Redemption::STATUS_PROCESSING)
            ->whereDoesntHave('executions', function ($query) {
                $query->whereIn('status', [
                    RewardExecution::STATUS_PENDING,
                    RewardExecution::STATUS_PROCESSING,
                ]);
            })
            ->chunkById(100, function ($redemptions) use ($delivery, &$repaired) {
                foreach ($redemptions as $redemption) {
                    $delivery->refreshRedemptionStatus($redemption);
                    $repaired++;
                }
            });

        $this->info(
            "Processed {$processed} pending deliveries; reconciled {$reconciled} interrupted attempts; repaired {$repaired} aggregate states."
        );

        return self::SUCCESS;
    }
}
