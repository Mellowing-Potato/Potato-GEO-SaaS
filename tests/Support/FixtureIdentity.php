<?php

namespace Tests\Support;

/** Stable fictional identities shared by fixtures; never generate real phone ranges. */
final class FixtureIdentity
{
    private static array $phones = [];

    public static function phone(string $identity): string
    {
        // Keep dynamic fixtures separate from literal fixture numbers (0001–0028).
        if (! isset(self::$phones[$identity])) {
            self::$phones[$identity] = (string) (13800000029 + count(self::$phones));
        }

        return self::$phones[$identity];
    }
}
