<?php

namespace Azuriom\Plugin\Vouchers\Services;

use Azuriom\Plugin\Vouchers\Models\Voucher;
use RuntimeException;

class VoucherCodeGenerator
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * Generate a readable and unique voucher code.
     */
    public function generate(int $groups = 4, int $charactersPerGroup = 4): string
    {
        if ($groups < 1 || $groups > 8 || $charactersPerGroup < 2 || $charactersPerGroup > 8) {
            throw new RuntimeException('Invalid voucher code dimensions.');
        }

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = collect(range(1, $groups))
                ->map(fn () => $this->randomGroup($charactersPerGroup))
                ->implode('-');

            if (! Voucher::query()->whereCode($code)->exists()) {
                return $code;
            }
        }

        throw new RuntimeException('Unable to generate a unique voucher code.');
    }

    /**
     * Generate one group without ambiguous characters such as 0, O, 1 or I.
     */
    private function randomGroup(int $length): string
    {
        $maxIndex = strlen(self::ALPHABET) - 1;
        $group = '';

        for ($index = 0; $index < $length; $index++) {
            $group .= self::ALPHABET[random_int(0, $maxIndex)];
        }

        return $group;
    }
}
