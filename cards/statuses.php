<?php
/**
 * Edit the global card status list (administrators).
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

$titleBlock = new w2p_Theme_TitleBlock('Card statuses', 'icon.png', $m);
$titleBlock->addCrumb('?m=cards', 'cards');
$titleBlock->show();

$categories = array(
    CCardStatuses::NOT_STARTED => $AppUI->_('Not started'),
    CCardStatuses::STARTED => $AppUI->_('Started'),
    CCardStatuses::CLOSED => $AppUI->_('Closed'),
);
$row = function ($status) use ($categories) {
    $html = '<tr class="w2p-cards-status-row">';
    $html .= '<td class="w2p-cards-handle" aria-hidden="true">&#8942;&#8942;</td>';
    $html .= '<td><input type="hidden" name="status_id[]" value="' . (int) $status['id'] . '" />';
    $html .= '<input type="text" class="text" name="status_name[]" maxlength="64" value="' . CCardView::e($status['name']) . '" aria-label="Status name" /></td>';
    $html .= '<td><select name="status_category[]" class="text" aria-label="Counts as">';
    foreach ($categories as $value => $label) {
        $html .= '<option value="' . $value . '"' . ($value == $status['category'] ? ' selected="selected"' : '') . '>' . CCardView::e($label) . '</option>';
    }
    $html .= '</select></td>';
    $html .= '<td><button type="button" class="button" data-move="-1" aria-label="Move up">&#8593;</button> '
        . '<button type="button" class="button" data-move="1" aria-label="Move down">&#8595;</button> '
        . '<button type="button" class="button" data-remove="1">Remove</button></td>';
    return $html . '</tr>';
};
?>
<form name="statusFrm" action="?m=cards" method="post" accept-charset="utf-8" class="w2p-cards-statuses">
    <input type="hidden" name="dosql" value="do_statuses_aed" />
    <p><?php echo CCardView::e($AppUI->_('These statuses are the columns of every project\'s board. "Counts as" tells boards and counts which statuses mean not started, started and closed. A status that still has cards can\'t be removed.')); ?></p>
    <table class="tbl list">
        <thead>
            <tr><th></th><th><?php echo CCardView::e($AppUI->_('Status')); ?></th><th><?php echo CCardView::e($AppUI->_('Counts as')); ?></th><th></th></tr>
        </thead>
        <tbody id="w2p-cards-status-rows">
            <?php foreach (CCardStatuses::all() as $status) {
                echo $row($status);
            } ?>
        </tbody>
    </table>
    <template id="w2p-cards-status-template"><?php echo $row(array('id' => 0, 'name' => '', 'category' => 0)); ?></template>
    <p>
        <button type="button" class="button" id="w2p-cards-status-add"><?php echo CCardView::e($AppUI->_('Add a status')); ?></button>
        <input type="submit" class="button" value="<?php echo CCardView::e($AppUI->_('Save')); ?>" />
    </p>
</form>
<script type="text/javascript">
(function () {
    var rows = document.getElementById('w2p-cards-status-rows');
    rows.addEventListener('click', function (e) {
        var button = e.target.closest('button');
        if (!button) { return; }
        var tr = button.closest('tr');
        if (button.hasAttribute('data-remove')) {
            tr.parentNode.removeChild(tr);
        } else if (button.getAttribute('data-move') === '-1' && tr.previousElementSibling) {
            tr.parentNode.insertBefore(tr, tr.previousElementSibling);
        } else if (button.getAttribute('data-move') === '1' && tr.nextElementSibling) {
            tr.parentNode.insertBefore(tr.nextElementSibling, tr);
        }
    });
    document.getElementById('w2p-cards-status-add').addEventListener('click', function () {
        var copy = document.getElementById('w2p-cards-status-template').content.cloneNode(true);
        rows.appendChild(copy);
        rows.lastElementChild.querySelector('input[type=text]').focus();
    });
})();
</script>
<?php
CCardView::assets();
