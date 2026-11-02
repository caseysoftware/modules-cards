<?php
/**
 * "Cards" tab on the task view: the task's cards, and its subtasks' cards
 * marked "via sub-task".
 *
 * @package web2project\modules\cards
 */
/** @var w2p_Core_CAppUI $AppUI */
if (!defined('W2P_BASE_DIR')) {
    die('You should not access this file directly.');
}
require_once W2P_BASE_DIR . '/modules/cards/cards.class.php';

global $AppUI, $obj, $task_id;

$task_id = (int) $task_id;
$projectId = (int) $obj->task_project;
$access = new CCardAccess($AppUI);
if (!$access->canSeeProject($projectId) || !$access->canSeeTask($task_id, $projectId)) {
    echo '<p>' . CCardView::e($AppUI->_('You can\'t see the cards of this task.', UI_OUTPUT_RAW)) . '</p>';
    return;
}

if (isset($_GET['cards_subtasks'])) {
    $AppUI->setState('CardsTaskSubtasks', (int) w2PgetParam($_GET, 'cards_subtasks', 1));
}
$withSubtasks = (bool) $AppUI->getState('CardsTaskSubtasks', 1);
// Show all cards, the open ones, or the closed ones (Done and the like).
if (isset($_GET['cards_show'])) {
    $AppUI->setState('CardsTaskShow', w2PgetParam($_GET, 'cards_show', 'all'));
}
$show = $AppUI->getState('CardsTaskShow', 'all');
if (!in_array($show, array('all', 'open', 'closed'), true)) {
    $show = 'all';
}

$board = new CCardBoard($AppUI, $access);
$cards = $board->taskCards($task_id, $withSubtasks);
$statuses = array();
foreach (CCardStatuses::all() as $status) {
    $statuses[$status['id']] = $status;
}
$total = count($cards);
if ('all' !== $show) {
    $cards = array_values(array_filter($cards, function ($card) use ($statuses, $show) {
        $closed = isset($statuses[$card['status']]) && CCardStatuses::CLOSED == $statuses[$card['status']]['category'];
        return ('closed' === $show) ? $closed : !$closed;
    }));
}
$users = array();
foreach ($board->users() as $user) {
    $users[$user['id']] = $user['name'];
}
$cardUrl = '?m=cards&project_id=' . $projectId . '&card=';
$here = '?m=tasks&a=view&task_id=' . $task_id;
?>
<div class="w2p-cards-tasktab">
    <div class="w2p-cards-tasktab-bar">
        <label>
            <input type="checkbox" <?php echo $withSubtasks ? 'checked="checked"' : ''; ?>
                onchange="window.location = '<?php echo $here; ?>&cards_subtasks=' + (this.checked ? 1 : 0);" />
            <?php echo CCardView::e($AppUI->_('Include cards on sub-tasks', UI_OUTPUT_RAW)); ?>
        </label>
        <label>
            <?php echo CCardView::e($AppUI->_('Show', UI_OUTPUT_RAW)); ?>
            <select onchange="window.location = '<?php echo $here; ?>&cards_show=' + this.value;" aria-label="<?php echo CCardView::e($AppUI->_('Show', UI_OUTPUT_RAW)); ?>">
                <?php foreach (array('all' => 'All cards', 'open' => 'Open cards', 'closed' => 'Closed cards (like Done)') as $value => $label) { ?>
                    <option value="<?php echo $value; ?>"<?php echo $show === $value ? ' selected="selected"' : ''; ?>><?php echo CCardView::e($AppUI->_($label, UI_OUTPUT_RAW)); ?></option>
                <?php } ?>
            </select>
        </label>
        <a href="?m=cards&amp;project_id=<?php echo $projectId; ?>"><?php echo CCardView::e($AppUI->_('Open the project board', UI_OUTPUT_RAW)); ?></a>
        <?php if ($access->can('add')) { ?>
            <a class="button" href="?m=cards&amp;project_id=<?php echo $projectId; ?>&amp;new_card_task=<?php echo $task_id; ?>"><?php echo CCardView::e($AppUI->_('New card for this task', UI_OUTPUT_RAW)); ?></a>
        <?php } ?>
    </div>
    <?php if (!$cards) { ?>
        <p class="w2p-cards-none"><?php echo CCardView::e($AppUI->_($total ? ('open' === $show ? 'No open cards.' : 'No closed cards.') : 'No cards yet.', UI_OUTPUT_RAW)); ?></p>
    <?php } else { ?>
    <table class="tbl list w2p-cards-tasktable">
        <thead>
            <tr>
                <th><?php echo CCardView::e($AppUI->_('Key', UI_OUTPUT_RAW)); ?></th>
                <th><?php echo CCardView::e($AppUI->_('Card', UI_OUTPUT_RAW)); ?></th>
                <th><?php echo CCardView::e($AppUI->_('Status', UI_OUTPUT_RAW)); ?></th>
                <th><?php echo CCardView::e($AppUI->_('Owner', UI_OUTPUT_RAW)); ?></th>
                <th><?php echo CCardView::e($AppUI->_('Due', UI_OUTPUT_RAW)); ?></th>
                <th><?php echo CCardView::e($AppUI->_('Subcards', UI_OUTPUT_RAW)); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($cards as $card) {
            $status = isset($statuses[$card['status']]) ? $statuses[$card['status']] : null; ?>
            <tr>
                <td><a href="<?php echo $cardUrl . $card['id']; ?>"><?php echo CCardView::e($card['key']); ?></a></td>
                <td>
                    <?php echo CCardView::e($card['name']); ?>
                    <?php if ($card['task'] !== $task_id) { ?>
                        <span class="w2p-cards-via"><?php echo CCardView::e($AppUI->_('via sub-task', UI_OUTPUT_RAW) . ' #' . $card['task'] . ' ' . $card['task_name']); ?></span>
                    <?php } ?>
                </td>
                <td><?php echo $status ? CCardView::e($status['name']) : ''; ?></td>
                <td><?php echo isset($users[$card['owner']]) ? CCardView::e($users[$card['owner']]) : ''; ?></td>
                <td><?php echo CCardView::e($card['due']); ?></td>
                <td><?php echo $card['subcards'] ? CCardView::e($card['subcards_done'] . ' / ' . $card['subcards']) : ''; ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
    <?php } ?>
</div>
<?php
CCardView::assets();
