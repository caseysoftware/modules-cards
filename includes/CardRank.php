<?php
/**
 * Fractional ranks for ordering cards.
 *
 * A rank is a string of base-62 digits compared byte by byte (the column is
 * ascii_bin). A card dropped between two others gets a rank between theirs,
 * so a move writes one row instead of renumbering the column. Ranks never
 * end in the lowest digit, which keeps a rank available between any two.
 *
 * Based on the midpoint algorithm of David Greenspan's fractional-indexing.
 *
 * @package web2project\modules\cards
 */
class CCardRank
{
    const DIGITS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /**
     * A rank between $before and $after. Either may be null: null before
     * means "at the start", null after means "at the end".
     *
     * @param string|null $before
     * @param string|null $after
     *
     * @return string
     */
    public static function between($before, $after)
    {
        $before = (null === $before || '' === $before) ? '' : (string) $before;
        $after = (null === $after || '' === $after) ? null : (string) $after;

        if (null !== $after && strcmp($before, $after) >= 0) {
            // Out of order (two cards with the same rank, or a stale page):
            // fall back to "just after $before".
            $after = null;
        }

        return self::midpoint($before, $after);
    }

    /**
     * @param string      $a lower bound ('' for none)
     * @param string|null $b upper bound (null for none)
     *
     * @return string
     */
    protected static function midpoint($a, $b)
    {
        $digits = self::DIGITS;

        if (null !== $b) {
            // Skip the shared prefix, treating a missing digit in $a as '0'.
            $n = 0;
            while ($n < strlen($b) && (($n < strlen($a)) ? $a[$n] : '0') === $b[$n]) {
                $n++;
            }
            if ($n > 0) {
                return substr($b, 0, $n) . self::midpoint(substr($a, $n), substr($b, $n));
            }
        }

        $digitA = ('' !== $a) ? strpos($digits, $a[0]) : 0;
        $digitB = (null !== $b) ? strpos($digits, $b[0]) : strlen($digits);

        if ($digitB - $digitA > 1) {
            return $digits[(int) round(0.5 * ($digitA + $digitB))];
        }

        if (null !== $b && strlen($b) > 1) {
            return $b[0];
        }

        return $digits[$digitA] . self::midpoint(substr($a, 1), null);
    }

    /**
     * Whether a string is a well-formed rank.
     *
     * @param string $rank
     *
     * @return bool
     */
    public static function isValid($rank)
    {
        return is_string($rank) && '' !== $rank && strlen($rank) <= 255
            && strspn($rank, self::DIGITS) === strlen($rank) && '0' !== substr($rank, -1);
    }
}
