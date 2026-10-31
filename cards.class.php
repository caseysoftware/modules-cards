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
