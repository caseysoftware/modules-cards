<?php
/**
 * Date math of scheduled copies (no database needed):
 *
 *     php tests/schedule_test.php
 */
require_once __DIR__ . '/../includes/CardSchedules.php';

$failures = 0;
function check($ok, $message)
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $message . "\n";
    if (!$ok) {
        $failures++;
    }
}

$S = 'CCardSchedules';
$next = function ($rule, $days, $from, $anchor = null) use ($S) {
    return $S::nextOn($rule, $S::cleanDays($rule, $days), $from, $anchor);
};

echo "== weekly\n";
// 2026-11-02 is a Monday.
check($next('weekly', array(1), '2026-11-02') === '2026-11-02', 'a Monday schedule falls on the Monday it starts');
check($next('weekly', array(1), '2026-11-03') === '2026-11-09', 'from Tuesday, the next Monday');
check($next('weekly', '1,4', '2026-11-03') === '2026-11-05', 'Monday and Thursday: from Tuesday, Thursday');
check($next('weekly', array(7), '2026-12-28') === '2027-01-03', 'Sunday across the new year');
check($S::cleanDays('weekly', array(9, 0)) === null, 'no valid weekday is refused');
check($S::cleanDays('weekly', array(4, 1, 1)) === array(1, 4), 'weekdays are sorted and deduplicated');

echo "== monthly\n";
check($next('monthly', 1, '2026-11-01') === '2026-11-01', 'the 1st, starting on the 1st');
check($next('monthly', 1, '2026-11-02') === '2026-12-01', 'the 1st, starting on the 2nd: next month');
check($next('monthly', 31, '2026-11-05') === '2026-11-30', 'the 31st in a 30-day month is its last day');
check($next('monthly', 31, '2027-02-01') === '2027-02-28', 'the 31st in February is the 28th');
check($next('monthly', 30, '2028-02-01') === '2028-02-29', 'the 30th in a leap February is the 29th');
check($next('monthly', 15, '2026-12-16') === '2027-01-15', 'the 15th, across the new year');
check($S::cleanDays('monthly', 32) === null, 'day 32 is refused');

echo "== every N days\n";
check($next('days', 14, '2026-11-02', '2026-11-02') === '2026-11-02', 'starts on its first date');
check($next('days', 14, '2026-11-03', '2026-11-02') === '2026-11-16', 'then every 14 days from it');
check($next('days', 14, '2026-11-16', '2026-11-02') === '2026-11-16', 'on a cadence date itself');
check($next('days', 3, '2026-12-30', '2026-11-02') === '2027-01-01', 'keeps the cadence over many periods');
check($S::cleanDays('days', 0) === null && $S::cleanDays('days', 366) === null, 'an interval of 0 or 366 days is refused');

echo "== summaries\n";
check($S::summary('weekly', array(1)) === 'Every Monday', 'Every Monday');
check($S::summary('weekly', array(1, 4)) === 'Every Monday and Thursday', 'Every Monday and Thursday');
check($S::summary('weekly', array(1, 3, 5)) === 'Every Monday, Wednesday and Friday', 'three days');
check($S::summary('weekly', array(1, 2, 3, 4, 5)) === 'Every weekday', 'Every weekday');
check($S::summary('monthly', 1) === 'Monthly on the 1st', 'Monthly on the 1st');
check($S::summary('monthly', 22) === 'Monthly on the 22nd', '22nd');
check($S::summary('monthly', 11) === 'Monthly on the 11th', '11th');
check(strpos($S::summary('monthly', 31), 'last day') !== false, 'the 31st mentions shorter months');
check($S::summary('days', 14) === 'Every 14 days', 'Every 14 days');

echo "== copy names\n";
check($S::copyName('{name} – {date}', 'Weekly list', '2026-11-09') === 'Weekly list – Nov 9', '{name} – {date}');
check($S::copyName('Week {week} of {year}: {name}', 'Plan', '2026-11-09') === 'Week 46 of 2026: Plan', '{week} and {year}');
check($S::copyName('{month} close', 'x', '2026-12-01') === 'December close', '{month}');
check($S::copyName('{name} {iso}', 'x', '2026-12-01') === 'x 2026-12-01', '{iso}');
check($S::copyName('', 'Same name', '2026-12-01') === 'Same name', 'an empty pattern keeps the name');
check(strlen($S::copyName(str_repeat('a', 300), 'x', '2026-12-01')) === 255, 'names are cut to 255 characters');

echo "\n" . ($failures ? $failures . ' failure(s)' : 'all schedule date checks passed') . "\n";
exit($failures ? 1 : 0);
