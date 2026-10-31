<?php
/**
 * The global card status list (the board's columns), shared by every
 * project. Each status has a category, so boards and counts can tell
 * "not started", "started" and "closed" apart whatever the names are.
 *
 * @package web2project\modules\cards
 */
class CCardStatuses
{
    const NOT_STARTED = 0;
    const STARTED = 1;
    const CLOSED = 2;

    /**
     * @return array<int, array{id:int, name:string, category:int, order:int}>
     */
    public static function all()
    {
        $q = new w2p_Database_Query();
        $q->addTable('card_statuses');
        $q->addQuery('*');
        $q->addOrder('card_status_order, card_status_id');

        $statuses = array();
        foreach ($q->loadList() as $row) {
            $statuses[] = array(
                'id' => (int) $row['card_status_id'],
                'name' => $row['card_status_name'],
                'category' => (int) $row['card_status_category'],
                'order' => (int) $row['card_status_order'],
            );
        }

        return $statuses;
    }

    /**
     * @param int $id
     *
     * @return array{id:int, name:string, category:int, order:int}|null
     */
    public static function get($id)
    {
        foreach (self::all() as $status) {
            if ($status['id'] == $id) {
                return $status;
            }
        }

        return null;
    }

    /**
     * The first status, where new cards go by default.
     *
     * @return int
     */
    public static function defaultId()
    {
        $all = self::all();

        return $all ? $all[0]['id'] : 0;
    }

    /**
     * Ids of the statuses that count as closed.
     *
     * @return int[]
     */
    public static function closedIds()
    {
        $ids = array();
        foreach (self::all() as $status) {
            if (self::CLOSED == $status['category']) {
                $ids[] = $status['id'];
            }
        }

        return $ids;
    }

    /**
     * Saves the whole list: renames, re-orders, adds (id 0) and removes.
     * A status still used by cards can't be removed.
     *
     * @param array<int, array<string, mixed>> $list in display order
     *
     * @return string[] errors, empty on success
     */
    public static function save(array $list)
    {
        $errors = array();
        $keep = array();
        $clean = array();
        foreach ($list as $item) {
            $name = trim((string) (isset($item['name']) ? $item['name'] : ''));
            $category = (int) (isset($item['category']) ? $item['category'] : 0);
            if ('' === $name || strlen($name) > 64) {
                $errors[] = 'Each status needs a name of at most 64 characters.';
                continue;
            }
            if (!in_array($category, array(self::NOT_STARTED, self::STARTED, self::CLOSED), true)) {
                $category = self::NOT_STARTED;
            }
            $clean[] = array('id' => (int) (isset($item['id']) ? $item['id'] : 0), 'name' => $name, 'category' => $category);
        }
        if (!$clean) {
            $errors[] = 'Keep at least one status.';
        }
        if ($errors) {
            return array_values(array_unique($errors));
        }

        $existing = array();
        foreach (self::all() as $status) {
            $existing[$status['id']] = $status;
        }
        foreach ($clean as $item) {
            if ($item['id'] && isset($existing[$item['id']])) {
                $keep[] = $item['id'];
            }
        }
        foreach (array_diff(array_keys($existing), $keep) as $removedId) {
            $q = new w2p_Database_Query();
            $q->addTable('cards');
            $q->addQuery('COUNT(*)');
            $q->addWhere('card_status = ' . (int) $removedId);
            if ((int) $q->loadResult() > 0) {
                $errors[] = 'The status "' . $existing[$removedId]['name'] . '" still has cards. Move them first.';
            }
        }
        if ($errors) {
            return $errors;
        }

        foreach (array_diff(array_keys($existing), $keep) as $removedId) {
            $q = new w2p_Database_Query();
            $q->setDelete('card_statuses');
            $q->addWhere('card_status_id = ' . (int) $removedId);
            $q->exec();
        }
        foreach ($clean as $order => $item) {
            $q = new w2p_Database_Query();
            $q->addTable('card_statuses');
            if ($item['id'] && isset($existing[$item['id']])) {
                $q->addUpdate('card_status_name', $item['name']);
                $q->addUpdate('card_status_category', $item['category']);
                $q->addUpdate('card_status_order', $order + 1);
                $q->addWhere('card_status_id = ' . $item['id']);
            } else {
                $q->addInsert('card_status_name', $item['name']);
                $q->addInsert('card_status_category', $item['category']);
                $q->addInsert('card_status_order', $order + 1);
            }
            $q->exec();
        }

        return array();
    }
}
