<?php

namespace Azuriom\Plugin\Vouchers\Services;

use Azuriom\Models\Server;
use Illuminate\Support\Collection;
use UnexpectedValueException;

class ServerCommandCatalog
{
    /**
     * List the servers whose configured bridge can execute commands.
     *
     * @return Collection<int, Server>
     */
    public function servers(): Collection
    {
        return Server::query()
            ->executable()
            ->orderBy('name')
            ->get(['id', 'name', 'type']);
    }

    /**
     * Return the executable server IDs from a submitted set.
     *
     * @param iterable<int, mixed> $ids
     * @return Collection<int, int>
     */
    public function eligibleIds(iterable $ids): Collection
    {
        $ids = collect($ids)
            ->filter(fn (mixed $id) => filter_var($id, FILTER_VALIDATE_INT) !== false && (int) $id > 0)
            ->map(fn (mixed $id) => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Server::query()
            ->executable()
            ->whereKey($ids)
            ->pluck('id')
            ->map(fn (mixed $id) => (int) $id);
    }

    /**
     * Resolve an executable server by its immutable identifier.
     */
    public function find(int $serverId): ?Server
    {
        if ($serverId < 1) {
            return null;
        }

        return Server::query()->executable()->find($serverId);
    }

    /**
     * Build the immutable configuration snapshot for a command reward.
     *
     * @return array{server_id: int, server_name: string, server_type: string, command: string, require_online: bool}
     */
    public function configuration(int $serverId, string $command, bool $requireOnline): array
    {
        $server = $this->find($serverId);

        if ($server === null) {
            throw new UnexpectedValueException('The selected server cannot execute commands.');
        }

        if ($requireOnline && ! $this->supportsOnlineRequirement($server)) {
            throw new UnexpectedValueException('Only AzLink servers can wait for the recipient to be online.');
        }

        return [
            'server_id' => (int) $server->getKey(),
            'server_name' => $server->name,
            'server_type' => $server->type,
            'command' => $command,
            'require_online' => $requireOnline,
        ];
    }

    /**
     * Determine whether the bridge can defer commands until the user is online.
     */
    public function supportsOnlineRequirement(Server $server): bool
    {
        return in_array($server->type, ['mc-azlink', 'steam-azlink'], true);
    }
}
