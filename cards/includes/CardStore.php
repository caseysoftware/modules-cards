<?php
/**
 * Every change to cards goes through here, so each one is validated, logged
 * in card_activity and version-checked the same way whether it came from the
 * board, the drawer or the cron sweep.
 *
 * Callers check permissions first (CCardAccess); this class checks the data:
 * same-project relationships, no cycles, valid values.
 *
 * @package web2project\modules\cards
 */
class CCardStore
{
    /** Ranks longer than this trigger a rebalance of the project's ranks. */
    const RANK_REBALANCE_LENGTH = 50;

    /** @var int user making the changes, 0 for the system */
    protected $userId;

    /** @var string[] */
    protected $errors = array();

    /**
     * @param int $userId
     */
    public function __construct($userId)
    {
        $this->userId = (int) $userId;
    }

    /**
     * @return string[]
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * @param string $field
     * @param string $message
     *
     * @return false
     */
    protected function fail($field, $message)
    {
        $this->errors[$field] = $message;

        return false;
    }

    // ---------------------------------------------------------------- reads

    /**
     * A card's row, or null.
     *
     * @param int $cardId
     *
     * @return array<string, mixed>|null
     */
    public static function load($cardId)
    {
        $q = new w2p_Database_Query();
        $q->addTable('cards');
        $q->addQuery('*');
        $q->addWhere('card_id = ' . (int) $cardId);
        $row = $q->loadHash();

        return $row ? $row : null;
    }

    /**
     * The card and all its subcards, recursively (the card first).
     *
     * @param int $cardId
     *
     * @return int[]
     */
    public static function withDescendants($cardId)
    {
        $ids = array((int) $cardId);
        $frontier = array((int) $cardId);
        while ($frontier) {
            $q = new w2p_Database_Query();
            $q->addTable('cards');
            $q->addQuery('card_id');
            $q->addWhere('card_parent IN (' . implode(',', $frontier) . ')');
            $children = array_diff(array_map('intval', $q->loadColumn()), $ids);
            $ids = array_merge($ids, $children);
            $frontier = $children;
        }

        return $ids;
    }

    /**
     * @param int $userId
     *
     * @return string
     */
    public static function userName($userId)
    {
        if (!$userId) {
            return '';
        }
        $q = new w2p_Database_Query();
        $q->addTable('users', 'u');
        $q->leftJoin('contacts', 'co', 'co.contact_id = u.user_contact');
        $q->addQuery('u.user_username, co.contact_first_name, co.contact_last_name');
        $q->addWhere('u.user_id = ' . (int) $userId);
        $row = $q->loadHash();
        if (!$row) {
            return '#' . (int) $userId;
        }
        $name = trim($row['contact_first_name'] . ' ' . $row['contact_last_name']);

        return html_entity_decode($name ? $name : $row['user_username'], ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param int $taskId
     *
     * @return string
     */
    public static function taskLabel($taskId)
    {
        if (!$taskId) {
            return '';
        }
        $q = new w2p_Database_Query();
        $q->addTable('tasks');
        $q->addQuery('task_name');
        $q->addWhere('task_id = ' . (int) $taskId);

        return '#' . (int) $taskId . ' ' . html_entity_decode((string) $q->loadResult(), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Card label used in the activity log: "WEB-12 Name".
     *
     * @param array<string, mixed> $card
     *
     * @return string
     */
    public static function label(array $card)
    {
        return self::key($card) . ' ' . $card['card_name'];
    }

    /**
     * Display key: the project's short name and the card id. Display only:
     * everything links by card_id.
     *
     * @param array<string, mixed> $card a cards row (or one with card_id + card_project)
     *
     * @return string
     */
    public static function key(array $card)
    {
        static $shortNames = array();
        $projectId = (int) $card['card_project'];
        if (!isset($shortNames[$projectId])) {
            $q = new w2p_Database_Query();
            $q->addTable('projects');
            $q->addQuery('project_short_name');
            $q->addWhere('project_id = ' . $projectId);
            $shortNames[$projectId] = (string) $q->loadResult();
        }
        $prefix = ('' !== trim($shortNames[$projectId])) ? trim($shortNames[$projectId]) : 'CARD';

        return $prefix . '-' . (int) $card['card_id'];
    }

    /**
     * The card id in a key like "WEB-1042", "erp-1042" or "1042". The prefix
     * is ignored, so a key keeps working after a move or a rename.
     *
     * @param string $key
     *
     * @return int
     */
    public static function idFromKey($key)
    {
        return preg_match('/^\s*(?:[^\s-]+-)?(\d+)\s*$/', (string) $key, $m) ? (int) $m[1] : 0;
    }

    // ------------------------------------------------------------- validate

    /**
     * Validates and normalizes field values. Returns the clean values, or
     * false with errors set.
     *
     * @param array<string, mixed> $fields
     * @param bool                 $creating name and status required
     *
     * @return array<string, mixed>|false
     */
    protected function clean(array $fields, $creating)
    {
        $clean = array();
        $this->errors = array();

        if (array_key_exists('name', $fields) || $creating) {
            $name = trim((string) (isset($fields['name']) ? $fields['name'] : ''));
            if ('' === $name) {
                $this->fail('name', 'Give the card a name.');
            } elseif (strlen($name) > 255) {
                $this->fail('name', 'The name can be at most 255 characters.');
            }
            $clean['card_name'] = $name;
        }
        if (array_key_exists('description', $fields)) {
            $clean['card_description'] = (string) $fields['description'];
        }
        if (array_key_exists('status', $fields) || $creating) {
            $status = isset($fields['status']) ? (int) $fields['status'] : CCardStatuses::defaultId();
            if (!CCardStatuses::get($status)) {
                $this->fail('status', 'Unknown status.');
            }
            $clean['card_status'] = $status;
        }
        if (array_key_exists('priority', $fields)) {
            $priority = (int) $fields['priority'];
            if (!in_array($priority, array(-1, 0, 1), true)) {
                $this->fail('priority', 'Priority must be low, normal or high.');
            }
            $clean['card_priority'] = $priority;
        }
        if (array_key_exists('owner', $fields)) {
            $clean['card_owner'] = max(0, (int) $fields['owner']);
        }
        if (array_key_exists('due', $fields)) {
            $due = trim((string) $fields['due']);
            if ('' === $due) {
                $clean['card_due_date'] = null;
            } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $due, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $clean['card_due_date'] = $due;
            } else {
                $this->fail('due', 'Use a date like 2026-11-30.');
            }
        }
        if (array_key_exists('task', $fields)) {
            $clean['card_task'] = max(0, (int) $fields['task']);
        }

        return $this->errors ? false : $clean;
    }

    // ---------------------------------------------------------------- write

    /**
     * Creates a card at the end of its project's order.
     *
     * @param int                  $projectId
     * @param array<string, mixed> $fields name, status, priority, owner, due, task, description
     * @param int                  $parentId
     *
     * @return int|false the new card id
     */
    public function create($projectId, array $fields, $parentId = 0)
    {
        $clean = $this->clean($fields, true);
        if (false === $clean) {
            return false;
        }
        $projectId = (int) $projectId;
        $parentId = (int) $parentId;
        if ($parentId) {
            $parent = self::load($parentId);
            if (!$parent || (int) $parent['card_project'] !== $projectId) {
                return $this->fail('parent', 'A subcard must be in the same project as its parent.');
            }
        }

        $q = new w2p_Database_Query();
        $now = $q->dbfnNowWithTZ();
        $q->addTable('cards');
        $q->addInsert('card_project', $projectId);
        $q->addInsert('card_parent', $parentId);
        $q->addInsert('card_name', $clean['card_name']);
        $q->addInsert('card_description', isset($clean['card_description']) ? $clean['card_description'] : '');
        $q->addInsert('card_status', $clean['card_status']);
        $q->addInsert('card_priority', isset($clean['card_priority']) ? $clean['card_priority'] : 0);
        $q->addInsert('card_owner', isset($clean['card_owner']) ? $clean['card_owner'] : $this->userId);
        $q->addInsert('card_task', isset($clean['card_task']) ? $clean['card_task'] : 0);
        if (!empty($clean['card_due_date'])) {
            $q->addInsert('card_due_date', $clean['card_due_date']);
        }
        $q->addInsert('card_rank', $this->rankAtEnd($projectId));
        $q->addInsert('card_creator', $this->userId);
        $q->addInsert('card_created', $now);
        $q->addInsert('card_updator', $this->userId);
        $q->addInsert('card_updated', $now);
        $q->addInsert('card_version', 1);
        if (!$q->exec()) {
            return $this->fail('store', 'The card could not be saved.');
        }
        $cardId = (int) $GLOBALS['db']->Insert_ID();

        CCardActivity::log($cardId, $this->userId, CCardActivity::CREATED);
        if ($parentId) {
            CCardActivity::log($parentId, $this->userId, CCardActivity::SUBCARD_ADDED, '', '', self::label(self::load($cardId)), $cardId);
        }
        addHistory('cards', $cardId, 'add', $clean['card_name'], $projectId);

        return $cardId;
    }

    /**
     * Changes fields of a card. Fails with a "conflict" error when the card
     * changed since the caller read it ($version).
     *
     * @param int                  $cardId
     * @param int|null             $version null to skip the check (system changes)
     * @param array<string, mixed> $fields
     * @param string|null          $rank    a new rank, from move()
     *
     * @return bool
     */
    public function update($cardId, $version, array $fields, $rank = null)
    {
        $card = self::load($cardId);
        if (!$card) {
            return $this->fail('card', 'The card no longer exists.');
        }
        $clean = $this->clean($fields, false);
        if (false === $clean) {
            return false;
        }

        $changed = array();
        foreach ($clean as $column => $value) {
            $old = $card[$column];
            $same = (null === $value) ? (null === $old || '' === $old) : ((string) $old === (string) $value);
            if (!$same) {
                $changed[$column] = $value;
            }
        }
        if (!$changed && null === $rank) {
            return true;
        }

        $q = new w2p_Database_Query();
        $q->addTable('cards');
        foreach ($changed as $column => $value) {
            if (null === $value) {
                $q->addUpdate($column, 'NULL', false, true);
            } else {
                $q->addUpdate($column, $value);
            }
        }
        if (null !== $rank) {
            $q->addUpdate('card_rank', $rank);
        }
        $q->addUpdate('card_updator', $this->userId);
        $q->addUpdate('card_updated', $q->dbfnNowWithTZ());
        $q->addUpdate('card_version', 'card_version + 1', false, true);
        $q->addWhere('card_id = ' . (int) $cardId);
        if (null !== $version) {
            $q->addWhere('card_version = ' . (int) $version);
        }
        if (!$q->exec()) {
            return $this->fail('store', 'The card could not be saved.');
        }
        if ($GLOBALS['db']->Affected_Rows() < 1) {
            return $this->fail('conflict', 'Someone else changed this card. The board has been refreshed.');
        }

        foreach ($changed as $column => $value) {
            $this->logChange($card, $column, $card[$column], $value);
        }

        return true;
    }

    /**
     * Writes one CHANGED entry with readable old and new values.
     *
     * @param array<string, mixed> $card
     * @param string               $column
     * @param mixed                $old
     * @param mixed                $new
     */
    protected function logChange(array $card, $column, $old, $new)
    {
        $field = substr($column, 5); // card_status -> status
        $describe = function ($value) use ($column) {
            switch ($column) {
                case 'card_status':
                    $status = CCardStatuses::get((int) $value);
                    return $status ? $status['name'] : '';
                case 'card_priority':
                    $labels = array(-1 => 'Low', 0 => 'Normal', 1 => 'High');
                    return isset($labels[(int) $value]) ? $labels[(int) $value] : '';
                case 'card_owner':
                    return CCardStore::userName((int) $value);
                case 'card_task':
                    return CCardStore::taskLabel((int) $value);
                case 'card_due_date':
                    return (string) $value;
                default:
                    return (string) $value;
            }
        };
        if ('due_date' == $field) {
            $field = 'due';
        }
        CCardActivity::log($card['card_id'], $this->userId, CCardActivity::CHANGED, $field, $describe($old), $describe($new));
    }

    /**
     * Moves a card on the board: between two cards, optionally changing the
     * field its new column stands for (status, priority, owner or task).
     *
     * @param int                  $cardId
     * @param int                  $version
     * @param array<string, mixed> $fields   e.g. array('status' => 3)
     * @param int                  $beforeId card now above it, 0 if first
     * @param int                  $afterId  card now below it, 0 if last
     *
     * @return bool
     */
    public function move($cardId, $version, array $fields, $beforeId, $afterId)
    {
        $card = self::load($cardId);
        if (!$card) {
            return $this->fail('card', 'The card no longer exists.');
        }
        $projectId = (int) $card['card_project'];
        $rank = $this->rankBetween($projectId, (int) $beforeId, (int) $afterId);
        if (strlen($rank) > self::RANK_REBALANCE_LENGTH) {
            $this->rebalance($projectId);
            $rank = $this->rankBetween($projectId, (int) $beforeId, (int) $afterId);
        }

        return $this->update($cardId, $version, $fields, $rank);
    }

    /**
     * @param int $projectId
     * @param int $beforeId
     * @param int $afterId
     *
     * @return string
     */
    protected function rankBetween($projectId, $beforeId, $afterId)
    {
        $before = $beforeId ? self::load($beforeId) : null;
        $after = $afterId ? self::load($afterId) : null;
        // Neighbours from another project don't count.
        $before = ($before && (int) $before['card_project'] === $projectId) ? $before['card_rank'] : null;
        $after = ($after && (int) $after['card_project'] === $projectId) ? $after['card_rank'] : null;

        return CCardRank::between($before, $after);
    }

    /**
     * @param int $projectId
     *
     * @return string
     */
    protected function rankAtEnd($projectId)
    {
        $q = new w2p_Database_Query();
        $q->addTable('cards');
        $q->addQuery('MAX(card_rank)');
        $q->addWhere('card_project = ' . (int) $projectId);
        $last = $q->loadResult();
        $rank = CCardRank::between($last ? $last : null, null);
        if (strlen($rank) > self::RANK_REBALANCE_LENGTH) {
            $this->rebalance($projectId);

            return $this->rankAtEnd($projectId);
        }

        return $rank;
    }

    /**
     * Rewrites a project's ranks as short, evenly spaced keys in the lower
     * half of the range, keeping the order. Leaves room to insert anywhere.
     *
     * @param int $projectId
     */
    public function rebalance($projectId)
    {
        $q = new w2p_Database_Query();
        $q->addTable('cards');
        $q->addQuery('card_id');
        $q->addWhere('card_project = ' . (int) $projectId);
        $q->addOrder('card_rank, card_id');
        $ids = array_map('intval', $q->loadColumn());

        $digits = CCardRank::DIGITS;
        $count = count($ids);
        $width = max(2, (int) ceil(log(2 * $count + 2, 62)));
        $span = pow(62, $width) / (2 * $count + 2);
        foreach ($ids as $i => $id) {
            $value = (int) floor(($i + 1) * $span);
            $key = '';
            for ($d = 0; $d < $width; $d++) {
                $key = $digits[$value % 62] . $key;
                $value = intdiv($value, 62);
            }
            $u = new w2p_Database_Query();
            $u->addTable('cards');
            $u->addUpdate('card_rank', $key . 'V');
            $u->addWhere('card_id = ' . $id);
            $u->exec();
        }
    }

    /**
     * Deletes a card with all its subcards, their relationships, tags and
     * activity. Surviving cards that depended on them log the removal.
     *
     * @param int $cardId
     *
     * @return int[] the deleted card ids
     */
    public function delete($cardId)
    {
        $card = self::load($cardId);
        if (!$card) {
            return array();
        }
        $ids = self::withDescendants($cardId);
        $this->unlinkDependencies($ids);

        if ((int) $card['card_parent'] && !in_array((int) $card['card_parent'], $ids, true)) {
            CCardActivity::log($card['card_parent'], $this->userId, CCardActivity::SUBCARD_REMOVED, '', self::label($card), 'deleted', $cardId);
        }

        CCardTags::deleteFor($ids);
        CCardActivity::deleteFor($ids);
        $q = new w2p_Database_Query();
        $q->setDelete('cards');
        $q->addWhere('card_id IN (' . implode(',', $ids) . ')');
        $q->exec();

        addHistory('cards', $cardId, 'delete', $card['card_name'], (int) $card['card_project']);

        return $ids;
    }

    /** Most cards one copy may create. */
    const MAX_COPY = 500;

    /**
     * Deep copy: the card, all its subcards (recursively) and their
     * dependencies, in the same project.
     *
     * - The copy of the card sits next to the original (same parent), right
     *   after it in the project's order; the subcard copies follow it.
     * - Name, description, priority, owner, due date, linked task and tags
     *   are copied. Every copy gets $status (null: each keeps its original's).
     *   Comments and activity are not copied.
     * - A dependency between two copied cards is recreated between their
     *   copies. A dependency on a card outside the copy is kept: the copy
     *   waits on the same card. Cards outside that wait on an original are
     *   left alone.
     * - Cards in $hidden (ones the user can't see) are not copied, nor their
     *   subcards, and dependencies on them are not recreated.
     *
     * @param int         $cardId
     * @param string|null $name   name of the top copy (null: the original's)
     * @param int|null    $status
     * @param int[]       $hidden
     *
     * @return array{root: int, map: array<int, int>, skipped: int}|false
     */
    public function copy($cardId, $name = null, $status = null, array $hidden = array())
    {
        $card = self::load($cardId);
        if (!$card) {
            return $this->fail('card', 'The card no longer exists.');
        }
        $this->errors = array();
        if (null !== $name) {
            $name = trim((string) $name);
            if ('' === $name) {
                return $this->fail('name', 'Give the copy a name.');
            }
            if (strlen($name) > 255) {
                return $this->fail('name', 'The name can be at most 255 characters.');
            }
        }
        if (null !== $status && !CCardStatuses::get((int) $status)) {
            return $this->fail('status', 'Unknown status.');
        }
        $projectId = (int) $card['card_project'];
        $hidden = array_flip(array_map('intval', $hidden));

        // The tree, parents before children, without hidden subtrees.
        $rows = array((int) $card['card_id'] => $card);
        $frontier = array((int) $card['card_id']);
        $skipped = 0;
        while ($frontier) {
            $q = new w2p_Database_Query();
            $q->addTable('cards');
            $q->addQuery('*');
            $q->addWhere('card_parent IN (' . implode(',', $frontier) . ')');
            $q->addWhere('card_project = ' . $projectId);
            $q->addOrder('card_rank, card_id');
            $frontier = array();
            foreach ($q->loadList() as $row) {
                $id = (int) $row['card_id'];
                if (isset($rows[$id])) {
                    continue;
                }
                if (isset($hidden[$id])) {
                    $skipped += count(self::withDescendants($id));
                    continue;
                }
                $rows[$id] = $row;
                $frontier[] = $id;
            }
            if (count($rows) > self::MAX_COPY) {
                return $this->fail('copy', 'A copy can include at most ' . self::MAX_COPY . ' cards.');
            }
        }

        // Ranks: right after the original, before whatever follows it.
        $q = new w2p_Database_Query();
        $q->addTable('cards');
        $q->addQuery('MIN(card_rank)');
        $q->addWhere('card_project = ' . $projectId);
        $q->addWhere('card_rank > ' . $q->quote($card['card_rank']));
        $next = $q->loadResult();
        $next = $next ? $next : null;
        $rank = $card['card_rank'];

        $tags = CCardTags::forCards(array_keys($rows));
        $tagIds = array();
        foreach ($tags as $ids) {
            $tagIds = array_merge($tagIds, $ids);
        }
        $tagNames = CCardTags::names($tagIds);
        $map = array();
        $now = (new w2p_Database_Query())->dbfnNowWithTZ();
        $longest = 0;
        foreach ($rows as $id => $row) {
            $isRoot = ($id === (int) $card['card_id']);
            $rank = CCardRank::between($rank, $next);
            $longest = max($longest, strlen($rank));

            $q = new w2p_Database_Query();
            $q->addTable('cards');
            $q->addInsert('card_project', $projectId);
            $q->addInsert('card_parent', $isRoot ? (int) $row['card_parent'] : $map[(int) $row['card_parent']]);
            $q->addInsert('card_name', ($isRoot && null !== $name) ? $name : $row['card_name']);
            $q->addInsert('card_description', (string) $row['card_description']);
            $q->addInsert('card_status', null !== $status ? (int) $status : (int) $row['card_status']);
            $q->addInsert('card_priority', (int) $row['card_priority']);
            $q->addInsert('card_owner', (int) $row['card_owner']);
            $q->addInsert('card_task', (int) $row['card_task']);
            if (!empty($row['card_due_date'])) {
                $q->addInsert('card_due_date', $row['card_due_date']);
            }
            $q->addInsert('card_rank', $rank);
            $q->addInsert('card_creator', $this->userId);
            $q->addInsert('card_created', $now);
            $q->addInsert('card_updator', $this->userId);
            $q->addInsert('card_updated', $now);
            $q->addInsert('card_version', 1);
            if (!$q->exec()) {
                if ($map) {
                    $this->delete($map[(int) $card['card_id']]);
                }

                return $this->fail('store', 'The copy could not be saved.');
            }
            $newId = (int) $GLOBALS['db']->Insert_ID();
            $map[$id] = $newId;

            foreach (isset($tags[$id]) ? $tags[$id] : array() as $tagId) {
                CCardTags::attach($newId, $tagId);
            }
            CCardActivity::log($newId, $this->userId, CCardActivity::COPIED, 'from', '', self::label($row), $id);
            if (!empty($tags[$id])) {
                $names = array_intersect_key($tagNames, array_flip($tags[$id]));
                CCardActivity::log($newId, $this->userId, CCardActivity::TAG_ADDED, 'tags', '', implode(', ', $names));
            }
            addHistory('cards', $newId, 'add', ($isRoot && null !== $name) ? $name : $row['card_name'], $projectId);
        }

        $rootCopy = self::load($map[(int) $card['card_id']]);
        CCardActivity::log($card['card_id'], $this->userId, CCardActivity::COPIED, 'to', '', self::label($rootCopy), $rootCopy['card_id']);
        if ((int) $card['card_parent']) {
            CCardActivity::log($card['card_parent'], $this->userId, CCardActivity::SUBCARD_ADDED, '', '', self::label($rootCopy), $rootCopy['card_id']);
        }

        // Dependencies of the copied cards: inside the set, between the
        // copies; on a card outside it, the copy waits on that card too.
        // Copies have no other links yet, so none of these can close a loop.
        $q = new w2p_Database_Query();
        $q->addTable('card_dependencies');
        $q->addQuery('dependency_card, dependency_requires');
        $q->addWhere('dependency_card IN (' . implode(',', array_keys($map)) . ')');
        foreach ($q->loadList() as $dep) {
            $requires = (int) $dep['dependency_requires'];
            if (isset($hidden[$requires])) {
                continue;
            }
            if (!isset($map[$requires])) {
                $target = self::load($requires);
                if (!$target || (int) $target['card_project'] !== $projectId) {
                    continue;
                }
            }
            $from = $map[(int) $dep['dependency_card']];
            $to = isset($map[$requires]) ? $map[$requires] : $requires;
            $i = new w2p_Database_Query();
            $i->addTable('card_dependencies');
            $i->addInsert('dependency_card', $from);
            $i->addInsert('dependency_requires', $to);
            $i->exec();
            $fromCard = self::load($from);
            $toCard = self::load($to);
            CCardActivity::log($from, $this->userId, CCardActivity::DEPENDENCY_ADDED, 'waits_on', '', self::label($toCard), $to);
            CCardActivity::log($to, $this->userId, CCardActivity::DEPENDENCY_ADDED, 'blocks', '', self::label($fromCard), $from);
        }

        if ($longest > self::RANK_REBALANCE_LENGTH) {
            $this->rebalance($projectId);
        }

        return array('root' => $map[(int) $card['card_id']], 'map' => $map, 'skipped' => $skipped);
    }

    /**
     * Removes every dependency row touching these cards, logging on the
     * cards outside the set.
     *
     * @param int[] $ids
     */
    protected function unlinkDependencies(array $ids)
    {
        $list = implode(',', array_map('intval', $ids));
        $q = new w2p_Database_Query();
        $q->addTable('card_dependencies');
        $q->addQuery('dependency_card, dependency_requires');
        $q->addWhere('dependency_card IN (' . $list . ') OR dependency_requires IN (' . $list . ')');
        foreach ($q->loadList() as $row) {
            $card = (int) $row['dependency_card'];
            $requires = (int) $row['dependency_requires'];
            if (!in_array($card, $ids, true)) {
                $gone = self::load($requires);
                CCardActivity::log($card, $this->userId, CCardActivity::DEPENDENCY_REMOVED, 'waits_on', $gone ? self::label($gone) : '#' . $requires, 'deleted', $requires);
            }
            if (!in_array($requires, $ids, true)) {
                $gone = self::load($card);
                CCardActivity::log($requires, $this->userId, CCardActivity::DEPENDENCY_REMOVED, 'blocks', $gone ? self::label($gone) : '#' . $card, 'deleted', $card);
            }
        }
        $q = new w2p_Database_Query();
        $q->setDelete('card_dependencies');
        $q->addWhere('dependency_card IN (' . $list . ') OR dependency_requires IN (' . $list . ')');
        $q->exec();
    }

    // -------------------------------------------------------- relationships

    /**
     * $cardId waits on $requiresId. Both must be in the same project, and
     * the new link must not close a loop.
     *
     * @param int $cardId
     * @param int $requiresId
     *
     * @return bool
     */
    public function addDependency($cardId, $requiresId)
    {
        $card = self::load($cardId);
        $requires = self::load($requiresId);
        if (!$card || !$requires) {
            return $this->fail('dependency', 'That card no longer exists.');
        }
        if ((int) $card['card_id'] === (int) $requires['card_id']) {
            return $this->fail('dependency', 'A card can\'t wait on itself.');
        }
        if ((int) $card['card_project'] !== (int) $requires['card_project']) {
            return $this->fail('dependency', 'Dependencies only connect cards in the same project.');
        }
        if (in_array((int) $card['card_id'], $this->dependsOnTransitively((int) $requires['card_id'], (int) $card['card_project']), true)) {
            return $this->fail('dependency', 'That would create a loop: ' . self::key($requires) . ' already waits on ' . self::key($card) . ', directly or through other cards.');
        }

        $q = new w2p_Database_Query();
        $q->addTable('card_dependencies');
        $q->addQuery('COUNT(*)');
        $q->addWhere('dependency_card = ' . (int) $cardId . ' AND dependency_requires = ' . (int) $requiresId);
        if ((int) $q->loadResult()) {
            return true;
        }

        $q = new w2p_Database_Query();
        $q->addTable('card_dependencies');
        $q->addInsert('dependency_card', (int) $cardId);
        $q->addInsert('dependency_requires', (int) $requiresId);
        $q->exec();

        CCardActivity::log($cardId, $this->userId, CCardActivity::DEPENDENCY_ADDED, 'waits_on', '', self::label($requires), $requiresId);
        CCardActivity::log($requiresId, $this->userId, CCardActivity::DEPENDENCY_ADDED, 'blocks', '', self::label($card), $cardId);

        return true;
    }

    /**
     * @param int $cardId
     * @param int $requiresId
     *
     * @return bool
     */
    public function removeDependency($cardId, $requiresId)
    {
        $q = new w2p_Database_Query();
        $q->setDelete('card_dependencies');
        $q->addWhere('dependency_card = ' . (int) $cardId . ' AND dependency_requires = ' . (int) $requiresId);
        $q->exec();
        if ($GLOBALS['db']->Affected_Rows() < 1) {
            return true;
        }
        $card = self::load($cardId);
        $requires = self::load($requiresId);
        CCardActivity::log($cardId, $this->userId, CCardActivity::DEPENDENCY_REMOVED, 'waits_on', $requires ? self::label($requires) : '#' . $requiresId, '', $requiresId);
        CCardActivity::log($requiresId, $this->userId, CCardActivity::DEPENDENCY_REMOVED, 'blocks', $card ? self::label($card) : '#' . $cardId, '', $cardId);

        return true;
    }

    /**
     * Every card $cardId waits on, directly or through others, within one
     * project. Walks the project's dependency graph breadth first.
     *
     * @param int $cardId
     * @param int $projectId
     *
     * @return int[]
     */
    public function dependsOnTransitively($cardId, $projectId)
    {
        $q = new w2p_Database_Query();
        $q->addTable('card_dependencies', 'd');
        $q->innerJoin('cards', 'c', 'c.card_id = d.dependency_card');
        $q->addQuery('d.dependency_card, d.dependency_requires');
        $q->addWhere('c.card_project = ' . (int) $projectId);
        $edges = array();
        foreach ($q->loadList() as $row) {
            $edges[(int) $row['dependency_card']][] = (int) $row['dependency_requires'];
        }

        $seen = array();
        $queue = array((int) $cardId);
        while ($queue) {
            $current = array_shift($queue);
            foreach (isset($edges[$current]) ? $edges[$current] : array() as $next) {
                if (!isset($seen[$next])) {
                    $seen[$next] = true;
                    $queue[] = $next;
                }
            }
        }

        return array_keys($seen);
    }

    /**
     * Makes $cardId a subcard of $parentId (0: top level). Same project
     * only, and a card can't go under itself or one of its own subcards.
     *
     * @param int $cardId
     * @param int $parentId
     *
     * @return bool
     */
    public function setParent($cardId, $parentId)
    {
        $card = self::load($cardId);
        if (!$card) {
            return $this->fail('parent', 'The card no longer exists.');
        }
        $parentId = (int) $parentId;
        $oldParentId = (int) $card['card_parent'];
        if ($parentId === $oldParentId) {
            return true;
        }
        if ($parentId) {
            $parent = self::load($parentId);
            if (!$parent) {
                return $this->fail('parent', 'That card no longer exists.');
            }
            if ((int) $parent['card_project'] !== (int) $card['card_project']) {
                return $this->fail('parent', 'A subcard must be in the same project as its parent.');
            }
            if (in_array($parentId, self::withDescendants($cardId), true)) {
                return $this->fail('parent', 'A card can\'t go under itself or one of its own subcards.');
            }
        }

        $q = new w2p_Database_Query();
        $q->addTable('cards');
        $q->addUpdate('card_parent', $parentId);
        $q->addUpdate('card_version', 'card_version + 1', false, true);
        $q->addWhere('card_id = ' . (int) $cardId);
        $q->exec();

        $label = self::label($card);
        if ($oldParentId) {
            CCardActivity::log($oldParentId, $this->userId, CCardActivity::SUBCARD_REMOVED, '', $label, '', $cardId);
        }
        if ($parentId) {
            CCardActivity::log($parentId, $this->userId, CCardActivity::SUBCARD_ADDED, '', '', $label, $cardId);
        }
        $old = $oldParentId ? self::load($oldParentId) : null;
        $new = $parentId ? self::load($parentId) : null;
        CCardActivity::log($cardId, $this->userId, CCardActivity::CHANGED, 'parent', $old ? self::label($old) : '', $new ? self::label($new) : '');

        return true;
    }

    /**
     * @param int    $cardId
     * @param string $text
     *
     * @return bool
     */
    public function comment($cardId, $text)
    {
        $text = trim((string) $text);
        if ('' === $text) {
            return $this->fail('comment', 'Write something first.');
        }
        if (strlen($text) > 10000) {
            return $this->fail('comment', 'Comments can be at most 10,000 characters.');
        }
        CCardActivity::log($cardId, $this->userId, CCardActivity::COMMENT, '', '', $text);

        return true;
    }

    /**
     * @param int    $cardId
     * @param string $name one tag, or several separated by commas
     *
     * @return int the (first) tag id, 0 on failure
     */
    public function addTag($cardId, $name)
    {
        $ids = $this->addTags($cardId, $name);

        return $ids ? $ids[0] : 0;
    }

    /**
     * Adds every tag in what someone typed: "electrical, rough-in" is two
     * tags. Nothing is added if a name is too long or the card would end up
     * with more than CCardTags::MAX_PER_CARD tags.
     *
     * @param int    $cardId
     * @param string $input
     *
     * @return int[] the tag ids, empty on failure
     */
    public function addTags($cardId, $input)
    {
        $names = CCardTags::split($input);
        if (!$names) {
            $this->fail('tag', 'Type a tag name. Separate several tags with commas.');

            return array();
        }
        foreach ($names as $name) {
            $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
            if ($length > CCardTags::MAX_LENGTH) {
                $this->fail('tag', 'Tags need a name of at most ' . CCardTags::MAX_LENGTH . ' characters.');

                return array();
            }
        }

        $current = CCardTags::forCards(array($cardId));
        $current = isset($current[$cardId]) ? $current[$cardId] : array();
        $ids = array();
        foreach ($names as $name) {
            $ids[] = CCardTags::idFor($name);
        }
        $new = array_diff($ids, $current);
        if (count($current) + count($new) > CCardTags::MAX_PER_CARD) {
            $this->fail('tag', 'A card can have at most ' . CCardTags::MAX_PER_CARD . ' tags.');

            return array();
        }

        $tagNames = CCardTags::names($new);
        foreach ($new as $tagId) {
            if (CCardTags::attach($cardId, $tagId)) {
                CCardActivity::log($cardId, $this->userId, CCardActivity::TAG_ADDED, 'tags', '', $tagNames[$tagId]);
            }
        }

        return $ids;
    }

    /**
     * Adds an existing tag by id, whatever its name (tags from before 0.3.0
     * may have a comma in it).
     *
     * @param int $cardId
     * @param int $tagId
     *
     * @return bool false if there's no such tag or the card is at the limit
     */
    public function attachTag($cardId, $tagId)
    {
        $names = CCardTags::names(array($tagId));
        if (!$names) {
            return $this->fail('tag', 'That tag doesn\'t exist.');
        }
        $current = CCardTags::forCards(array($cardId));
        $current = isset($current[$cardId]) ? $current[$cardId] : array();
        if (in_array((int) $tagId, $current, true)) {
            return true;
        }
        if (!CCardTags::attach($cardId, $tagId)) {
            return $this->fail('tag', 'A card can have at most ' . CCardTags::MAX_PER_CARD . ' tags.');
        }
        CCardActivity::log($cardId, $this->userId, CCardActivity::TAG_ADDED, 'tags', '', reset($names));

        return true;
    }

    /**
     * @param int $cardId
     * @param int $tagId
     *
     * @return bool
     */
    public function removeTag($cardId, $tagId)
    {
        $names = CCardTags::names(array($tagId));
        if (CCardTags::detach($cardId, $tagId)) {
            CCardActivity::log($cardId, $this->userId, CCardActivity::TAG_REMOVED, 'tags', isset($names[$tagId]) ? $names[$tagId] : '', '');
        }

        return true;
    }
}
