<?php
/**
 * Saves the global card status list from statuses.php.
 *
 * @package web2project\modules\cards
 */
/** @var w2p_Core_CAppUI $AppUI */
if (!defined('W2P_BASE_DIR')) {
    die('You should not access this file directly.');
}
require_once W2P_BASE_DIR . '/modules/cards/cards.class.php';

$access = new CCardAccess($AppUI);
if (!$access->canManageStatuses()) {
    $AppUI->redirect(ACCESS_DENIED);
}

$ids = (array) w2PgetParam($_POST, 'status_id', array());
$names = (array) w2PgetParam($_POST, 'status_name', array());
$categories = (array) w2PgetParam($_POST, 'status_category', array());

$list = array();
foreach ($names as $i => $name) {
    $list[] = array(
        'id' => isset($ids[$i]) ? (int) $ids[$i] : 0,
        'name' => (string) $name,
        'category' => isset($categories[$i]) ? (int) $categories[$i] : 0,
    );
}

$errors = CCardStatuses::save($list);
if ($errors) {
    $AppUI->setMsg(implode(' ', $errors), UI_MSG_ERROR);
} else {
    $AppUI->setMsg('Card statuses saved', UI_MSG_OK);
}
$AppUI->redirect('m=cards&a=statuses');
