<?php

declare(strict_types=1);

namespace App\Support\Catalogue;

use App\Exceptions\OrderTypesOverlapException;
use RuntimeException;

/**
 * LAUNCH packaging add-on — the "Used for" ticks of a stock line (owner
 * decision 1, tester call 2): one smallint bit mask per line.
 *
 *   1 dine in · 2 quick order · 4 to go · 8 delivery; 15 = every type.
 *
 * QR table and staff rounds are dine in, QR quick orders are quick; `car`
 * counts as to go. A line with no tick (0) is refused: a line used for
 * nothing should be deleted. Two lines of the same item on one product (or
 * one add-on option and direction) may exist only when their ticks do not
 * overlap (tester call 3: napkin ×1 dine in, napkin ×3 to go + delivery).
 *
 * Reading a copy (an order line's frozen JSON): a missing `order_types`
 * means every type — old copies are never filtered.
 */
final class OrderTypes
{
    public const DINE_IN = 1;

    public const QUICK = 2;

    public const TO_GO = 4;

    public const DELIVERY = 8;

    public const ALL = 15;

    /** The four buckets, in the portal's order, with their bit. */
    public const BUCKETS = [
        'dine_in' => self::DINE_IN,
        'quick' => self::QUICK,
        'to_go' => self::TO_GO,
        'delivery' => self::DELIVERY,
    ];

    /** The bucket an order type falls in (car → to go); null = unknown (no filter). */
    public static function bucket(?string $orderType): ?string
    {
        return match ($orderType) {
            'dine_in', 'quick', 'to_go', 'delivery' => $orderType,
            'car' => 'to_go',
            default => null,
        };
    }

    /** The bit of an order type (via its bucket); null = unknown. */
    public static function bit(?string $orderType): ?int
    {
        $bucket = self::bucket($orderType);

        return $bucket === null ? null : self::BUCKETS[$bucket];
    }

    /** Whether a mask is a valid tick set (1..15: at least one tick). */
    public static function valid(mixed $mask): bool
    {
        return is_int($mask) && $mask >= 1 && $mask <= self::ALL;
    }

    /**
     * A stored or copied mask: absent / malformed reads as every type (old
     * rows and copies are never filtered).
     */
    public static function read(mixed $mask): int
    {
        if ((is_string($mask) && ctype_digit($mask)) || (is_float($mask) && floor($mask) === $mask)) {
            $mask = (int) $mask;
        }

        return self::valid($mask) ? $mask : self::ALL;
    }

    /** Whether a line with this mask is taken for an order of this bit (null bit = no filter). */
    public static function includes(mixed $mask, ?int $bit): bool
    {
        return $bit === null || (self::read($mask) & $bit) !== 0;
    }

    /** "Dine in, To go" — for audit rows and messages. */
    public static function label(int $mask): string
    {
        if ($mask === self::ALL) {
            return 'All';
        }
        $names = ['dine_in' => 'Dine in', 'quick' => 'Quick order', 'to_go' => 'To go', 'delivery' => 'Delivery'];
        $out = [];
        foreach (self::BUCKETS as $bucket => $bit) {
            if (($mask & $bit) !== 0) {
                $out[] = $names[$bucket];
            }
        }

        return implode(', ', $out);
    }

    /** The same in Arabic ("داخل المحل، سفري"). */
    public static function labelAr(int $mask): string
    {
        if ($mask === self::ALL) {
            return 'الكل';
        }
        $names = ['dine_in' => 'داخل المحل', 'quick' => 'طلب سريع', 'to_go' => 'سفري', 'delivery' => 'توصيل'];
        $out = [];
        foreach (self::BUCKETS as $bucket => $bit) {
            if (($mask & $bit) !== 0) {
                $out[] = $names[$bucket];
            }
        }

        return implode('، ', $out);
    }

    /**
     * The no-overlap rule (tester call 3): lines sharing a key (the item, and
     * for add-on lines the direction) must have disjoint ticks.
     *
     * @param  list<array{key: string, mask: int, name: string}>  $lines
     *
     * @throws OrderTypesOverlapException naming the item (a 422 in English and Arabic)
     */
    public static function assertNoOverlap(array $lines): void
    {
        $seen = [];
        foreach ($lines as $line) {
            $used = $seen[$line['key']] ?? 0;
            if (($used & $line['mask']) !== 0) {
                throw new OrderTypesOverlapException(
                    $line['name'],
                    self::label($used & $line['mask']),
                    self::labelAr($used & $line['mask']),
                );
            }
            $seen[$line['key']] = $used | $line['mask'];
        }
    }

    /**
     * The mask a saved line gets. A payload line WITHOUT `order_types` (an
     * old portal tab) keeps the stored mask of that item when exactly one
     * stored line holds it, else every type — a stale tab never re-ticks a
     * line silently.
     *
     * @param  array<string, list<int>>  $storedByKey  key => the stored masks of that key
     */
    public static function resolve(mixed $sent, string $key, array $storedByKey): int
    {
        if ($sent !== null) {
            if (is_string($sent) && ctype_digit($sent)) {
                $sent = (int) $sent;
            }
            if (! self::valid($sent)) {
                throw new RuntimeException('Tick at least one order type (Dine in, Quick order, To go or Delivery) for every line.');
            }

            return $sent;
        }
        $stored = $storedByKey[$key] ?? [];

        return count($stored) === 1 ? $stored[0] : self::ALL;
    }
}
