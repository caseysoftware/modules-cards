<?php
/**
 * "Cards" tab on the project view: the project's board, compact.
 *
 * @package web2project\modules\cards
 */
/** @var w2p_Core_CAppUI $AppUI */
if (!defined('W2P_BASE_DIR')) {
    die('You should not access this file directly.');
}
require_once W2P_BASE_DIR . '/modules/cards/cards.class.php';

global $AppUI, $project_id;

$access = new CCardAccess($AppUI);
if (!$access->canSeeProject((int) $project_id)) {
    echo '<p>' . CCardView::e($AppUI->_('You can\'t see the cards of this project.')) . '</p>';
    return;
}

// The board loads its cards itself when the tab is shown, so a project
// page with tabs rendered up front doesn't pay for it.
CCardView::mount($AppUI, array(
    'projectId' => (int) $project_id,
    'mode' => 'compact',
));
