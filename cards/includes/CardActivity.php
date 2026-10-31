<?php
/**
 * The card activity log: every change to a card, and comments.
 *
 * Rows are written as things happen and never edited. Values are stored as
 * they read at the time (a status or tag name, not just its id), so the log
 * still makes sense after renames. User 0 is "System" (the cron sweep).
 *
 * @package web2project\modules\cards
 */
class CCardActivity
{
    const CREATED = 'created';
    const CHANGED = 'changed';
    const COMMENT = 'comment';
    const MOVED_PROJECT = 'moved_project';
    const DEPENDENCY_ADDED = 'dependency_added';
    const DEPENDENCY_REMOVED = 'dependency_removed';
    const SUBCARD_ADDED = 'subcard_added';
    const SUBCARD_REMOVED = 'subcard_removed';
    const TAG_ADDED = 'tag_added';
    const TAG_REMOVED = 'tag_removed';
    const SYSTEM = 'system';

    /**
     * @param int    $cardId
     * @param int    $userId   0 for the system
     * @param string $type
     * @param string $field
     * @param mixed  $old
     * @param mixed  $new
     * @param int    $related  another card involved (dependency, subcard)
     */
    public static function log($cardId, $userId, $type, $field = '', $old = null, $new = null, $related = 0)
    {
        $q = new w2p_Database_Query();
        $q->addTable('card_activity');
        $q->addInsert('activity_card', (int) $cardId);
        $q->addInsert('activity_user', (int) $userId);
        $q->addInsert('activity_date', $q->dbfnNowWithTZ());
        $q->addInsert('activity_type', (string) $type);
        $q->addInsert('activity_field', (string) $field);
        $q->addInsert('activity_old', (null === $old) ? '' : (string) $old);
        $q->addInsert('activity_new', (null === $new) ? '' : (string) $new);
        $q->addInsert('activity_related', (int) $related);
        $q->exec();
    }

    /**
     * A card's activity, newest first, with the user's name.
     *
     * @param int $cardId
     * @param int $limit
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forCard($cardId, $limit = 200)
    {
        $q = new w2p_Database_Query();
        $q->addTable('card_activity', 'a');
        $q->addQuery('a.*, co.contact_first_name, co.contact_last_name');
        $q->leftJoin('users', 'u', 'u.user_id = a.activity_user');
        $q->leftJoin('contacts', 'co', 'co.contact_id = u.user_contact');
        $q->addWhere('a.activity_card = ' . (int) $cardId);
        $q->addOrder('a.activity_id DESC');
        $q->setLimit((int) $limit);

        $rows = array();
        foreach ($q->loadList() as $row) {
            $name = html_entity_decode(trim($row['contact_first_name'] . ' ' . $row['contact_last_name']), ENT_QUOTES, 'UTF-8');
            $rows[] = array(
                'id' => (int) $row['activity_id'],
                'user' => (int) $row['activity_user'],
                'user_name' => $row['activity_user'] ? ($name ? $name : '#' . $row['activity_user']) : 'System',
                'date' => $row['activity_date'],
                'type' => $row['activity_type'],
                'field' => $row['activity_field'],
                'old' => $row['activity_old'],
                'new' => $row['activity_new'],
                'related' => (int) $row['activity_related'],
            );
        }

        return $rows;
    }

    /**
     * Deletes a card's activity (when the card itself is deleted).
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
        $q->setDelete('card_activity');
        $q->addWhere('activity_card IN (' . implode(',', $ids) . ')');
        $q->exec();
    }
}
