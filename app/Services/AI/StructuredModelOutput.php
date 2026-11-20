<?php

namespace App\Services\AI;

/** Validates the structure declared by GEO's model-output schemas. */
final class StructuredModelOutput
{
    public static function matches(mixed $value, array $schema): bool
    {
        if (isset($schema['type'])) {
            $matchesType = match (true) {
                $value instanceof \stdClass => ['object'],
                is_array($value) => ['array'],
                is_string($value) => ['string'],
                is_int($value) => ['integer', 'number'],
                is_float($value) => ['number'],
                is_bool($value) => ['boolean'],
                $value === null => ['null'],
                default => [],
            };
            if (array_intersect((array) $schema['type'], $matchesType) === []) {
                return false;
            }
        }

        if ($value instanceof \stdClass) {
            $properties = $schema['properties'] ?? [];
            $actual = get_object_vars($value);
            if (array_diff($schema['required'] ?? [], array_keys($actual)) !== []) {
                return false;
            }
            foreach ($actual as $name => $item) {
                if (isset($properties[$name])) {
                    if (! self::matches($item, $properties[$name])) {
                        return false;
                    }
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    return false;
                }
            }
        }

        if (is_array($value) && isset($schema['items'])) {
            foreach ($value as $item) {
                if (! self::matches($item, $schema['items'])) {
                    return false;
                }
            }
        }

        return true;
    }
}
