<?php

declare(strict_types=1);

namespace Grav\Plugin\Mambers;

use Grav\Common\Grav;
use Grav\Common\Utils;

final class MudMambersCsrf
{
    public const ACTION = 'mambers-profile-write';

    public static function token(Grav $grav): string
    {
        return Utils::getNonce(self::ACTION);
    }

    public static function verify(Grav $grav, ?string $nonce): bool
    {
        $nonce = trim((string) $nonce);
        if ($nonce === '') {
            return false;
        }

        return Utils::verifyNonce($nonce, self::ACTION);
    }

    public static function assertValid(Grav $grav, ?string $nonce): void
    {
        if (!self::verify($grav, $nonce)) {
            throw new \RuntimeException('Invalid or missing security token.');
        }
    }
}
