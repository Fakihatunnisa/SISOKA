<?php

namespace App\Support;

use InvalidArgumentException;

class DecimalInput
{
    /** Preserve legacy grouped amounts while treating leading-zero fractions as decimals. */
    public static function parse(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_scalar($value) || is_bool($value)) {
            throw new InvalidArgumentException('Invalid number.');
        }
        if (is_int($value) || is_float($value)) {
            if (! is_finite((float) $value)) {
                throw new InvalidArgumentException('Number is out of range.');
            }

            return (float) $value;
        }

        $value = trim((string) $value);
        $value = preg_replace('/^Rp\s*/i', '', $value);
        $value = preg_replace('/\s*%$/', '', $value);
        if (! preg_match('/^-?\d+(?:[.,]\d+)*$/D', $value)) {
            throw new InvalidArgumentException('Invalid number.');
        }

        if (str_contains($value, ',') && str_contains($value, '.')) {
            $decimal = strrpos($value, ',') > strrpos($value, '.') ? ',' : '.';
            $group = $decimal === ',' ? '.' : ',';
            $pattern = '/^-?\d{1,3}(?:'.preg_quote($group, '/').'\d{3})+'.preg_quote($decimal, '/').'\d+$/D';
            if (! preg_match($pattern, $value)) {
                throw new InvalidArgumentException('Invalid number grouping.');
            }
            $value = str_replace($group, '', $value);
        } elseif (substr_count($value, ',') > 1 || substr_count($value, '.') > 1) {
            if (! preg_match('/^-?\d{1,3}([.,])\d{3}(?:\1\d{3})+$/D', $value)) {
                throw new InvalidArgumentException('Invalid number grouping.');
            }
            $value = str_replace([',', '.'], '', $value);
        } elseif (preg_match('/^-?[1-9]\d{0,2}[.,]\d{3}$/D', $value)) {
            // Existing imports use 13.172 and 13,172 to mean 13172.
            $value = str_replace([',', '.'], '', $value);
        }

        $number = (float) str_replace(',', '.', $value);
        if (! is_finite($number)) {
            throw new InvalidArgumentException('Number is out of range.');
        }

        return $number;
    }
}
