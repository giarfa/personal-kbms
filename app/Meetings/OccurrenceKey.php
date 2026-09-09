<?php

namespace App\Meetings;

/**
 * Addresses a calendar occurrence by its feed-carried natural key
 * (`source_uid`, `recurrence_id`) rather than a surrogate row id a resync can change.
 */
final readonly class OccurrenceKey
{
    public function __construct(public string $sourceUid, public string $recurrenceId = '') {}

    /**
     * Encode as a single URL-safe route segment: base64url(sourceUid)[.base64url(recurrenceId)].
     */
    public function toRouteKey(): string
    {
        $key = self::encode($this->sourceUid);

        if ($this->recurrenceId !== '') {
            $key .= '.'.self::encode($this->recurrenceId);
        }

        return $key;
    }

    /**
     * Decode a route segment produced by toRouteKey(). Returns null on anything malformed.
     */
    public static function fromRouteKey(string $key): ?self
    {
        if ($key === '') {
            return null;
        }

        [$sourceUidPart, $recurrenceIdPart] = array_pad(explode('.', $key, 2), 2, null);

        $sourceUid = self::decode($sourceUidPart);

        if ($sourceUid === null || $sourceUid === '') {
            return null;
        }

        if ($recurrenceIdPart === null) {
            return new self($sourceUid);
        }

        $recurrenceId = self::decode($recurrenceIdPart);

        if ($recurrenceId === null) {
            return null;
        }

        return new self($sourceUid, $recurrenceId);
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decode(string $value): ?string
    {
        $padded = str_pad($value, strlen($value) + (4 - strlen($value) % 4) % 4, '=');

        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
