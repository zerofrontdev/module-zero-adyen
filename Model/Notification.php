<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Adyen\Model;

/**
 * An Adyen standard webhook item's HMAC signature
 * (additionalData.hmacSignature): base64 of HMAC-SHA256, keyed with the
 * webhook's hex HMAC key, over
 * pspReference:originalReference:merchantAccountCode:merchantReference:value:currency:eventCode:success.
 */
class Notification
{
    public static function isValid(array $item, string $hexKey): bool
    {
        $additional = is_array($item['additionalData'] ?? null) ? $item['additionalData'] : [];
        $signature = is_scalar($additional['hmacSignature'] ?? null) ? (string) $additional['hmacSignature'] : '';
        if ($signature === '' || $hexKey === '' || !ctype_xdigit($hexKey)) {
            return false;
        }

        return hash_equals(self::sign($item, $hexKey), $signature);
    }

    public static function sign(array $item, string $hexKey): string
    {
        $amount = is_array($item['amount'] ?? null) ? $item['amount'] : [];
        // A field sent as an array signs as '' (so the signature can't match), never a 500.
        $payload = implode(':', array_map(static fn ($value) => is_array($value) ? '' : $value, [
            $item['pspReference'] ?? '',
            $item['originalReference'] ?? '',
            $item['merchantAccountCode'] ?? '',
            $item['merchantReference'] ?? '',
            $amount['value'] ?? '',
            $amount['currency'] ?? '',
            $item['eventCode'] ?? '',
            $item['success'] ?? '',
        ]));

        return base64_encode(hash_hmac('sha256', $payload, (string) hex2bin($hexKey), true));
    }
}
