<?php
/**
 * Board settings: soft WIP limits, which status columns are shown, and
 * which fields cards show. Stored as JSON, one row per project.
 *
 * Project 0 (DEFAULTS) holds the default configuration. The all-projects
 * view uses it, and so does any project that hasn't saved settings of its
 * own. Administrators change it from the all-projects view.
 *
 * @package web2project\modules\cards
 */
class CCardSettings
{
    /** The settings row that holds the defaults. */
    const DEFAULTS = 0;

    /** Fields a card can show on the board, and their built-in defaults. */
    const FIELDS = array(
        'key' => true, 'owner' => true, 'priority' => true, 'due' => true,
        'tags' => true, 'subcards' => true, 'dependencies' => true, 'task' => true,
        'project' => true,
    );

    /**
     * The default configuration (row 0, else the built-in defaults).
     *
     * @return array{columns: array<int, array{wip:int, shown:bool}>, fields: array<string, bool>}
     */
    public static function defaults()
    {
        $stored = self::stored(self::DEFAULTS);

        return self::normalize(is_array($stored) ? $stored : array());
    }

    /**
     * A project's settings; anything it hasn't set comes from the defaults.
     *
     * @param int $projectId
     *
     * @return array{columns: array<int, array{wip:int, shown:bool}>, fields: array<string, bool>}
     */
    public static function forProject($projectId)
    {
        if (self::DEFAULTS == (int) $projectId) {
            return self::defaults();
        }
        $stored = self::stored((int) $projectId);

        return self::normalize(is_array($stored) ? $stored : array(), self::defaults());
    }

    /**
     * @param int $projectId
     *
     * @return array<string, mixed>|null
     */
    protected static function stored($projectId)
    {
        $q = new w2p_Database_Query();
        $q->addTable('card_settings');
        $q->addQuery('settings_json');
        $q->addWhere('settings_project = ' . (int) $projectId);
        $json = $q->loadResult();
        $stored = $json ? json_decode((string) $json, true) : null;

        return is_array($stored) ? $stored : null;
    }

    /**
     * Keeps only known keys and sane values; missing ones come from $base,
     * else the built-in defaults.
     *
     * @param array<string, mixed>      $input
     * @param array<string, mixed>|null $base
     *
     * @return array{columns: array<int, array{wip:int, shown:bool}>, fields: array<string, bool>}
     */
    public static function normalize(array $input, $base = null)
    {
        $columns = array();
        foreach (CCardStatuses::all() as $status) {
            $id = $status['id'];
            $fallback = isset($base['columns'][$id]) ? $base['columns'][$id] : array('wip' => 0, 'shown' => true);
            $given = (isset($input['columns'][$id]) && is_array($input['columns'][$id])) ? $input['columns'][$id] : array();
            $wip = isset($given['wip']) ? (int) $given['wip'] : (int) $fallback['wip'];
            $columns[$id] = array(
                'wip' => max(0, min(999, $wip)),
                'shown' => isset($given['shown']) ? (bool) $given['shown'] : (bool) $fallback['shown'],
            );
        }

        $fields = array();
        foreach (self::FIELDS as $field => $default) {
            $fallback = isset($base['fields'][$field]) ? (bool) $base['fields'][$field] : $default;
            $fields[$field] = isset($input['fields'][$field]) ? (bool) $input['fields'][$field] : $fallback;
        }

        return array('columns' => $columns, 'fields' => $fields);
    }

    /**
     * @param int                  $projectId DEFAULTS for the default configuration
     * @param array<string, mixed> $input
     *
     * @return array{columns: array<int, array{wip:int, shown:bool}>, fields: array<string, bool>}
     */
    public static function save($projectId, array $input)
    {
        $base = (self::DEFAULTS == (int) $projectId) ? null : self::defaults();
        $settings = self::normalize($input, $base);
        $q = new w2p_Database_Query();
        $q->addTable('card_settings');
        $q->addReplace('settings_project', (int) $projectId);
        $q->addReplace('settings_json', json_encode($settings));
        $q->exec();

        return $settings;
    }

    /**
     * @param int[] $projectIds never the defaults row
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
