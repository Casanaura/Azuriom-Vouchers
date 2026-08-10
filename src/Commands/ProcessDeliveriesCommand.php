<?php

namespace Azuriom\Plugin\Vouchers\Commands;

use Azuriom\Plugin\Vouchers\Models\Reward;
use Azuriom\Plugin\Vouchers\Models\RewardExecution;
use Azuriom\Plugin\Vouchers\Models\Redemption;
use Azuriom\Plugin\Vouchers\Services\RewardDeliveryService;
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
        RewardDeliveryService $delivery,
    ): int {
        $cutoff = now()->subMinutes(10);
        $reconciled = 0;
        $processed = 0;
        $repaired = 0;

        RewardExecution::query()
            ->whereIn('type', Reward::EXTERNAL_TYPES)
            ->where('status', RewardExecution::STATUS_PROCESSING)
            ->where(function ($query) use ($cutoff) {
                $query->whereNull('started_at')->orWhere('started_at', '<=', $cutoff);
            })
            ->chunkById(100, function ($executions) use ($delivery, $cutoff, &$reconciled) {
                foreach ($executions as $execution) {
                    if ($delivery->reconcileDeferredExecution($execution, $cutoff)) {
                        $reconciled++;
                    }
                }
            });

        RewardExecution::query()
            ->whereIn('type', Reward::EXTERNAL_TYPES)
            ->where('status', RewardExecution::STATUS_PENDING)
            ->chunkById(100, function ($executions) use ($delivery, &$processed) {
                foreach ($executions as $execution) {
                    $delivery->deliverDeferredExecution($execution);
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
