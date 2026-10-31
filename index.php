<?php
/**
 * The Cards board: all projects (the default), or one project.
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
$projects = array();
foreach ($access->visibleProjects() as $project) {
    $projects[$project['id']] = $project['name'];
}

// The title block's project filter posts; links pass it in the URL.
if (isset($_REQUEST['project_id'])) {
    $AppUI->setState('CardsProject', (int) w2PgetParam($_REQUEST, 'project_id', CCardBoard::ALL));
}
$projectId = (int) $AppUI->getState('CardsProject', CCardBoard::ALL);
if (!isset($projects[$projectId])) {
    $projectId = CCardBoard::ALL;
}
$openCard = (int) w2PgetParam($_GET, 'card', 0);
if ($openCard && CCardBoard::ALL !== $projectId) {
    // A link to a card (Smart Search, the calendar, the Task tab): show its
    // project's board, unless the all-projects board is showing anyway.
    $card = CCardStore::load($openCard);
    if ($card && isset($projects[(int) $card['card_project']])) {
        $projectId = (int) $card['card_project'];
        $AppUI->setState('CardsProject', $projectId);
    }
}

$titleBlock = new w2p_Theme_TitleBlock('Cards', 'icon.png', $m);
$titleBlock->addFilterCell('Project', 'project_id', array(CCardBoard::ALL => $AppUI->_('All projects')) + $projects, $projectId);
if (CCardBoard::ALL !== $projectId) {
    $titleBlock->addCrumb('?m=projects&a=view&project_id=' . $projectId, 'view this project');
}
if ($access->canManageStatuses()) {
    $titleBlock->addCrumb('?m=cards&a=statuses', 'edit card statuses');
}
$titleBlock->show();

CCardView::mount($AppUI, array(
    'projectId' => $projectId,
    'mode' => 'full',
    'openCard' => $openCard,
    'newCardTask' => (int) w2PgetParam($_GET, 'new_card_task', 0),
));
