<?php

namespace Azuriom\Plugin\Vouchers\Tests\Fakes;

use Azuriom\Games\ServerBridge;
use Azuriom\Models\User;
use RuntimeException;

class RecordingServerBridge extends ServerBridge
{
    /** @var array<int, array{commands: array<int, string>, user_id: int, username: string, require_online: bool}> */
    public static array $calls = [];

    public static bool $throwAfterRecording = false;

    public static function reset(): void
    {
        self::$calls = [];
        self::$throwAfterRecording = false;
    }

    public function getServerData(): ?array
    {
        return null;
    }

    public function verifyLink(): bool
    {
        return true;
    }

    public function sendCommands(array $commands, User $user, bool $needConnected = false): void
    {
        self::$calls[] = [
            'commands' => array_values($commands),
            'user_id' => (int) $user->getKey(),
            'username' => $user->name,
            'require_online' => $needConnected,
        ];

        if (self::$throwAfterRecording) {
            throw new RuntimeException('Simulated failure after crossing the command boundary.');
        }
    }

    public function canExecuteCommand(): bool
    {
        return true;
    }
}
