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

// The picker offers "All projects" and the active projects. Inactive
// projects the user may see are still reachable by a direct link (their
// project's Cards tab, a card link); they then show in the picker too.
$projects = array();
$visible = array();
foreach ($access->visibleProjects() as $project) {
    $visible[$project['id']] = $project;
    if ($project['active']) {
        $projects[$project['id']] = $project['name'];
    }
}

// The title block's project filter posts; links pass it in the URL.
$requested = isset($_REQUEST['project_id']);
if ($requested) {
    $AppUI->setState('CardsProject', (int) w2PgetParam($_REQUEST, 'project_id', CCardBoard::ALL));
}
$projectId = (int) $AppUI->getState('CardsProject', CCardBoard::ALL);
$openCard = (int) w2PgetParam($_GET, 'card', 0);
if ($openCard) {
    // A link to a card (Smart Search, the calendar, the Task tab): show its
    // project's board, unless the all-projects board shows it already.
    $card = CCardStore::load($openCard);
    $cardProject = $card ? (int) $card['card_project'] : 0;
    if ($cardProject && isset($visible[$cardProject])
        && (CCardBoard::ALL !== $projectId || !$visible[$cardProject]['active'])) {
        $projectId = $cardProject;
        $requested = true;
        $AppUI->setState('CardsProject', $projectId);
    }
}
if (!isset($projects[$projectId])) {
    if ($requested && isset($visible[$projectId])) {
        $projects[$projectId] = $visible[$projectId]['name'] . ' (' . $AppUI->_('inactive', UI_OUTPUT_RAW) . ')';
    } else {
        $projectId = CCardBoard::ALL;
    }
}

$titleBlock = new w2p_Theme_TitleBlock('Cards', 'icon.png', $m);
$titleBlock->addFilterCell('Project', 'project_id', array(CCardBoard::ALL => $AppUI->_('All projects', UI_OUTPUT_RAW)) + $projects, $projectId);
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
