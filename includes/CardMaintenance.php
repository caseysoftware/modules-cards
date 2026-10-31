<?php
/**
 * Keeps cards consistent with their projects and tasks (modules-cards #3).
 *
 * Rules:
 *  - project deleted: its cards are deleted
 *  - task deleted: the cards linked to it are deleted (with their subcards)
 *  - task moved to another project: the cards linked to it move too, with
 *    their subcards; relationships that would cross projects are removed
 *    and logged on both cards; each move is logged
 *
 * Today the cron sweep (CCard::hook_cron) applies these. When core can tell
 * modules about task and project changes (web2project/web2project#526), the
 * same handlers run in real time; the sweep stays as the backstop.
 *
 * Every change is logged as user 0, "System".
 *
 * @package web2project\modules\cards
 */
class CCardMaintenance
{
    /** Cards handled per check per run. */
    const BATCH = 500;

    /** @var CCardStore */
    protected $store;

    /** @var array<string, int> */
    protected $counts = array();

    public function __construct()
    {
        $this->store = new CCardStore(0);
    }

    /**
     * Runs every check once. Safe to run any number of times.
     *
     * @return array<string, int> what was changed, by check
     */
    public function sweep()
    {
        $this->counts = array(
            'project_gone' => 0, 'task_gone' => 0, 'moved_with_task' => 0,
            'dangling_links' => 0, 'cross_project_links' => 0, 'cycles_found' => 0,
        );

        $this->deleteWhereProjectGone();
        $this->deleteWhereTaskGone();
        $this->followMovedTasks();
        $this->removeDanglingLinks();
        $this->reportCycles();

        return $this->counts;
    }

    // ------------------------------------------- handlers (also for #526)

    /**
     * A project was deleted: delete its cards.
     *
     * @param int $projectId
     */
    public function projectDeleted($projectId)
    {
        $q = new w2p_Database_Query();
        $q->addTable('cards');
        $q->addQuery('card_id');
        $q->addWhere('card_project = ' . (int) $projectId);
        $q->addWhere('card_parent = 0');
        foreach ($q->loadColumn() as $cardId) {
            $this->counts['project_gone'] += count($this->store->delete((int) $cardId));
        }
        // Subcards whose parent was elsewhere (shouldn't happen) go too.
        $q = new w2p_Database_Query();
        $q->addTable('cards');
        $q->addQuery('card_id');
        $q->addWhere('card_project = ' . (int) $projectId);
        foreach ($q->loadColumn() as $cardId) {
            $this->counts['project_gone'] += count($this->store->delete((int) $cardId));
        }
        CCardSettings::deleteFor(array($projectId));
    }

    /**
     * Tasks were deleted: delete the cards linked to them.
     *
     * @param int[] $taskIds
     */
    public function tasksDeleted(array $taskIds)
    {
        $ids = array_filter(array_map('intval', $taskIds));
        if (!$ids) {
            return;
        }
        $q = new w2p_Database_Query();
        $q->addTable('cards');
        $q->addQuery('card_id');
        $q->addWhere('card_task IN (' . implode(',', $ids) . ')');
        foreach ($q->loadColumn() as $cardId) {
            // A subcard may already be gone with its parent.
            $this->counts['task_gone'] += count($this->store->delete((int) $cardId));
        }
    }

    /**
     * Tasks moved to another project: move their cards and those cards'
     * subcards, then cut relationships that now cross projects.
     *
     * @param int[] $taskIds    the moved task and its subtasks
     * @param int   $newProject
     */
    public function tasksMoved(array $taskIds, $newProject)
    {
        $ids = array_filter(array_map('intval', $taskIds));
        $newProject = (int) $newProject;
        if (!$ids || !$newProject) {
            return;
        }
        $q = new w2p_Database_Query();
        $q->addTable('cards');
        $q->addQuery('card_id');
        $q->addWhere('card_task IN (' . implode(',', $ids) . ')');
        $q->addWhere('card_project <> ' . $newProject);

        $moving = array();
        foreach ($q->loadColumn() as $cardId) {
            foreach (CCardStore::withDescendants((int) $cardId) as $id) {
                $moving[$id] = true;
            }
        }
        $moving = array_keys($moving);
        if (!$moving) {
            return;
        }

        $newName = $this->projectName($newProject);
        foreach ($moving as $cardId) {
            $card = CCardStore::load($cardId);
            if (!$card || (int) $card['card_project'] === $newProject) {
                continue;
            }
            $oldKey = CCardStore::key($card);
            $oldName = $this->projectName((int) $card['card_project']);

            $u = new w2p_Database_Query();
            $u->addTable('cards');
            $u->addUpdate('card_project', $newProject);
            $u->addUpdate('card_version', 'card_version + 1', false, true);
            $u->addWhere('card_id = ' . (int) $cardId);
            $u->exec();

            $card['card_project'] = $newProject;
            $via = (int) $card['card_task'] ? ' with task ' . CCardStore::taskLabel((int) $card['card_task']) : ' with its parent card';
            CCardActivity::log($cardId, 0, CCardActivity::MOVED_PROJECT, 'project',
                $oldName . ' (' . $oldKey . ')', $newName . ' (' . CCardStore::key($card) . ')' . $via);
            $this->counts['moved_with_task']++;
        }

        // The moved cards' ranks came from another project; rebalancing keeps
        // the merged order and the keys short.
        $this->store->rebalance($newProject);
        $this->cutCrossProjectLinks($moving);
    }

    // --------------------------------------------------------------- checks

    protected function deleteWhereProjectGone()
    {
        $q = new w2p_Database_Query();
        $q->addTable('cards', 'c');
        $q->leftJoin('projects', 'p', 'p.project_id = c.card_project');
        $q->addQuery('DISTINCT c.card_project');
        $q->addWhere('p.project_id IS NULL');
        $q->setLimit(self::BATCH);
        foreach ($q->loadColumn() as $projectId) {
            $this->projectDeleted((int) $projectId);
        }
    }

    protected function deleteWhereTaskGone()
    {
        $q = new w2p_Database_Query();
        $q->addTable('cards', 'c');
        $q->leftJoin('tasks', 't', 't.task_id = c.card_task');
        $q->addQuery('DISTINCT c.card_task');
        $q->addWhere('c.card_task > 0 AND t.task_id IS NULL');
        $q->setLimit(self::BATCH);
        $this->tasksDeleted(array_map('intval', $q->loadColumn()));
    }

    protected function followMovedTasks()
    {
        $q = new w2p_Database_Query();
        $q->addTable('cards', 'c');
        $q->innerJoin('tasks', 't', 't.task_id = c.card_task');
        $q->addQuery('DISTINCT t.task_id, t.task_project');
        $q->addWhere('t.task_project <> c.card_project');
        $q->setLimit(self::BATCH);
        $byProject = array();
        foreach ($q->loadList() as $row) {
            $byProject[(int) $row['task_project']][] = (int) $row['task_id'];
        }
        foreach ($byProject as $projectId => $taskIds) {
            $this->tasksMoved($taskIds, $projectId);
        }
    }

    /**
     * Relationship rows pointing at cards that no longer exist.
     */
    protected function removeDanglingLinks()
    {
        $q = new w2p_Database_Query();
        $q->addTable('card_dependencies', 'd');
        $q->leftJoin('cards', 'a', 'a.card_id = d.dependency_card');
        $q->leftJoin('cards', 'b', 'b.card_id = d.dependency_requires');
        $q->addQuery('d.dependency_card, d.dependency_requires, a.card_id AS a_id, b.card_id AS b_id');
        $q->addWhere('a.card_id IS NULL OR b.card_id IS NULL');
        $q->setLimit(self::BATCH);
        foreach ($q->loadList() as $row) {
            $card = (int) $row['dependency_card'];
            $requires = (int) $row['dependency_requires'];
            $d = new w2p_Database_Query();
            $d->setDelete('card_dependencies');
            $d->addWhere('dependency_card = ' . $card . ' AND dependency_requires = ' . $requires);
            $d->exec();
            if ($row['a_id']) {
                CCardActivity::log($card, 0, CCardActivity::DEPENDENCY_REMOVED, 'waits_on', '#' . $requires, 'deleted', $requires);
            }
            if ($row['b_id']) {
                CCardActivity::log($requires, 0, CCardActivity::DEPENDENCY_REMOVED, 'blocks', '#' . $card, 'deleted', $card);
            }
            $this->counts['dangling_links']++;
        }

        $q = new w2p_Database_Query();
        $q->addTable('cards', 'c');
        $q->leftJoin('cards', 'p', 'p.card_id = c.card_parent');
        $q->addQuery('c.card_id');
        $q->addWhere('c.card_parent > 0 AND p.card_id IS NULL');
        $q->setLimit(self::BATCH);
        foreach ($q->loadColumn() as $cardId) {
            $u = new w2p_Database_Query();
            $u->addTable('cards');
            $u->addUpdate('card_parent', 0);
            $u->addWhere('card_id = ' . (int) $cardId);
            $u->exec();
            CCardActivity::log($cardId, 0, CCardActivity::CHANGED, 'parent', '(deleted card)', '');
            $this->counts['dangling_links']++;
        }

        // Any relationship that crosses projects, however it got there.
        $q = new w2p_Database_Query();
        $q->addTable('card_dependencies', 'd');
        $q->innerJoin('cards', 'a', 'a.card_id = d.dependency_card');
        $q->innerJoin('cards', 'b', 'b.card_id = d.dependency_requires');
        $q->addQuery('a.card_id');
        $q->addWhere('a.card_project <> b.card_project');
        $q->setLimit(self::BATCH);
        $ids = array_map('intval', $q->loadColumn());
        $q = new w2p_Database_Query();
        $q->addTable('cards', 'c');
        $q->innerJoin('cards', 'p', 'p.card_id = c.card_parent');
        $q->addQuery('c.card_id');
        $q->addWhere('c.card_project <> p.card_project');
        $q->setLimit(self::BATCH);
        $ids = array_merge($ids, array_map('intval', $q->loadColumn()));
        if ($ids) {
            $this->cutCrossProjectLinks(array_unique($ids));
        }
    }

    /**
     * Removes parent links and dependencies between these cards and cards
     * in another project, logging on both sides.
     *
     * @param int[] $cardIds
     */
    protected function cutCrossProjectLinks(array $cardIds)
    {
        $list = implode(',', array_map('intval', $cardIds));

        $q = new w2p_Database_Query();
        $q->addTable('card_dependencies', 'd');
        $q->innerJoin('cards', 'a', 'a.card_id = d.dependency_card');
        $q->innerJoin('cards', 'b', 'b.card_id = d.dependency_requires');
        $q->addQuery('a.card_id AS a_id, b.card_id AS b_id');
        $q->addWhere('(a.card_id IN (' . $list . ') OR b.card_id IN (' . $list . '))');
        $q->addWhere('a.card_project <> b.card_project');
        foreach ($q->loadList() as $row) {
            $a = CCardStore::load($row['a_id']);
            $b = CCardStore::load($row['b_id']);
            $d = new w2p_Database_Query();
            $d->setDelete('card_dependencies');
            $d->addWhere('dependency_card = ' . (int) $row['a_id'] . ' AND dependency_requires = ' . (int) $row['b_id']);
            $d->exec();
            CCardActivity::log($row['a_id'], 0, CCardActivity::DEPENDENCY_REMOVED, 'waits_on', CCardStore::label($b), 'now in another project', $row['b_id']);
            CCardActivity::log($row['b_id'], 0, CCardActivity::DEPENDENCY_REMOVED, 'blocks', CCardStore::label($a), 'now in another project', $row['a_id']);
            $this->counts['cross_project_links']++;
        }

        $q = new w2p_Database_Query();
        $q->addTable('cards', 'c');
        $q->innerJoin('cards', 'p', 'p.card_id = c.card_parent');
        $q->addQuery('c.card_id, p.card_id AS parent_id');
        $q->addWhere('(c.card_id IN (' . $list . ') OR p.card_id IN (' . $list . '))');
        $q->addWhere('c.card_project <> p.card_project');
        foreach ($q->loadList() as $row) {
            $child = CCardStore::load($row['card_id']);
            $parent = CCardStore::load($row['parent_id']);
            $u = new w2p_Database_Query();
            $u->addTable('cards');
            $u->addUpdate('card_parent', 0);
            $u->addWhere('card_id = ' . (int) $row['card_id']);
            $u->exec();
            CCardActivity::log($row['card_id'], 0, CCardActivity::CHANGED, 'parent', CCardStore::label($parent), '(none: parent is now in another project)');
            CCardActivity::log($row['parent_id'], 0, CCardActivity::SUBCARD_REMOVED, '', CCardStore::label($child), 'now in another project', $row['card_id']);
            $this->counts['cross_project_links']++;
        }
    }

    /**
     * Writes are checked for loops, so none should exist. If one does, say
     * so (error log and the card's activity) rather than guess which link
     * to break.
     */
    protected function reportCycles()
    {
        $q = new w2p_Database_Query();
        $q->addTable('card_dependencies', 'd');
        $q->innerJoin('cards', 'c', 'c.card_id = d.dependency_card');
        $q->addQuery('DISTINCT c.card_project');
        foreach ($q->loadColumn() as $projectId) {
            foreach ($this->findCycleCards((int) $projectId) as $cardId) {
                error_log('Cards: dependency loop through card ' . $cardId . ' in project ' . $projectId);
                CCardActivity::log($cardId, 0, CCardActivity::SYSTEM, 'dependencies', '', 'This card is part of a dependency loop. Remove one of the links.');
                $this->counts['cycles_found']++;
            }
        }
    }

    /**
     * Cards that sit on a dependency loop in a project.
     *
     * @param int $projectId
     *
     * @return int[]
     */
    public function findCycleCards($projectId)
    {
        $q = new w2p_Database_Query();
        $q->addTable('cards');
        $q->addQuery('card_id');
        $q->addWhere('card_project = ' . (int) $projectId);
        $found = array();
        foreach ($q->loadColumn() as $cardId) {
            if (in_array((int) $cardId, $this->store->dependsOnTransitively((int) $cardId, $projectId), true)) {
                $found[] = (int) $cardId;
            }
        }

        return $found;
    }

    /**
     * @param int $projectId
     *
     * @return string
     */
    protected function projectName($projectId)
    {
        $q = new w2p_Database_Query();
        $q->addTable('projects');
        $q->addQuery('project_name');
        $q->addWhere('project_id = ' . (int) $projectId);

        return html_entity_decode((string) $q->loadResult(), ENT_QUOTES, 'UTF-8');
    }
}
