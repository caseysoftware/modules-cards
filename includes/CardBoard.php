<?php
/**
 * Reads for the board, the card drawer and the Task tab, already limited to
 * what the current user may see (CCardAccess).
 *
 * @package web2project\modules\cards
 */
class CCardBoard
{
    /** @var w2p_Core_CAppUI */
    protected $AppUI;

    /** @var CCardAccess */
    protected $access;

    public function __construct(w2p_Core_CAppUI $AppUI, CCardAccess $access)
    {
        $this->AppUI = $AppUI;
        $this->access = $access;
    }

    /** The board id that means "all projects". */
    const ALL = -1;

    /**
     * Everything the board needs: one project, or every project the user
     * may see (ALL).
     *
     * @param int $projectId
     *
     * @return array<string, mixed>
     */
    public function board($projectId)
    {
        $projectId = (int) $projectId;
        if (self::ALL === $projectId) {
            return $this->boardAll();
        }
        $q = new w2p_Database_Query();
        $q->addTable('projects');
        $q->addQuery('project_id, project_name, project_short_name');
        $q->addWhere('project_id = ' . $projectId);
        $project = $q->loadHash();
        if (!$project) {
            // No such project (deleted, or 0 for the defaults): show everything.
            return $this->boardAll();
        }

        return array(
            'all' => false,
            'project' => array(
                'id' => (int) $project['project_id'],
                'name' => html_entity_decode((string) $project['project_name'], ENT_QUOTES, 'UTF-8'),
                'short_name' => html_entity_decode((string) $project['project_short_name'], ENT_QUOTES, 'UTF-8'),
            ),
            'projects' => array(),
            'statuses' => CCardStatuses::all(),
            'settings' => CCardSettings::forProject($projectId),
            'cards' => $this->cardRows('(' . CCardAccess::effectiveProject() . ') = ' . $projectId),
            'tags' => $this->pairs(CCardTags::all()),
            'users' => $this->users(),
            'tasks' => $this->pairs($this->access->visibleTasks($projectId)),
            'can' => $this->rights($projectId),
        );
    }

    /**
     * The all-projects board: every card the user may see, grouped by
     * project within each column, with the default board settings. Cards
     * can change status (and priority, owner, tags) here, never project.
     *
     * @return array<string, mixed>
     */
    public function boardAll()
    {
        $projects = $this->access->visibleProjects();

        return array(
            'all' => true,
            'project' => array('id' => self::ALL, 'name' => $this->AppUI->_('All projects'), 'short_name' => ''),
            'projects' => $projects,
            'statuses' => CCardStatuses::all(),
            'settings' => CCardSettings::defaults(),
            'cards' => $this->cardRows('1 = 1', 'p.project_name, p.project_id, c.card_rank, c.card_id'),
            'tags' => $this->pairs(CCardTags::all()),
            'users' => $this->users(),
            'tasks' => array(),
            'can' => $this->rights(CCardSettings::DEFAULTS),
        );
    }

    /**
     * @param int $projectId CCardSettings::DEFAULTS for the all-projects view
     *
     * @return array<string, bool>
     */
    protected function rights($projectId)
    {
        return array(
            'add' => $this->access->can('add'),
            'edit' => $this->access->can('edit'),
            'delete' => $this->access->can('delete'),
            'configure' => $this->access->canConfigure($projectId),
            'statuses' => $this->access->canManageStatuses(),
        );
    }

    /**
     * Visible cards matching a condition on `cards c` (joined with
     * `tasks t`), shaped for the board, in rank order.
     *
     * @param string $where
     * @param string $order
     *
     * @return array<int, array<string, mixed>>
     */
    public function cardRows($where, $order = 'c.card_rank, c.card_id')
    {
        $q = new w2p_Database_Query();
        $q->addTable('cards', 'c');
        $q->addQuery('c.*, p.project_name, p.project_short_name, p.project_color_identifier, t.task_name, t.task_project');
        $this->access->restrict($q);
        $q->addWhere($where);
        $q->addOrder($order);
        $rows = $q->loadList();

        $ids = array();
        foreach ($rows as $row) {
            $ids[] = (int) $row['card_id'];
        }
        $tags = CCardTags::forCards($ids);
        $waitsOn = $this->waitsOn($ids);
        $closed = CCardStatuses::closedIds();

        // Subcard counts: every visible child, whether or not it is in $rows.
        $children = array();
        if ($ids) {
            $k = new w2p_Database_Query();
            $k->addTable('cards', 'c');
            $k->addQuery('c.card_parent, c.card_status');
            $this->access->restrict($k);
            $k->addWhere('c.card_parent IN (' . implode(',', $ids) . ')');
            foreach ($k->loadList() as $kid) {
                $children[(int) $kid['card_parent']][] = $kid;
            }
        }

        $cards = array();
        foreach ($rows as $row) {
            $id = (int) $row['card_id'];
            $kids = isset($children[$id]) ? $children[$id] : array();
            $kidsDone = 0;
            foreach ($kids as $kid) {
                if (in_array((int) $kid['card_status'], $closed, true)) {
                    $kidsDone++;
                }
            }
            $prefix = trim((string) $row['project_short_name']);
            $cards[] = array(
                'id' => $id,
                'key' => ('' !== $prefix ? $prefix : 'CARD') . '-' . $id,
                'name' => $row['card_name'],
                'project' => (int) $row['card_project'],
                'project_name' => html_entity_decode((string) $row['project_name'], ENT_QUOTES, 'UTF-8'),
                'project_color' => preg_match('/^[0-9a-fA-F]{6}$/', (string) $row['project_color_identifier']) ? $row['project_color_identifier'] : '',
                'status' => (int) $row['card_status'],
                'priority' => (int) $row['card_priority'],
                'owner' => (int) $row['card_owner'],
                'due' => $row['card_due_date'] ? substr($row['card_due_date'], 0, 10) : '',
                'task' => (int) $row['card_task'],
                'task_name' => (int) $row['card_task'] ? html_entity_decode((string) $row['task_name'], ENT_QUOTES, 'UTF-8') : '',
                'parent' => (int) $row['card_parent'],
                'rank' => $row['card_rank'],
                'version' => (int) $row['card_version'],
                'tags' => isset($tags[$id]) ? $tags[$id] : array(),
                'waits_on' => isset($waitsOn[$id]) ? $waitsOn[$id] : array(),
                'subcards' => count($kids),
                'subcards_done' => $kidsDone,
            );
        }

        return $cards;
    }

    /**
     * For each card, the open cards it still waits on. Blockers the user
     * can see come with their name; ones they can't (a private task) are
     * only counted, as a single entry with id 0 and hidden set.
     *
     * @param int[] $ids
     *
     * @return array<int, array<int, array{id:int, key:string, name:string, hidden?:int}>>
     */
    protected function waitsOn(array $ids)
    {
        if (!$ids) {
            return array();
        }
        $closed = CCardStatuses::closedIds();
        $open = $closed ? ' AND c.card_status NOT IN (' . implode(',', $closed) . ')' : '';

        // Open blockers the user may see, with their names.
        $q = new w2p_Database_Query();
        $q->addTable('card_dependencies', 'd');
        $q->innerJoin('cards', 'c', 'c.card_id = d.dependency_requires');
        $q->addQuery('d.dependency_card, c.card_id, c.card_name, p.project_short_name');
        $this->access->restrict($q);
        $q->addWhere('d.dependency_card IN (' . implode(',', $ids) . ')' . $open);
        $q->addOrder('c.card_rank, c.card_id');
        $result = array();
        $shown = array();
        foreach ($q->loadList() as $row) {
            $prefix = trim((string) $row['project_short_name']);
            $result[(int) $row['dependency_card']][] = array(
                'id' => (int) $row['card_id'],
                'key' => ('' !== $prefix ? $prefix : 'CARD') . '-' . (int) $row['card_id'],
                'name' => html_entity_decode((string) $row['card_name'], ENT_QUOTES, 'UTF-8'),
            );
            $shown[(int) $row['dependency_card']] = (isset($shown[(int) $row['dependency_card']]) ? $shown[(int) $row['dependency_card']] : 0) + 1;
        }

        // All open blockers, to count the ones the user can't see.
        $q = new w2p_Database_Query();
        $q->addTable('card_dependencies', 'd');
        $q->innerJoin('cards', 'c', 'c.card_id = d.dependency_requires');
        $q->addQuery('d.dependency_card, COUNT(*) AS blockers');
        $q->addWhere('d.dependency_card IN (' . implode(',', $ids) . ')' . $open);
        $q->addGroup('d.dependency_card');
        foreach ($q->loadList() as $row) {
            $cardId = (int) $row['dependency_card'];
            $hidden = (int) $row['blockers'] - (isset($shown[$cardId]) ? $shown[$cardId] : 0);
            if ($hidden > 0) {
                $result[$cardId][] = array('id' => 0, 'key' => '', 'name' => '', 'hidden' => $hidden);
            }
        }

        return $result;
    }

    /**
     * Everything the drawer shows for one card.
     *
     * @param int $cardId
     *
     * @return array<string, mixed>|null null if the user can't see it
     */
    public function card($cardId)
    {
        $rows = $this->cardRows('c.card_id = ' . (int) $cardId);
        if (!$rows) {
            return null;
        }
        $card = $rows[0];
        $row = CCardStore::load($cardId);
        $card['description'] = (string) $row['card_description'];
        $card['created'] = $row['card_created'];
        $card['creator_name'] = CCardStore::userName((int) $row['card_creator']);

        $card['subcard_list'] = $this->cardRows('c.card_parent = ' . (int) $cardId);
        $closed = CCardStatuses::closedIds();
        $card['subcards'] = count($card['subcard_list']);
        $card['subcards_done'] = 0;
        foreach ($card['subcard_list'] as $sub) {
            if (in_array($sub['status'], $closed, true)) {
                $card['subcards_done']++;
            }
        }
        if ($card['parent']) {
            $parent = $this->cardRows('c.card_id = ' . (int) $card['parent']);
            $card['parent_card'] = $parent ? $parent[0] : null;
        }

        $q = new w2p_Database_Query();
        $q->addTable('card_dependencies');
        $q->addQuery('dependency_requires');
        $q->addWhere('dependency_card = ' . (int) $cardId);
        $waitsOnIds = array_map('intval', $q->loadColumn());
        $q = new w2p_Database_Query();
        $q->addTable('card_dependencies');
        $q->addQuery('dependency_card');
        $q->addWhere('dependency_requires = ' . (int) $cardId);
        $blocksIds = array_map('intval', $q->loadColumn());

        $card['waits_on_list'] = $waitsOnIds ? $this->cardRows('c.card_id IN (' . implode(',', $waitsOnIds) . ')') : array();
        $card['blocks_list'] = $blocksIds ? $this->cardRows('c.card_id IN (' . implode(',', $blocksIds) . ')') : array();
        // Relationships with cards this user can't see are counted, not shown.
        $card['hidden_relations'] = count($waitsOnIds) + count($blocksIds)
            - count($card['waits_on_list']) - count($card['blocks_list']);

        $card['activity'] = CCardActivity::forCard($cardId);
        // The drawer's task picker: this card's project's tasks, also when
        // it is opened from the all-projects view.
        $card['tasks'] = $this->pairs($this->access->visibleTasks($card['project']));
        $card['tag_names'] = CCardTags::names($card['tags']);

        return $card;
    }

    /**
     * Cards on a task and, optionally, on its subtasks (marked "via").
     *
     * @param int  $taskId
     * @param bool $withSubtasks
     *
     * @return array<int, array<string, mixed>>
     */
    public function taskCards($taskId, $withSubtasks)
    {
        $taskIds = array((int) $taskId);
        if ($withSubtasks) {
            $frontier = array((int) $taskId);
            while ($frontier) {
                $q = new w2p_Database_Query();
                $q->addTable('tasks');
                $q->addQuery('task_id');
                $q->addWhere('task_parent IN (' . implode(',', $frontier) . ')');
                $q->addWhere('task_parent <> task_id');
                $kids = array_diff(array_map('intval', $q->loadColumn()), $taskIds);
                $taskIds = array_merge($taskIds, $kids);
                $frontier = $kids;
            }
        }

        return $this->cardRows('c.card_task IN (' . implode(',', $taskIds) . ')');
    }

    /**
     * Users who can use the Cards module, for owner pickers.
     *
     * @return array<int, array{id:int, name:string}>
     */
    public function users()
    {
        $perms = $this->AppUI->acl();
        $users = array();
        foreach ($perms->getPermittedUsers('cards') as $id => $name) {
            $users[] = array('id' => (int) $id, 'name' => html_entity_decode((string) $name, ENT_QUOTES, 'UTF-8'));
        }

        return $users;
    }

    /**
     * @param array<int, string> $hash
     *
     * @return array<int, array{id:int, name:string}>
     */
    protected function pairs(array $hash)
    {
        $list = array();
        foreach ($hash as $id => $name) {
            $list[] = array('id' => (int) $id, 'name' => html_entity_decode((string) $name, ENT_QUOTES, 'UTF-8'));
        }

        return $list;
    }
}
