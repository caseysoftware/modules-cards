<?php
/**
 * @package web2project\modules\cards
 */

require_once __DIR__ . '/includes/CardRank.php';
require_once __DIR__ . '/includes/CardAccess.php';
require_once __DIR__ . '/includes/CardActivity.php';
require_once __DIR__ . '/includes/CardTags.php';
require_once __DIR__ . '/includes/CardStatuses.php';
require_once __DIR__ . '/includes/CardSettings.php';
require_once __DIR__ . '/includes/CardStore.php';
require_once __DIR__ . '/includes/CardBoard.php';
require_once __DIR__ . '/includes/CardMaintenance.php';
require_once __DIR__ . '/includes/CardJson.php';
require_once __DIR__ . '/includes/CardView.php';

/**
 * A card: the module's main class.
 *
 * web2project instantiates it for module hooks (hook_cron). Reads and writes
 * go through CCardBoard and CCardStore, which apply the module's own
 * permission rules (CCardAccess): cards follow their project and task, and
 * have no permission items of their own.
 */
class CCard extends w2p_Core_BaseObject
{
    public $card_id = null;
    public $card_project = null;
    public $card_task = null;
    public $card_parent = null;
    public $card_name = null;
    public $card_description = null;
    public $card_status = null;
    public $card_priority = null;
    public $card_owner = null;
    public $card_due_date = null;
    public $card_rank = null;
    public $card_creator = null;
    public $card_created = null;
    public $card_updator = null;
    public $card_updated = null;
    public $card_version = null;

    public function __construct()
    {
        parent::__construct('cards', 'card_id', 'cards');
    }

    /**
     * A card is visible when its project (and task, if any) are.
     *
     * @return bool
     */
    public function canView()
    {
        $access = new CCardAccess($this->_AppUI);

        return $this->card_id ? $access->canSeeCard($this->card_id) : $access->can('view');
    }

    /**
     * @return bool
     */
    public function canEdit()
    {
        $access = new CCardAccess($this->_AppUI);

        return $access->can('edit') && (!$this->card_id || $access->canSeeCard($this->card_id));
    }

    /**
     * @param string     $notUsed
     * @param int|null   $oid
     * @param array|null $joins
     *
     * @return bool
     */
    public function canDelete($notUsed = '', $oid = null, $joins = null)
    {
        $access = new CCardAccess($this->_AppUI);
        $id = $oid ? (int) $oid : (int) $this->card_id;

        return $access->can('delete') && $access->canSeeCard($id);
    }

    /**
     * The cards a user may see, as card_id => card_name.
     *
     * web2project asks this (through getViewableItems()) whenever it checks
     * a single card, for example Smart Search results and the History list.
     * Card visibility follows the project and the task (CCardAccess), so
     * the per-item lists the base class would read are not enough.
     *
     * @return array<int, string>
     */
    public function getAllowedRecords($uid, $fields = '*', $orderby = '', $index = null, $extra = null, $table_alias = '')
    {
        $access = new CCardAccess($this->_AppUI, (int) $uid);
        $q = new w2p_Database_Query();
        $q->addTable('cards', 'c');
        $q->addQuery('c.card_id, c.card_name');
        $access->restrict($q);
        $q->addOrder('c.card_id');

        return $q->loadHashList();
    }

    /**
     * Per-card rules from web2project's permission system (denials set for
     * individual cards in Roles or user permissions), as SQL conditions.
     *
     * @param int    $uid
     * @param string $index the card id column
     *
     * @return string[]
     */
    public function getModuleSQL($uid, $index)
    {
        return parent::getAllowedSQL($uid, $index);
    }

    /**
     * Smart Search: card names and descriptions.
     *
     * Smart Search checks each result with checkModuleItem(), which only
     * knows module rights, so the card rules (project and task visibility)
     * are added here as conditions on the query itself.
     *
     * @return array<string, mixed>
     */
    public function hook_search()
    {
        $access = new CCardAccess($this->_AppUI);

        return array(
            'table' => 'cards',
            'table_alias' => 'c',
            'table_module' => 'cards',
            'table_key' => 'c.card_id',
            'table_link' => 'index.php?m=cards&card=',
            'table_title' => 'Cards',
            'table_orderby' => 'c.card_name',
            'search_fields' => array('c.card_name', 'c.card_description'),
            'display_fields' => array('c.card_name', 'c.card_description'),
            'table_joins' => array(
                array('table' => 'projects', 'alias' => 'p', 'join' => 'p.project_id = c.card_project'),
                array('table' => 'tasks', 'alias' => 't', 'join' => 't.task_id = c.card_task'),
            ),
            'table_extra' => implode(' AND ', $access->conditions()),
        );
    }

    /**
     * The user's open cards with a due date: the cards they own that they
     * can still see, not in a closed status.
     *
     * @param int    $userId
     * @param string $from   Y-m-d, inclusive ('' for no lower bound)
     * @param string $to     Y-m-d, inclusive
     *
     * @return array<int, array<string, mixed>>
     */
    protected function dueCards($userId, $from, $to)
    {
        $access = new CCardAccess($this->_AppUI);
        $q = new w2p_Database_Query();
        $q->addTable('cards', 'c');
        $q->addQuery('c.card_id, c.card_name, c.card_description, c.card_due_date, c.card_updated,'
            . ' c.card_version, c.card_project, p.project_name, p.project_short_name');
        $access->restrict($q);
        $q->addWhere('c.card_owner = ' . (int) $userId);
        $q->addWhere('c.card_due_date IS NOT NULL');
        if ('' !== $from) {
            $q->addWhere('c.card_due_date >= ' . $q->quote($from));
        }
        $q->addWhere('c.card_due_date <= ' . $q->quote($to));
        $closed = CCardStatuses::closedIds();
        if ($closed) {
            $q->addWhere('c.card_status NOT IN (' . implode(',', $closed) . ')');
        }
        $q->addOrder('c.card_due_date, c.card_id');
        $q->setLimit(1000);

        return $q->loadList();
    }

    /**
     * The calendar feed (calendar.php): the user's open cards due in the
     * next 30 days, and any overdue ones, as all-day items on the due date.
     *
     * @param int $userId
     *
     * @return array<int, array<string, mixed>>
     */
    public function hook_calendar($userId)
    {
        $items = array();
        foreach ($this->dueCards($userId, '', date('Y-m-d', strtotime('+30 days'))) as $row) {
            $due = substr($row['card_due_date'], 0, 10);
            $key = CCardStore::key($row);
            $items[] = array(
                'id' => (int) $row['card_id'],
                'UID' => 'cards_' . (int) $row['card_id'],
                'name' => 'Due: ' . $key . ' ' . html_entity_decode((string) $row['card_name'], ENT_QUOTES, 'UTF-8'),
                'description' => (string) $row['card_description'],
                'startDate' => $due . ' 00:00:00',
                'endDate' => $due . ' 23:59:59',
                'updatedDate' => $row['card_updated'],
                'sequence' => (int) $row['card_version'],
                'url' => rtrim(W2P_BASE_URL, '/') . '/index.php?m=cards&card=' . (int) $row['card_id'],
                'project_id' => (int) $row['card_project'],
                'project_name' => html_entity_decode((string) $row['project_name'], ENT_QUOTES, 'UTF-8'),
            );
        }

        return $items;
    }

    /**
     * The Calendar module's month, week and day views: the user's open
     * cards on their due dates (a year either side of today).
     *
     * @param int $userId
     *
     * @return array<int, array<string, mixed>>
     */
    public function hook_calendar_links($userId)
    {
        $items = array();
        $from = date('Y-m-d', strtotime('-1 year'));
        $to = date('Y-m-d', strtotime('+1 year'));
        foreach ($this->dueCards($userId, $from, $to) as $row) {
            $items[] = array(
                'startDate' => str_replace('-', '', substr($row['card_due_date'], 0, 10)),
                'id' => (int) $row['card_id'],
                'key' => CCardStore::key($row),
                'name' => html_entity_decode((string) $row['card_name'], ENT_QUOTES, 'UTF-8'),
            );
        }

        return $items;
    }

    /**
     * One calendar entry, as the Calendar views draw them (text is HTML,
     * alt goes into a title attribute unescaped, so both are escaped here).
     *
     * @param mixed                $notUsed
     * @param array<string, mixed> $item from hook_calendar_links()
     *
     * @return array{href:string, alt:string, text:string}
     */
    public function getCalendarLink($notUsed, $item)
    {
        $label = $item['key'] . ' ' . $item['name'];

        return array(
            'href' => '?m=cards&card=' . (int) $item['id'],
            'alt' => CCardView::e($this->_AppUI->_('Card due', UI_OUTPUT_RAW) . ': ' . $label),
            'text' => '<span class="w2p-cards-cal">' . CCardView::e($label) . '</span>',
        );
    }

    /**
     * Run by queuescanner.php: the consistency sweep (modules-cards #3).
     */
    public function hook_cron()
    {
        $maintenance = new CCardMaintenance();
        $counts = $maintenance->sweep();
        if (array_sum($counts)) {
            error_log('Cards sweep: ' . json_encode($counts));
        }
    }
}
