<?php
/**
 * Scheduled copies: a card that repeats is deep-copied (CCardStore::copy)
 * on a schedule, such as every Monday or the 1st of each month.
 *
 * Timing is by date, not time: a schedule is due once its next date has
 * come, and runs the first time the cron sweep (queuescanner.php) or a board
 * page load notices. Missed dates are not caught up: a late run makes one
 * copy, dated the day it runs, and the next date is the first one after
 * that day. Each run claims its schedule in the database first, so two
 * runs at once can't both make a copy.
 *
 * A schedule runs with the rights of the user who last saved it, checked
 * at each run. If that user can no longer see the card or add cards, the
 * schedule pauses and the card's activity log says why.
 *
 * @package web2project\modules\cards
 */
class CCardSchedules
{
    const WEEKLY = 'weekly';
    const MONTHLY = 'monthly';
    const DAYS = 'days';

    /** Default name of a copy. */
    const DEFAULT_NAME = '{name} – {date}';

    /** @var string[] ISO weekday (1 = Monday) => name */
    public static $weekdays = array(1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday');

    // ------------------------------------------------------------ date math

    /**
     * Normalizes a rule's days: weekly => sorted ISO weekdays, monthly => a
     * day of the month (1-31), days => an interval (1-365). Null if invalid.
     *
     * @param string $rule
     * @param mixed  $days
     *
     * @return int[]|int|null
     */
    public static function cleanDays($rule, $days)
    {
        switch ($rule) {
            case self::WEEKLY:
                $list = is_array($days) ? $days : explode(',', (string) $days);
                $list = array_values(array_unique(array_filter(array_map('intval', $list), function ($d) {
                    return $d >= 1 && $d <= 7;
                })));
                sort($list);

                return $list ? $list : null;
            case self::MONTHLY:
                $day = (int) (is_array($days) ? reset($days) : $days);

                return ($day >= 1 && $day <= 31) ? $day : null;
            case self::DAYS:
                $n = (int) (is_array($days) ? reset($days) : $days);

                return ($n >= 1 && $n <= 365) ? $n : null;
        }

        return null;
    }

    /**
     * The first date on or after $from that the rule falls on. For "every N
     * days" the dates count from $anchor; monthly day 29-31 falls on the
     * month's last day in shorter months.
     *
     * @param string        $rule
     * @param int[]|int     $days   from cleanDays()
     * @param string        $from   Y-m-d
     * @param string|null   $anchor Y-m-d, the first date (every N days)
     *
     * @return string Y-m-d
     */
    public static function nextOn($rule, $days, $from, $anchor = null)
    {
        $date = new DateTimeImmutable($from);
        switch ($rule) {
            case self::WEEKLY:
                for ($i = 0; $i < 7; $i++) {
                    $d = $date->modify('+' . $i . ' day');
                    if (in_array((int) $d->format('N'), $days, true)) {
                        return $d->format('Y-m-d');
                    }
                }
                break;
            case self::MONTHLY:
                $month = $date->modify('first day of this month');
                for ($i = 0; $i < 2; $i++) {
                    $m = $month->modify('+' . $i . ' month');
                    $day = min($days, (int) $m->format('t'));
                    $d = $m->setDate((int) $m->format('Y'), (int) $m->format('n'), $day);
                    if ($d >= $date) {
                        return $d->format('Y-m-d');
                    }
                }
                break;
            case self::DAYS:
                $start = new DateTimeImmutable($anchor ? $anchor : $from);
                if ($date <= $start) {
                    return $start->format('Y-m-d');
                }
                $gap = (int) $start->diff($date)->days;
                $steps = (int) ceil($gap / $days);

                return $start->modify('+' . ($steps * $days) . ' day')->format('Y-m-d');
        }

        return $date->format('Y-m-d');
    }

    /**
     * "Every Monday and Thursday", "Monthly on the 1st", "Every 14 days".
     *
     * @param string    $rule
     * @param int[]|int $days
     *
     * @return string
     */
    public static function summary($rule, $days)
    {
        switch ($rule) {
            case self::WEEKLY:
                $names = array();
                foreach ($days as $d) {
                    $names[] = self::$weekdays[$d];
                }
                if (count($names) === 7) {
                    return 'Every day';
                }
                if ($names === array('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday')) {
                    return 'Every weekday';
                }
                $last = array_pop($names);

                return 'Every ' . ($names ? implode(', ', $names) . ' and ' : '') . $last;
            case self::MONTHLY:
                if ($days >= 29) {
                    return 'Monthly on the ' . self::ordinal($days) . ' (or the last day of shorter months)';
                }

                return 'Monthly on the ' . self::ordinal($days);
            case self::DAYS:
                return 1 === $days ? 'Every day' : 'Every ' . $days . ' days';
        }

        return '';
    }

    /**
     * @param int $n
     *
     * @return string
     */
    protected static function ordinal($n)
    {
        $suffix = 'th';
        if (!in_array($n % 100, array(11, 12, 13), true)) {
            $suffix = array(1 => 'st', 2 => 'nd', 3 => 'rd')[$n % 10] ?? 'th';
        }

        return $n . $suffix;
    }

    /**
     * A copy's name: {name} the card's name, {date} "Nov 9", {iso}
     * "2026-11-09", {week} the ISO week number, {month} "November", {year}.
     *
     * @param string $pattern
     * @param string $name
     * @param string $date Y-m-d
     *
     * @return string at most 255 characters
     */
    public static function copyName($pattern, $name, $date)
    {
        $pattern = trim((string) $pattern);
        if ('' === $pattern) {
            return $name;
        }
        $d = new DateTimeImmutable($date);
        $out = strtr($pattern, array(
            '{name}' => $name,
            '{date}' => $d->format('M j'),
            '{iso}' => $d->format('Y-m-d'),
            '{week}' => (string) (int) $d->format('W'),
            '{month}' => $d->format('F'),
            '{year}' => $d->format('Y'),
        ));
        $out = trim($out);
        if ('' === $out) {
            return $name;
        }

        return function_exists('mb_substr') ? mb_substr($out, 0, 255, 'UTF-8') : substr($out, 0, 255);
    }

    // --------------------------------------------------------------- store

    /**
     * The schedule of a card, or null.
     *
     * @param int $cardId
     *
     * @return array<string, mixed>|null
     */
    public static function forCard($cardId)
    {
        $q = new w2p_Database_Query();
        $q->addTable('card_schedules');
        $q->addQuery('*');
        $q->addWhere('schedule_card = ' . (int) $cardId);
        $row = $q->loadHash();

        return $row ? $row : null;
    }

    /**
     * Ids of the cards (among these) that repeat.
     *
     * @param int[] $cardIds
     *
     * @return array<int, bool> card_id => active
     */
    public static function repeating(array $cardIds)
    {
        $ids = array_filter(array_map('intval', $cardIds));
        if (!$ids) {
            return array();
        }
        $q = new w2p_Database_Query();
        $q->addTable('card_schedules');
        $q->addQuery('schedule_card, schedule_active');
        $q->addWhere('schedule_card IN (' . implode(',', $ids) . ')');
        $out = array();
        foreach ($q->loadList() as $row) {
            $out[(int) $row['schedule_card']] = (bool) $row['schedule_active'];
        }

        return $out;
    }

    /**
     * Sets a card's schedule (creating or replacing it), as $userId, who it
     * then runs as. Saving also resumes a paused schedule.
     *
     * @param int                  $cardId
     * @param int                  $userId
     * @param array<string, mixed> $input rule, days, start (Y-m-d), name, status (0 = keep), shift, skip_open
     * @param string|null          $today Y-m-d
     *
     * @return array<string, mixed>|string the saved schedule, or an error message
     */
    public static function save($cardId, $userId, array $input, $today = null)
    {
        $today = $today ? $today : date('Y-m-d');
        $rule = isset($input['rule']) ? (string) $input['rule'] : '';
        if (!in_array($rule, array(self::WEEKLY, self::MONTHLY, self::DAYS), true)) {
            return 'Pick how often the card repeats.';
        }
        $days = self::cleanDays($rule, isset($input['days']) ? $input['days'] : null);
        if (null === $days) {
            return array(
                self::WEEKLY => 'Pick at least one day of the week.',
                self::MONTHLY => 'Pick a day of the month from 1 to 31.',
                self::DAYS => 'Repeat every 1 to 365 days.',
            )[$rule];
        }
        $start = isset($input['start']) ? trim((string) $input['start']) : '';
        if ('' === $start) {
            $start = $today;
        } elseif (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $start, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return 'Use a start date like 2026-11-09.';
        }
        if ($start < $today) {
            $start = $today;
        }
        $status = isset($input['status']) ? (int) $input['status'] : 0;
        if ($status && !CCardStatuses::get($status)) {
            return 'Unknown status.';
        }
        $name = isset($input['name']) ? trim((string) $input['name']) : self::DEFAULT_NAME;
        if (strlen($name) > 255) {
            return 'The name can be at most 255 characters.';
        }

        $first = self::nextOn($rule, $days, $start, $start);
        $old = self::forCard($cardId);
        $q = new w2p_Database_Query();
        $q->addTable('card_schedules');
        $values = array(
            'schedule_user' => (int) $userId,
            'schedule_rule' => $rule,
            'schedule_days' => is_array($days) ? implode(',', $days) : (string) $days,
            'schedule_name' => $name,
            'schedule_status' => $status,
            'schedule_shift' => empty($input['shift']) ? 0 : 1,
            'schedule_skip_open' => empty($input['skip_open']) ? 0 : 1,
            'schedule_anchor' => $first,
            'schedule_next' => $first,
            'schedule_active' => 1,
            'schedule_note' => '',
            'schedule_updated' => $q->dbfnNowWithTZ(),
        );
        if ($old) {
            foreach ($values as $column => $value) {
                $q->addUpdate($column, $value);
            }
            $q->addWhere('schedule_id = ' . (int) $old['schedule_id']);
        } else {
            $q->addInsert('schedule_card', (int) $cardId);
            $q->addInsert('schedule_last_copy', 0);
            foreach ($values as $column => $value) {
                $q->addInsert($column, $value);
            }
        }
        $q->exec();

        $before = $old ? self::summary($old['schedule_rule'], self::cleanDays($old['schedule_rule'], $old['schedule_days'])) : '';
        $after = self::summary($rule, $days);
        if ($before !== $after || !$old || !$old['schedule_active']) {
            CCardActivity::log($cardId, $userId, CCardActivity::CHANGED, 'repeat', $before, $after);
        }

        return self::forCard($cardId);
    }

    /**
     * Stops a card repeating.
     *
     * @param int $cardId
     * @param int $userId
     */
    public static function remove($cardId, $userId)
    {
        $old = self::forCard($cardId);
        if (!$old) {
            return;
        }
        self::deleteFor(array($cardId));
        CCardActivity::log($cardId, $userId, CCardActivity::CHANGED, 'repeat',
            self::summary($old['schedule_rule'], self::cleanDays($old['schedule_rule'], $old['schedule_days'])), '');
    }

    /**
     * Removes the schedules of deleted cards.
     *
     * @param int[] $cardIds
     */
    public static function deleteFor(array $cardIds)
    {
        $ids = array_filter(array_map('intval', $cardIds));
        if (!$ids) {
            return;
        }
        $q = new w2p_Database_Query();
        $q->setDelete('card_schedules');
        $q->addWhere('schedule_card IN (' . implode(',', $ids) . ')');
        $q->exec();
    }

    /**
     * A schedule shaped for the drawer.
     *
     * @param array<string, mixed> $row
     * @param CCardAccess          $access the viewer's
     *
     * @return array<string, mixed>
     */
    public static function describe(array $row, CCardAccess $access)
    {
        $days = self::cleanDays($row['schedule_rule'], $row['schedule_days']);
        $last = null;
        if ((int) $row['schedule_last_copy'] && $access->canSeeCard((int) $row['schedule_last_copy'])) {
            $copy = CCardStore::load((int) $row['schedule_last_copy']);
            $last = array('id' => (int) $copy['card_id'], 'key' => CCardStore::key($copy), 'name' => $copy['card_name']);
        }

        return array(
            'rule' => $row['schedule_rule'],
            'days' => $days,
            'summary' => self::summary($row['schedule_rule'], $days),
            'start' => $row['schedule_anchor'],
            'next' => $row['schedule_next'],
            'name' => $row['schedule_name'],
            'status' => (int) $row['schedule_status'],
            'shift' => (bool) $row['schedule_shift'],
            'skip_open' => (bool) $row['schedule_skip_open'],
            'active' => (bool) $row['schedule_active'],
            'note' => $row['schedule_note'],
            'user' => (int) $row['schedule_user'],
            'user_name' => CCardStore::userName((int) $row['schedule_user']),
            'last_run' => $row['schedule_last_run'],
            'last_copy' => $last,
        );
    }

    // ----------------------------------------------------------------- run

    /**
     * Whether any schedule is due, cheaply (for page loads).
     *
     * @param string|null $today
     *
     * @return bool
     */
    public static function anyDue($today = null)
    {
        $q = new w2p_Database_Query();
        $q->addTable('card_schedules');
        $q->addQuery('COUNT(*)');
        $q->addWhere('schedule_active = 1');
        $q->addWhere('schedule_next <= ' . $q->quote($today ? $today : date('Y-m-d')));

        return (int) $q->loadResult() > 0;
    }

    /**
     * Runs every schedule that is due.
     *
     * @param w2p_Core_CAppUI $AppUI
     * @param string|null     $today Y-m-d
     *
     * @return array<string, int> copied, skipped, paused
     */
    public static function runDue(w2p_Core_CAppUI $AppUI, $today = null)
    {
        $today = $today ? $today : date('Y-m-d');
        $counts = array('copied' => 0, 'skipped' => 0, 'paused' => 0);
        $q = new w2p_Database_Query();
        $q->addTable('card_schedules');
        $q->addQuery('*');
        $q->addWhere('schedule_active = 1');
        $q->addWhere('schedule_next <= ' . $q->quote($today));
        $q->addOrder('schedule_next, schedule_id');
        foreach ($q->loadList() as $row) {
            $result = self::runOne($AppUI, $row, $today);
            if ($result) {
                $counts[$result]++;
            }
        }

        return $counts;
    }

    /**
     * @param w2p_Core_CAppUI      $AppUI
     * @param array<string, mixed> $row
     * @param string               $today
     *
     * @return string|null copied, skipped, paused, or null if another run had it
     */
    protected static function runOne(w2p_Core_CAppUI $AppUI, array $row, $today)
    {
        $days = self::cleanDays($row['schedule_rule'], $row['schedule_days']);
        $tomorrow = (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
        $next = self::nextOn($row['schedule_rule'], $days, $tomorrow, $row['schedule_anchor']);

        // Claim it: only the run that moves schedule_next on goes ahead.
        $q = new w2p_Database_Query();
        $q->addTable('card_schedules');
        $q->addUpdate('schedule_next', $next);
        $q->addUpdate('schedule_last_run', $q->dbfnNowWithTZ());
        $q->addWhere('schedule_id = ' . (int) $row['schedule_id']);
        $q->addWhere('schedule_active = 1');
        $q->addWhere('schedule_next = ' . $q->quote($row['schedule_next']));
        $q->exec();
        if ($GLOBALS['db']->Affected_Rows() < 1) {
            return null;
        }

        $cardId = (int) $row['schedule_card'];
        $userId = (int) $row['schedule_user'];
        $card = CCardStore::load($cardId);
        if (!$card) {
            self::deleteFor(array($cardId));

            return null;
        }
        $label = (new DateTimeImmutable($today))->format('M j');

        $access = new CCardAccess($AppUI, $userId);
        if (!$access->can('add') || !$access->canSeeCard($cardId)) {
            $why = 'Paused: ' . (CCardStore::userName($userId) ?: 'the user who set it up') . ' can no longer see this card or add cards. Save the repeat again to resume it as yourself.';
            self::setNote($row, $why, false);
            CCardActivity::log($cardId, 0, CCardActivity::SYSTEM, 'repeat', '', 'paused the repeat before the ' . $label . ' copy: ' . (CCardStore::userName($userId) ?: 'its owner') . ' can no longer see this card or add cards.');

            return 'paused';
        }

        if ($row['schedule_skip_open'] && (int) $row['schedule_last_copy']) {
            $previous = CCardStore::load((int) $row['schedule_last_copy']);
            $closed = CCardStatuses::closedIds();
            if ($previous && !in_array((int) $previous['card_status'], $closed, true)) {
                $why = 'the last copy, ' . CCardStore::key($previous) . ', is still open.';
                self::setNote($row, 'Skipped the ' . $label . ' copy: ' . $why, true);
                CCardActivity::log($cardId, 0, CCardActivity::SYSTEM, 'repeat', '', 'skipped the ' . $label . ' copy: ' . $why);

                return 'skipped';
            }
        }

        $shift = 0;
        if ($row['schedule_shift']) {
            $shift = (int) (new DateTimeImmutable($row['schedule_anchor']))->diff(new DateTimeImmutable($today))->format('%r%a');
        }
        $store = new CCardStore($userId);
        $copied = $store->copy(
            $cardId,
            self::copyName($row['schedule_name'], $card['card_name'], $today),
            (int) $row['schedule_status'] ? (int) $row['schedule_status'] : null,
            $access->hiddenForCopy($cardId),
            $shift
        );
        if (!$copied) {
            $why = 'the ' . $label . ' copy failed (' . implode(' ', $store->getErrors()) . ')';
            self::setNote($row, 'Paused: ' . $why, false);
            CCardActivity::log($cardId, 0, CCardActivity::SYSTEM, 'repeat', '', 'paused the repeat: ' . $why);

            return 'paused';
        }

        $u = new w2p_Database_Query();
        $u->addTable('card_schedules');
        $u->addUpdate('schedule_last_copy', (int) $copied['root']);
        $u->addUpdate('schedule_note', '');
        $u->addWhere('schedule_id = ' . (int) $row['schedule_id']);
        $u->exec();

        return 'copied';
    }

    /**
     * @param array<string, mixed> $row
     * @param string               $note
     * @param bool                 $active
     */
    protected static function setNote(array $row, $note, $active)
    {
        $q = new w2p_Database_Query();
        $q->addTable('card_schedules');
        $q->addUpdate('schedule_note', substr($note, 0, 255));
        $q->addUpdate('schedule_active', $active ? 1 : 0);
        $q->addWhere('schedule_id = ' . (int) $row['schedule_id']);
        $q->exec();
    }
}
