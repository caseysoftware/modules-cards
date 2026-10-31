<?php
/**
 * Who may see and change which cards.
 *
 * Cards have no permission items of their own. A user sees a card when:
 *  - they have view access to the Cards module,
 *  - they may view the card's project (projects ACL), and
 *  - the card has no task, or they may see its task: the tasks ACL plus the
 *    task's own access level (public, protected, participant, private), the
 *    same rules as CTask::canAccess().
 *
 * Changing cards also needs the matching Cards module right (add, edit,
 * delete). Everything is written as SQL conditions so a board loads in one
 * query, and the same conditions decide single-card checks.
 *
 * "Validate links on read": queries also join the project and the task, so a
 * card whose project or task no longer exists is never shown, and a card
 * whose task moved to another project shows under the task's project.
 *
 * @package web2project\modules\cards
 */
class CCardAccess
{
    /** @var w2p_Core_CAppUI */
    protected $AppUI;

    /** @var int */
    protected $userId;

    public function __construct(w2p_Core_CAppUI $AppUI)
    {
        $this->AppUI = $AppUI;
        $this->userId = (int) $AppUI->user_id;
    }

    /**
     * Module-level right on Cards: access, view, add, edit or delete.
     *
     * @param string $right
     *
     * @return bool
     */
    public function can($right)
    {
        if (!$this->userId) {
            return false;
        }
        $perms = $this->AppUI->acl();
        if (!$perms->checkModule('cards', 'access')) {
            return false;
        }
        return ('access' == $right) ? true : (bool) $perms->checkModule('cards', $right);
    }

    /**
     * Adds the joins and conditions that limit a query on `cards c` to the
     * cards this user may see. Joins `projects p` and `tasks t`.
     *
     * @param w2p_Database_Query $q
     *
     * @return w2p_Database_Query
     */
    public function restrict(w2p_Database_Query $q)
    {
        $q->innerJoin('projects', 'p', 'p.project_id = c.card_project');
        $q->leftJoin('tasks', 't', 't.task_id = c.card_task');

        if (!$this->can('view')) {
            $q->addWhere('0 = 1');
            return $q;
        }

        // Projects the user may view (projects ACL).
        $project = new CProject();
        foreach ($project->getAllowedSQL($this->userId, 'c.card_project') as $condition) {
            $q->addWhere($condition);
        }

        // A linked task must still exist and be visible.
        $q->addWhere('(c.card_task = 0 OR (t.task_id IS NOT NULL AND ' . $this->taskCondition('t') . '))');

        return $q;
    }

    /**
     * SQL condition: the user may see task alias $t.
     *
     * @param string $t
     *
     * @return string
     */
    public function taskCondition($t)
    {
        $task = new CTask();
        $acl = $task->getAllowedSQL($this->userId, $t . '.task_id');
        $parts = $acl ? $acl : array('1 = 1');

        // Admins (System view) see every task, as in CTask::canAccess().
        if (!canView('system')) {
            $uid = $this->userId;
            $assigned = 'EXISTS (SELECT 1 FROM user_tasks ut WHERE ut.task_id = ' . $t . '.task_id AND ut.user_id = ' . $uid . ')';
            $sameCompany = 'EXISTS (SELECT 1 FROM users u1 JOIN users u2 ON u1.user_company = u2.user_company'
                . ' WHERE u1.user_id = ' . $uid . ' AND u2.user_id = ' . $t . '.task_owner)';
            $parts[] = '(' . $t . '.task_owner = ' . $uid
                . ' OR ' . $t . '.task_access = ' . CTask::ACCESS_PUBLIC
                . ' OR (' . $t . '.task_access = ' . CTask::ACCESS_PARTICIPANT . ' AND ' . $assigned . ')'
                . ' OR (' . $t . '.task_access = ' . CTask::ACCESS_PROTECTED . ' AND ' . $assigned . ' AND ' . $sameCompany . '))';
        }

        return '(' . implode(' AND ', $parts) . ')';
    }

    /**
     * The project a card shows under: its task's project when it has a task
     * (the task wins until the cron sweep moves the card), else its own.
     *
     * @return string SQL expression
     */
    public static function effectiveProject()
    {
        return 'CASE WHEN c.card_task > 0 THEN t.task_project ELSE c.card_project END';
    }

    /**
     * Whether the user may see this card.
     *
     * @param int $cardId
     *
     * @return bool
     */
    public function canSeeCard($cardId)
    {
        $q = new w2p_Database_Query();
        $q->addTable('cards', 'c');
        $q->addQuery('c.card_id');
        $q->addWhere('c.card_id = ' . (int) $cardId);
        $this->restrict($q);

        return (bool) $q->loadResult();
    }

    /**
     * Whether the user may view this project (and so its cards).
     *
     * @param int $projectId
     *
     * @return bool
     */
    public function canSeeProject($projectId)
    {
        if (!$this->can('view') || !$projectId) {
            return false;
        }
        $q = new w2p_Database_Query();
        $q->addTable('projects', 'p');
        $q->addQuery('p.project_id');
        $q->addWhere('p.project_id = ' . (int) $projectId);
        $project = new CProject();
        foreach ($project->getAllowedSQL($this->userId, 'p.project_id') as $condition) {
            $q->addWhere($condition);
        }

        return (bool) $q->loadResult();
    }

    /**
     * Whether the user may see this task, and it belongs to $projectId.
     *
     * @param int $taskId
     * @param int $projectId
     *
     * @return bool
     */
    public function canSeeTask($taskId, $projectId)
    {
        $q = new w2p_Database_Query();
        $q->addTable('tasks', 't');
        $q->addQuery('t.task_id');
        $q->addWhere('t.task_id = ' . (int) $taskId);
        $q->addWhere('t.task_project = ' . (int) $projectId);
        $q->addWhere($this->taskCondition('t'));

        return (bool) $q->loadResult();
    }

    /**
     * The tasks of a project this user may see, for the task picker.
     *
     * @param int $projectId
     *
     * @return array<int, string> task_id => task_name
     */
    public function visibleTasks($projectId)
    {
        $q = new w2p_Database_Query();
        $q->addTable('tasks', 't');
        $q->addQuery('t.task_id, t.task_name');
        $q->addWhere('t.task_project = ' . (int) $projectId);
        $q->addWhere($this->taskCondition('t'));
        $q->addOrder('t.task_start_date, t.task_name');

        return $q->loadHashList();
    }

    /**
     * Whether the user may change the board settings of a project: Cards
     * edit plus edit rights on the project itself.
     *
     * @param int $projectId
     *
     * @return bool
     */
    public function canConfigure($projectId)
    {
        return $this->can('edit') && canEdit('projects', (int) $projectId);
    }

    /**
     * Whether the user may change the global status list (admins).
     *
     * @return bool
     */
    public function canManageStatuses()
    {
        return $this->can('edit') && canEdit('system');
    }
}
