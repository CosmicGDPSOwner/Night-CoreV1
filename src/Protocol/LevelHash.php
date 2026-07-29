<?php

declare(strict_types=1);

namespace NightCore\Protocol;

final class LevelHash
{
    private const SALT = 'xI25fpAapCQg';

    /** @param list<array{levelID:int,stars:int,coins:int}> $levels */
    public static function multi(array $levels): string
    {
        $source = '';
        foreach ($levels as $level) {
            $id = (string) $level['levelID'];
            if ($id === '') {
                continue;
            }
            $source .= $id[0] . $id[strlen($id) - 1] . $level['stars'] . $level['coins'];
        }
        return sha1($source . self::SALT);
    }

    public static function solo(string $levelString): string
    {
        $length = strlen($levelString);
        if ($length < 41) {
            return sha1($levelString . self::SALT);
        }

        $step = intdiv($length, 40);
        $sample = '';
        for ($i = 0; $i < 40; $i++) {
            $sample .= $levelString[$i * $step];
        }
        return sha1($sample . self::SALT);
    }

    public static function solo2(string $value): string
    {
        return sha1($value . self::SALT);
    }
}
