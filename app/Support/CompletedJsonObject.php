<?php

namespace App\Support;

/** Repair one missing array closer in an otherwise completed JSON object. */
final class CompletedJsonObject
{
    public static function decode(string $json): ?array
    {
        $stack = [];
        $quoted = false;
        $escaped = false;
        $fixed = '';
        $repairs = 0;

        for ($i = 0, $length = strlen($json); $i < $length; $i++) {
            $char = $json[$i];
            if ($quoted) {
                $fixed .= $char;
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $quoted = false;
                }
                continue;
            }

            if ($char === '"') {
                $quoted = true;
            } elseif ($char === '{' || $char === '[') {
                $stack[] = $char;
            } elseif ($char === '}' || $char === ']') {
                if ($char === '}' && end($stack) === '[' && count($stack) > 1
                    && $stack[count($stack) - 2] === '{') {
                    array_pop($stack);
                    $fixed .= ']';
                    $repairs++;
                }
                if (array_pop($stack) !== ($char === '}' ? '{' : '[')) {
                    return null;
                }
            }
            $fixed .= $char;
        }

        if ($quoted || $stack !== [] || $repairs !== 1) {
            return null;
        }
        $decoded = json_decode($fixed, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) && ! array_is_list($decoded)
            ? $decoded : null;
    }
}
