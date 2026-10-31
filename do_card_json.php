<?php
/**
 * The board's JSON endpoint: index.php?m=cards&a=do_card_json&suppressHeaders=1
 *
 * POST only. Fields: __nonce (the session form token; index.php checks it
 * for every a=do_* request), op, and payload (a JSON object).
 *
 * Every change answers with the refreshed board (and the open card, when the
 * request names one), so the page never drifts from the server.
 *
 * @package web2project\modules\cards
 */
/** @var w2p_Core_CAppUI $AppUI */
if (!defined('W2P_BASE_DIR')) {
    die('You should not access this file directly.');
}
require_once W2P_BASE_DIR . '/modules/cards/cards.class.php';

if ('POST' !== $_SERVER['REQUEST_METHOD']) {
    CCardJson::error(405, 'method', 'Use POST.');
}

$access = new CCardAccess($AppUI);
if (!$access->can('access')) {
    CCardJson::error(403, 'forbidden', 'You don\'t have access to Cards.');
}

$op = (string) w2PgetParam($_POST, 'op', '');
$in = CCardJson::input();
$board = new CCardBoard($AppUI, $access);
$store = new CCardStore($AppUI->user_id);

$int = function ($key) use ($in) {
    return isset($in[$key]) ? (int) $in[$key] : 0;
};

/**
 * Sends the board for $projectId, and the drawer's card if one is open.
 */
$respond = function ($projectId, $extra = array()) use ($board, $access, $in) {
    $data = array('board' => $board->board($projectId));
    $open = isset($in['open_card']) ? (int) $in['open_card'] : 0;
    if ($open && $access->canSeeCard($open)) {
        $data['card'] = $board->card($open);
    }
    CCardJson::send(array_merge($data, $extra));
};

/**
 * Loads a card the user may see, or stops with 404.
 */
$visibleCard = function ($cardId) use ($access) {
    $card = $cardId ? CCardStore::load($cardId) : null;
    if (!$card || !$access->canSeeCard($cardId)) {
        CCardJson::error(404, 'not_found', 'That card doesn\'t exist or you can\'t see it.');
    }
    return $card;
};

$requireRight = function ($right) use ($access) {
    if (!$access->can($right)) {
        CCardJson::error(403, 'forbidden', 'You don\'t have permission to ' . $right . ' cards.');
    }
};

$failed = function (CCardStore $store, $projectId) use ($board) {
    $errors = $store->getErrors();
    if (isset($errors['conflict'])) {
        CCardJson::error(409, 'conflict', $errors['conflict'], array(), array('board' => $board->board($projectId)));
    }
    CCardJson::error(400, 'invalid', implode(' ', $errors), $errors);
};

/**
 * A task link must be a task the user can see, in the card's project.
 */
$checkTask = function ($taskId, $projectId) use ($access) {
    if ($taskId && !$access->canSeeTask($taskId, $projectId)) {
        CCardJson::error(400, 'invalid', 'Pick a task from this project.', array('task' => 'Pick a task from this project.'));
    }
};

switch ($op) {
    case 'board':
        $projectId = $int('project_id');
        if (!$access->canSeeProject($projectId)) {
            CCardJson::error(404, 'not_found', 'That project doesn\'t exist or you can\'t see it.');
        }
        $respond($projectId);
        break;

    case 'card':
        $card = $visibleCard($int('card_id'));
        CCardJson::send(array('card' => $board->card($card['card_id'])));
        break;

    case 'create':
        $requireRight('add');
        $projectId = $int('project_id');
        $parentId = $int('parent');
        if (!$access->canSeeProject($projectId)) {
            CCardJson::error(404, 'not_found', 'That project doesn\'t exist or you can\'t see it.');
        }
        if ($parentId) {
            $parent = $visibleCard($parentId);
            if ((int) $parent['card_project'] !== $projectId) {
                CCardJson::error(400, 'invalid', 'A subcard must be in the same project as its parent.');
            }
        }
        $fields = isset($in['fields']) && is_array($in['fields']) ? $in['fields'] : array();
        $checkTask(isset($fields['task']) ? (int) $fields['task'] : 0, $projectId);
        $cardId = $store->create($projectId, $fields, $parentId);
        if (!$cardId) {
            $failed($store, $projectId);
        }
        $respond($projectId, array('created' => $cardId));
        break;

    case 'update':
        $requireRight('edit');
        $card = $visibleCard($int('card_id'));
        $projectId = (int) $card['card_project'];
        $fields = isset($in['fields']) && is_array($in['fields']) ? $in['fields'] : array();
        if (array_key_exists('task', $fields)) {
            $checkTask((int) $fields['task'], $projectId);
        }
        if (!$store->update($card['card_id'], $int('version'), $fields)) {
            $failed($store, $projectId);
        }
        $respond($projectId);
        break;

    case 'move':
        $requireRight('edit');
        $card = $visibleCard($int('card_id'));
        $projectId = (int) $card['card_project'];
        $fields = array();
        $group = isset($in['group']) ? (string) $in['group'] : 'status';
        $value = isset($in['value']) ? $in['value'] : null;
        switch ($group) {
            case 'status':
            case 'priority':
            case 'owner':
                if (null !== $value) {
                    $fields[$group] = (int) $value;
                }
                break;
            case 'task':
                if (null !== $value) {
                    $checkTask((int) $value, $projectId);
                    $fields['task'] = (int) $value;
                }
                break;
            case 'tag':
                // Handled after the move: drop the old column's tag, add the new one's.
                break;
            default:
                CCardJson::error(400, 'invalid', 'Unknown grouping.');
        }
        if (!$store->move($card['card_id'], $int('version'), $fields, $int('before'), $int('after'))) {
            $failed($store, $projectId);
        }
        if ('tag' == $group) {
            $from = $int('from_tag');
            $to = (int) $value;
            if ($from && $from !== $to) {
                $store->removeTag($card['card_id'], $from);
            }
            if ($to) {
                $names = CCardTags::names(array($to));
                if ($names) {
                    $store->addTag($card['card_id'], $names[$to]);
                }
            }
        }
        // Closing a parent may close its open subcards too, when asked.
        if ('status' == $group && !empty($in['close_subcards'])) {
            $status = CCardStatuses::get((int) $value);
            if ($status && CCardStatuses::CLOSED == $status['category']) {
                foreach (array_slice(CCardStore::withDescendants($card['card_id']), 1) as $subId) {
                    $sub = CCardStore::load($subId);
                    if ($sub && $access->canSeeCard($subId)
                        && !in_array((int) $sub['card_status'], CCardStatuses::closedIds(), true)) {
                        $store->update($subId, null, array('status' => (int) $value));
                    }
                }
            }
        }
        $respond($projectId);
        break;

    case 'delete':
        $requireRight('delete');
        $card = $visibleCard($int('card_id'));
        $deleted = $store->delete($card['card_id']);
        $respond((int) $card['card_project'], array('deleted' => $deleted));
        break;

    case 'comment':
        $requireRight('edit');
        $card = $visibleCard($int('card_id'));
        if (!$store->comment($card['card_id'], isset($in['text']) ? $in['text'] : '')) {
            $failed($store, (int) $card['card_project']);
        }
        $respond((int) $card['card_project']);
        break;

    case 'dependency_add':
    case 'dependency_remove':
        $requireRight('edit');
        $card = $visibleCard($int('card_id'));
        $other = $visibleCard($int('requires'));
        $ok = ('dependency_add' == $op)
            ? $store->addDependency($card['card_id'], $other['card_id'])
            : $store->removeDependency($card['card_id'], $other['card_id']);
        if (!$ok) {
            $failed($store, (int) $card['card_project']);
        }
        $respond((int) $card['card_project']);
        break;

    case 'parent':
        $requireRight('edit');
        $card = $visibleCard($int('card_id'));
        $parentId = $int('parent');
        if ($parentId) {
            $visibleCard($parentId);
        }
        if (!$store->setParent($card['card_id'], $parentId)) {
            $failed($store, (int) $card['card_project']);
        }
        $respond((int) $card['card_project']);
        break;

    case 'tag_add':
        $requireRight('edit');
        $card = $visibleCard($int('card_id'));
        if (!$store->addTag($card['card_id'], isset($in['name']) ? $in['name'] : '')) {
            $failed($store, (int) $card['card_project']);
        }
        $respond((int) $card['card_project']);
        break;

    case 'tag_remove':
        $requireRight('edit');
        $card = $visibleCard($int('card_id'));
        $store->removeTag($card['card_id'], $int('tag'));
        $respond((int) $card['card_project']);
        break;

    case 'settings':
        $projectId = $int('project_id');
        if (!$access->canSeeProject($projectId) || !$access->canConfigure($projectId)) {
            CCardJson::error(403, 'forbidden', 'You need edit rights on this project to change its board.');
        }
        CCardSettings::save($projectId, isset($in['settings']) && is_array($in['settings']) ? $in['settings'] : array());
        $respond($projectId);
        break;

    case 'statuses':
        if (!$access->canManageStatuses()) {
            CCardJson::error(403, 'forbidden', 'Only administrators can change the status list.');
        }
        $errors = CCardStatuses::save(isset($in['statuses']) && is_array($in['statuses']) ? $in['statuses'] : array());
        if ($errors) {
            CCardJson::error(400, 'invalid', implode(' ', $errors));
        }
        CCardJson::send(array('statuses' => CCardStatuses::all()));
        break;

    default:
        CCardJson::error(400, 'unknown_op', 'Unknown request.');
}
