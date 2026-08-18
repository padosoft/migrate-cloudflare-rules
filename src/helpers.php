<?php

/*
|--------------------------------------------------------------------------
| Array helpers
|--------------------------------------------------------------------------
|
| These functions are copied verbatim from padosoft/support
| (https://github.com/padosoft/support/blob/master/src/array.php and
| src/helpers.php, src/string.php) so that this package does not need to
| depend on it. They are declared inside the package namespace to avoid any
| clash with the global functions defined by padosoft/support when both
| packages are installed in the same application.
|
*/

namespace Padosoft\MigrateCloudflareRules;

use ArrayAccess;
use Closure;

/**
 * Return the default value of the given value.
 *
 * @param  mixed  $value
 * @return mixed
 */
function valueEx($value)
{
    return $value instanceof Closure ? $value() : $value;
}

/**
 * Return true if $subject is null or the string representation(cast to string) of $subject is an empty string ('').
 *
 * @param  bool  $withTrim  if set to true (default) and $subject is a scalar then check if trim($subject)!='' too.
 */
function isNullOrEmpty($subject, bool $withTrim = true): bool
{
    return $subject === null || $subject === '' || ($withTrim === true && is_scalar($subject) && trim($subject) == '');
}

/**
 * Check if array is null or empty.
 */
function isNullOrEmptyArray($array): bool
{
    return $array === null || ! is_array($array) || count($array) < 1;
}

/**
 * Check if a key exists in an array. Return false if $array is null or empty or $key is null or empty.
 */
function array_key_exists_safe(array $array, string $key): bool
{
    if (isNullOrEmptyArray($array) || isNullOrEmpty($key)) {
        return false;
    }

    return array_key_exists($key, $array);
}

/**
 * Determine whether the given value is array accessible.
 * See: https://github.com/illuminate/support/blob/master/Arr.php
 *
 * @param  mixed  $value
 * @return bool
 */
function array_accessibleEx($value)
{
    return is_array($value) || $value instanceof ArrayAccess;
}

/**
 * Get an item from an array using "dot" notation.
 *
 * @param  array  $array
 * @param  string  $key
 * @param  mixed  $default
 * @return mixed
 */
function array_getEx($array, $key, $default = null)
{
    if (! array_accessibleEx($array)) {
        return valueEx($default);
    }

    if (is_null($key)) {
        return $array;
    }

    if (array_key_exists_safe($array, $key)) {
        return $array[$key];
    }

    if (strpos($key, '.') === false) {
        return $array[$key] ?? valueEx($default);
    }

    foreach (explode('.', $key) as $segment) {
        if (! array_accessibleEx($array) || ! array_key_exists_safe($array, $segment)) {
            return valueEx($default);
        }
        $array = $array[$segment];
    }

    return $array;
}
