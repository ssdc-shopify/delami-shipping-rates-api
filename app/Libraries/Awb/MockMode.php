<?php

namespace App\Libraries\Awb;

/**
 * Mock AWB mode — the one switch between "invent waybills locally" and "book
 * real shipments and email customers".
 *
 * Set by an operator in Admin → Settings and stored in the `settings` table
 * (CodeIgniter Settings), not in .env: going live is an operational decision,
 * and needing shell access to the server to make it — or to back out of it —
 * was the wrong way round.
 *
 * Every reader goes through here, so the rule that makes it safe lives in one
 * place: only an explicitly stored `false` is live. No row yet (a fresh
 * database), or anything unexpected in it, reads as mock — the fallback is
 * Config\Couriers::$mockAwb, which is true.
 */
final class MockMode
{
    /** The Settings key, i.e. Config\Couriers::$mockAwb. */
    public const SETTING = 'Couriers.mockAwb';

    /**
     * Who last flipped the switch, and when. Kept beside the switch rather
     * than in the log: production logs only errors, and "who turned live
     * mode on" is exactly the question asked after a shipment nobody meant
     * to book.
     */
    private const CHANGED_BY = 'Couriers.mockAwbChangedBy';
    private const CHANGED_AT = 'Couriers.mockAwbChangedAt';

    public static function enabled(): bool
    {
        return setting(self::SETTING) !== false;
    }

    public static function set(bool $enabled, string $changedBy): void
    {
        setting(self::SETTING, $enabled);
        setting(self::CHANGED_BY, $changedBy);
        setting(self::CHANGED_AT, date('Y-m-d H:i:s'));
    }

    /**
     * The last change made through set(), or null when the switch has never
     * been touched and the default is still in force.
     *
     * @return array{by: string, at: string}|null
     */
    public static function lastChange(): ?array
    {
        $at = setting(self::CHANGED_AT);

        if (! is_string($at) || $at === '') {
            return null;
        }

        return ['by' => (string) setting(self::CHANGED_BY), 'at' => $at];
    }
}
