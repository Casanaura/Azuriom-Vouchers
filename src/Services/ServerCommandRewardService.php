<?php

namespace Azuriom\Plugin\Vouchers\Services;

use Azuriom\Models\User;
use Azuriom\Plugin\Vouchers\Models\Redemption;
use Azuriom\Plugin\Vouchers\Models\Reward;
use Azuriom\Plugin\Vouchers\Models\RewardExecution;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;
use UnexpectedValueException;

class ServerCommandRewardService
{
    public function __construct(
        private readonly ServerCommandCatalog $servers,
        private readonly RedemptionStatusService $redemptionStatuses,
    ) {
    }

    /**
     * Validate and snapshot a server command inside the voucher reservation.
     */
    public function prepare(
        RewardExecution $execution,
        Redemption $redemption,
        User $recipient,
    ): void {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Server command rewards must be prepared inside a database transaction.');
        }

        if ($execution->type !== Reward::TYPE_SERVER_COMMAND) {
            throw new LogicException('The reward execution is not a server command reward.');
        }

        if ($execution->external_reference !== null) {
            throw new LogicException('The server command reward has already been prepared.');
        }

        $serverId = filter_var(data_get($execution->configuration, 'server_id'), FILTER_VALIDATE_INT);
        $command = $this->validatedTemplate(data_get($execution->configuration, 'command'));
        $requireOnline = $this->validatedBoolean(data_get($execution->configuration, 'require_online', false));

        if ($serverId === false || $requireOnline === null) {
            throw new UnexpectedValueException('The server command reward configuration is invalid.');
        }

        $command = $this->renderCommand($command, $recipient);
        $configuration = $this->servers->configuration((int) $serverId, $command, $requireOnline);

        $execution->forceFill([
            'configuration' => $configuration,
            'external_reference' => $this->externalReference($execution, (int) $serverId),
        ])->save();
    }

    /**
     * Dispatch one command at most once after the voucher reservation commits.
     */
    public function deliver(RewardExecution $execution): void
    {
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
            $server = $this->servers->find($claim['server_id']);
            $recipient = User::query()->registered()->find($claim['user_id']);

            if ($server === null || $recipient === null) {
                throw new UnexpectedValueException('The command target is no longer available.');
            }

            $bridge = $server->bridge();

            if ($server->type !== $claim['server_type']
                || ! $bridge->canExecuteCommand()
                || ($claim['require_online'] && ! $this->servers->supportsOnlineRequirement($server))) {
                throw new UnexpectedValueException('The selected server can no longer execute commands.');
            }
        } catch (Throwable $exception) {
            $this->finish($execution, RewardExecution::STATUS_FAILED, $exception);
            report($exception);

            return;
        }

        try {
            $bridge->sendCommands([$claim['command']], $recipient, $claim['require_online']);
        } catch (Throwable $exception) {
            $this->finish($execution, RewardExecution::STATUS_UNCERTAIN, $exception);
            report($exception);

            return;
        }

        try {
            $this->markDispatched($execution);
        } catch (Throwable $exception) {
            $this->finish($execution, RewardExecution::STATUS_UNCERTAIN, $exception);
            report($exception);
        }
    }

    /**
     * Convert an abandoned command attempt to review without dispatching it again.
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
                    || $lockedExecution->type !== Reward::TYPE_SERVER_COMMAND
                    || $lockedExecution->status !== RewardExecution::STATUS_PROCESSING
                    || ($lockedExecution->started_at !== null && $lockedExecution->started_at->gt($cutoff))) {
                    return false;
                }

                $this->finishLocked(
                    $lockedExecution,
                    RewardExecution::STATUS_UNCERTAIN,
                    'The server command attempt was interrupted and will not be retried automatically.',
                );
                $this->redemptionStatuses->refreshLocked($lockedRedemption);

                return true;
            }, 3);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * Claim the next external reward for a redemption.
     *
     * @return array{server_id: int, server_type: string, user_id: int, command: string, require_online: bool}|null
     */
    private function claim(RewardExecution $execution): ?array
    {
        return DB::transaction(function () use ($execution) {
            $lockedRedemption = Redemption::query()->lockForUpdate()->find($execution->redemption_id);
            $lockedExecution = $lockedRedemption?->executions()
                ->whereKey($execution->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedRedemption === null
                || $lockedExecution === null
                || $lockedExecution->type !== Reward::TYPE_SERVER_COMMAND
                || $lockedExecution->status !== RewardExecution::STATUS_PENDING) {
                return null;
            }

            if ($lockedExecution->attempts !== 0) {
                $this->finishLocked(
                    $lockedExecution,
                    RewardExecution::STATUS_UNCERTAIN,
                    'The server command already has a recorded attempt and will not be dispatched again.',
                );
                $this->redemptionStatuses->refreshLocked($lockedRedemption);

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

            try {
                $serverId = filter_var(data_get($lockedExecution->configuration, 'server_id'), FILTER_VALIDATE_INT);
                $serverType = data_get($lockedExecution->configuration, 'server_type');
                $command = $this->validatedCommand(data_get($lockedExecution->configuration, 'command'));
                $requireOnline = $this->validatedBoolean(
                    data_get($lockedExecution->configuration, 'require_online', false)
                );
                $recipient = User::query()->registered()->find($lockedRedemption->user_id);
                $server = $serverId === false ? null : $this->servers->find((int) $serverId);

                if ($serverId === false
                    || ! is_string($serverType)
                    || $requireOnline === null
                    || $recipient === null
                    || $server === null
                    || $server->type !== $serverType
                    || ($requireOnline && ! $this->servers->supportsOnlineRequirement($server))
                    || ! $server->bridge()->canExecuteCommand()
                    || $lockedExecution->external_reference !== $this->externalReference($lockedExecution, (int) $serverId)) {
                    throw new UnexpectedValueException('The prepared server command no longer matches its execution.');
                }
            } catch (Throwable $exception) {
                $this->finishLocked(
                    $lockedExecution,
                    RewardExecution::STATUS_FAILED,
                    $this->errorMessage($exception),
                );
                $this->redemptionStatuses->refreshLocked($lockedRedemption);
                report($exception);

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
                'server_id' => (int) $serverId,
                'server_type' => $serverType,
                'user_id' => (int) $recipient->getKey(),
                'command' => $command,
                'require_online' => $requireOnline,
            ];
        }, 3);
    }

    /**
     * Persist a successful return from the server bridge.
     */
    private function markDispatched(RewardExecution $execution): void
    {
        DB::transaction(function () use ($execution) {
            $lockedRedemption = Redemption::query()->lockForUpdate()->find($execution->redemption_id);
            $lockedExecution = $lockedRedemption?->executions()
                ->whereKey($execution->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedRedemption === null
                || $lockedExecution === null
                || $lockedExecution->status !== RewardExecution::STATUS_PROCESSING) {
                return;
            }

            $lockedExecution->forceFill([
                'status' => RewardExecution::STATUS_DISPATCHED,
                'error' => null,
                'finished_at' => now(),
            ])->save();
            $this->redemptionStatuses->refreshLocked($lockedRedemption);
        }, 3);
    }

    /**
     * Persist a known pre-call failure or an uncertain external attempt.
     */
    private function finish(RewardExecution $execution, string $status, Throwable $exception): void
    {
        try {
            DB::transaction(function () use ($execution, $status, $exception) {
                $lockedRedemption = Redemption::query()->lockForUpdate()->find($execution->redemption_id);
                $lockedExecution = $lockedRedemption?->executions()
                    ->whereKey($execution->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($lockedRedemption === null
                    || $lockedExecution === null
                    || $lockedExecution->status !== RewardExecution::STATUS_PROCESSING) {
                    return;
                }

                $this->finishLocked($lockedExecution, $status, $this->errorMessage($exception));
                $this->redemptionStatuses->refreshLocked($lockedRedemption);
            }, 3);
        } catch (Throwable $finishException) {
            report($finishException);
        }
    }

    private function finishLocked(RewardExecution $execution, string $status, string $error): void
    {
        $execution->forceFill([
            'status' => $status,
            'error' => Str::limit($error, 2000, ''),
            'finished_at' => now(),
        ])->save();
    }

    /**
     * Validate the administrative command template before rendering it.
     */
    private function validatedTemplate(mixed $command): string
    {
        $command = $this->validatedCommand($command);

        if (preg_match_all('/\{([A-Za-z][A-Za-z0-9_]*)\}/', $command, $matches) === false) {
            throw new UnexpectedValueException('The server command placeholders are invalid.');
        }

        $unsupported = collect($matches[1] ?? [])
            ->reject(fn (string $placeholder) => in_array($placeholder, ['player', 'name'], true));

        if ($unsupported->isNotEmpty()) {
            throw new UnexpectedValueException('The server command contains an unsupported placeholder.');
        }

        return $command;
    }

    /**
     * Render the only portable user placeholders before crossing the bridge boundary.
     */
    private function renderCommand(string $command, User $recipient): string
    {
        if (preg_match('/\{(?:player|name)\}/', $command) === 1
            && preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_.-]{0,63}\z/D', $recipient->name) !== 1) {
            throw new UnexpectedValueException('The account name is unsafe for a server command placeholder.');
        }

        return $this->validatedCommand(str_replace(
            ['{player}', '{name}'],
            $recipient->name,
            $command,
        ));
    }

    /**
     * Validate a single command and reject all command-separator controls.
     */
    private function validatedCommand(mixed $command): string
    {
        if (! is_string($command) || preg_match('/[\x00-\x1F\x7F]/', $command) === 1) {
            throw new UnexpectedValueException('The server command contains invalid characters.');
        }

        $command = trim($command);

        if ($command === '' || Str::length($command) > 4096 || str_starts_with($command, '/')) {
            throw new UnexpectedValueException('The server command is empty, too long or starts with a slash.');
        }

        return $command;
    }

    private function validatedBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }

    private function externalReference(RewardExecution $execution, int $serverId): string
    {
        return 'server-command:'.$execution->getKey().':server:'.$serverId;
    }

    private function errorMessage(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        return class_basename($exception).($message === '' ? '' : ': '.$message);
    }
}
