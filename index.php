<?php
/**
 * The Cards board for one project, with a project picker.
 *
 * @package web2project\modules\cards
 */
/** @var w2p_Core_CAppUI $AppUI */
if (!defined('W2P_BASE_DIR')) {
    die('You should not access this file directly.');
}
require_once W2P_BASE_DIR . '/modules/cards/cards.class.php';

$access = new CCardAccess($AppUI);
if (!$access->can('view')) {
    $AppUI->redirect(ACCESS_DENIED);
}

// Projects this user may see, for the picker.
$project = new CProject();
$projects = array();
foreach ($project->getAllowedProjects($AppUI->user_id, false) as $id => $info) {
    $projects[(int) $id] = html_entity_decode((string) $info['project_name'], ENT_QUOTES, 'UTF-8');
}
asort($projects, SORT_NATURAL | SORT_FLAG_CASE);

// The title block's project filter posts; links pass it in the URL.
if (isset($_REQUEST['project_id'])) {
    $AppUI->setState('CardsProject', (int) w2PgetParam($_REQUEST, 'project_id', 0));
}
$projectId = (int) $AppUI->getState('CardsProject', 0);
if (!isset($projects[$projectId])) {
    $projectId = 0;
}
$openCard = (int) w2PgetParam($_GET, 'card', 0);
if ($openCard) {
    // A link to a card: show its project's board, whatever was picked before.
    $card = CCardStore::load($openCard);
    if ($card && isset($projects[(int) $card['card_project']])) {
        $projectId = (int) $card['card_project'];
        $AppUI->setState('CardsProject', $projectId);
    }
}

$titleBlock = new w2p_Theme_TitleBlock('Cards', 'icon.png', $m);
$titleBlock->addFilterCell('Project', 'project_id', array(0 => '(' . $AppUI->_('pick a project') . ')') + $projects, $projectId);
if ($projectId) {
    $titleBlock->addCrumb('?m=projects&a=view&project_id=' . $projectId, 'view this project');
}
if ($access->canManageStatuses()) {
    $titleBlock->addCrumb('?m=cards&a=statuses', 'edit card statuses');
}
$titleBlock->show();

if (!$projectId) {
    echo '<div class="w2p-cards-empty"><p>' . CCardView::e($AppUI->_('Pick a project to see its board.')) . '</p></div>';
    return;
}

CCardView::mount($AppUI, array(
    'projectId' => $projectId,
    'mode' => 'full',
    'openCard' => $openCard,
    'newCardTask' => (int) w2PgetParam($_GET, 'new_card_task', 0),
));
