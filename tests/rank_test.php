<?php
/**
 * Randomized check of CCardRank: run with `php tests/rank_test.php`.
 *
 * Inserts thousands of ranks at random positions (including repeatedly at
 * the same spot) and checks the list stays strictly ordered and every rank
 * stays valid.
 */
require __DIR__ . '/../includes/CardRank.php';

$failures = 0;
$check = function ($ok, $message) use (&$failures) {
    if (!$ok) {
        $failures++;
        echo "FAIL: $message\n";
    }
};

mt_srand(42);
$ranks = array();
for ($i = 0; $i < 5000; $i++) {
    $pos = mt_rand(0, count($ranks));
    $before = ($pos > 0) ? $ranks[$pos - 1] : null;
    $after = ($pos < count($ranks)) ? $ranks[$pos] : null;
    $rank = CCardRank::between($before, $after);
    $check(CCardRank::isValid($rank), "invalid rank '$rank'");
    $check(null === $before || strcmp($before, $rank) < 0, "'$rank' not after '$before'");
    $check(null === $after || strcmp($rank, $after) < 0, "'$rank' not before '$after'");
    array_splice($ranks, $pos, 0, array($rank));
}

// Hammer one spot: always insert right after the first element.
$a = CCardRank::between(null, null);
$b = CCardRank::between($a, null);
$longest = 0;
for ($i = 0; $i < 500; $i++) {
    $mid = CCardRank::between($a, $b);
    $check(strcmp($a, $mid) < 0 && strcmp($mid, $b) < 0, "squeeze failed between '$a' and '$b'");
    $b = $mid;
    $longest = max($longest, strlen($mid));
}

// Out-of-order or equal neighbours fall back to "after $before".
$x = CCardRank::between('V', 'V');
$check(strcmp('V', $x) < 0, "equal neighbours: '$x'");
$y = CCardRank::between('k', 'V');
$check(strcmp('k', $y) < 0, "reversed neighbours: '$y'");

echo $failures ? "$failures failure(s)\n" : "ok: 5000 random inserts, 500 squeezes (longest rank $longest chars)\n";
exit($failures ? 1 : 0);
