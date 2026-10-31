<?php
/**
 * Per-project board settings: soft WIP limits and which status columns are
 * shown, plus which fields cards show. Stored as JSON, one row per project.
 *
 * @package web2project\modules\cards
 */
class CCardSettings
{
    /** Fields a card can show on the board, with their defaults. */
    const FIELDS = array(
        'key' => true, 'owner' => true, 'priority' => true, 'due' => true,
        'tags' => true, 'subcards' => true, 'dependencies' => true, 'task' => true,
    );

    /**
     * @param int $projectId
     *
     * @return array{columns: array<int, array{wip:int, shown:bool}>, fields: array<string, bool>}
     */
    public static function forProject($projectId)
    {
        $q = new w2p_Database_Query();
        $q->addTable('card_settings');
        $q->addQuery('settings_json');
        $q->addWhere('settings_project = ' . (int) $projectId);
        $stored = json_decode((string) $q->loadResult(), true);

        return self::normalize(is_array($stored) ? $stored : array());
    }

    /**
     * Keeps only known keys and sane values.
     *
     * @param array<string, mixed> $input
     *
     * @return array{columns: array<int, array{wip:int, shown:bool}>, fields: array<string, bool>}
     */
    public static function normalize(array $input)
    {
        $columns = array();
        foreach (CCardStatuses::all() as $status) {
            $given = (isset($input['columns'][$status['id']]) && is_array($input['columns'][$status['id']]))
                ? $input['columns'][$status['id']] : array();
            $wip = isset($given['wip']) ? (int) $given['wip'] : 0;
            $columns[$status['id']] = array(
                'wip' => max(0, min(999, $wip)),
                'shown' => isset($given['shown']) ? (bool) $given['shown'] : true,
            );
        }

        $fields = array();
        foreach (self::FIELDS as $field => $default) {
            $fields[$field] = isset($input['fields'][$field]) ? (bool) $input['fields'][$field] : $default;
        }

        return array('columns' => $columns, 'fields' => $fields);
    }

    /**
     * @param int                  $projectId
     * @param array<string, mixed> $input
     *
     * @return array{columns: array<int, array{wip:int, shown:bool}>, fields: array<string, bool>}
     */
    public static function save($projectId, array $input)
    {
        $settings = self::normalize($input);
        $q = new w2p_Database_Query();
        $q->addTable('card_settings');
        $q->addReplace('settings_project', (int) $projectId);
        $q->addReplace('settings_json', json_encode($settings));
        $q->exec();

        return $settings;
    }

    /**
     * @param int[] $projectIds
     */
    public static function deleteFor(array $projectIds)
    {
        $ids = array_filter(array_map('intval', $projectIds));
        if (!$ids) {
            return;
        }
        $q = new w2p_Database_Query();
        $q->setDelete('card_settings');
        $q->addWhere('settings_project IN (' . implode(',', $ids) . ')');
        $q->exec();
    }
}
