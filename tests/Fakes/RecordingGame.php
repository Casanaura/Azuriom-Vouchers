<?php

namespace Azuriom\Plugin\Vouchers\Tests\Fakes;

use Azuriom\Games\Game;
use Azuriom\Models\User;

class RecordingGame extends Game
{
    public function id(): string
    {
        return 'vouchers-test';
    }

    public function name(): string
    {
        return 'Vouchers Test';
    }

    public function getAvatarUrl(User $user, int $size = 64): string
    {
        return '';
    }

    public function getUserUniqueId(string $name): ?string
    {
        return $name;
    }

    public function getUserName(User $user): ?string
    {
        return $user->name;
    }

    public function getSupportedServers(): array
    {
        return [
            'recording-server' => RecordingServerBridge::class,
            'mc-azlink' => RecordingServerBridge::class,
        ];
    }
}
