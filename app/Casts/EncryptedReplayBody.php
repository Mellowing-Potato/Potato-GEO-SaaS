<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/** Keep existing JSON consumers unchanged while protecting cached credentials. */
final class EncryptedReplayBody implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        // Deployments may read pre-migration JSON. New writes are always encrypted.
        if (is_array(json_decode($value, true))) {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            // Do not discard the reservation or include ciphertext/keys in errors.
            throw new ConflictHttpException('幂等回执无法读取；请核对原请求记录，系统不会重复执行。');
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return self::encode($value);
    }

    public static function encode(?string $value): ?string
    {
        return $value === null ? null : Crypt::encryptString($value);
    }
}
