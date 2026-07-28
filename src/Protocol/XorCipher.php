<?php

declare(strict_types=1);

namespace NightCore\Protocol;

final class XorCipher
{
    public static function apply(string $input, string $key): string
    {
        if ($key === '') {
            return $input;
        }

        $output = '';
        $keyLength = strlen($key);
        $inputLength = strlen($input);

        for ($i = 0; $i < $inputLength; $i++) {
            $output .= chr(ord($input[$i]) ^ ord($key[$i % $keyLength]));
        }

        return $output;
    }

    public static function decodeGjp(string $gjp): ?string
    {
        $normalized = str_replace(['_', '-'], ['/', '+'], $gjp);
        $decoded = base64_decode($normalized, true);
        if ($decoded === false) {
            return null;
        }

        return self::apply($decoded, '37526');
    }
}
