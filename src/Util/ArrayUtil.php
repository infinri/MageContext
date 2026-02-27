<?php

declare(strict_types=1);

namespace MageContext\Util;

/**
 * Shared array utility methods used across the codebase.
 */
class ArrayUtil
{
    /**
     * Check if an array is associative (string keys) vs sequential (integer keys).
     */
    public static function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
