<?php

declare(strict_types=1);

namespace NightCore\Security;

use RuntimeException;

final class PasswordService
{
    private const GJP2_SALT = 'mI29fmAnxgTs';

    public function hashPassword(string $password): string
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hash === false) {
            throw new RuntimeException('Unable to hash password.');
        }
        return $hash;
    }

    public function verifyPassword(string $password, string $hash): bool
    {
        return $hash !== '' && password_verify($password, $hash);
    }

    public function passwordNeedsRehash(string $hash): bool
    {
        return $hash !== '' && password_needs_rehash($hash, PASSWORD_DEFAULT);
    }

    public function gjp2FromPassword(string $password): string
    {
        return sha1($password . self::GJP2_SALT);
    }

    public function hashGjp2FromPassword(string $password): string
    {
        return $this->hashGjp2($this->gjp2FromPassword($password));
    }

    public function hashGjp2(string $gjp2): string
    {
        $hash = password_hash($gjp2, PASSWORD_DEFAULT);
        if ($hash === false) {
            throw new RuntimeException('Unable to hash GJP2.');
        }
        return $hash;
    }

    public function verifyGjp2(string $gjp2, string $hash): bool
    {
        return $hash !== '' && password_verify($gjp2, $hash);
    }
}
