<?php
/**
 * Puts the board on a page: its stylesheet and scripts, and the element the
 * script turns into a board.
 *
 * @package web2project\modules\cards
 */
class CCardView
{
    const VERSION = '0.2.0';

    /** @var bool */
    protected static $assetsDone = false;

    /**
     * The module's stylesheet and scripts, once per page.
     */
    public static function assets()
    {
        if (self::$assetsDone) {
            return;
        }
        self::$assetsDone = true;
        $base = rtrim(W2P_BASE_URL, '/') . '/modules/cards/assets/';
        $v = '?v=' . self::VERSION;
        echo '<link rel="stylesheet" type="text/css" href="' . $base . 'board.css' . $v . '" />';
        echo '<script type="text/javascript" src="' . $base . 'vendor/Sortable.min.js' . $v . '"></script>';
        echo '<script type="text/javascript" src="' . $base . 'board.js' . $v . '"></script>';
    }

    /**
     * An element the board script mounts on.
     *
     * @param w2p_Core_CAppUI      $AppUI
     * @param array<string, mixed> $options projectId, mode (full|compact), openCard, newCardTask
     */
    public static function mount(w2p_Core_CAppUI $AppUI, array $options)
    {
        self::assets();
        $base = rtrim(W2P_BASE_URL, '/') . '/index.php';
        $options = array_merge(array(
            'endpoint' => $base . '?m=cards&a=do_card_json&suppressHeaders=1',
            'boardUrl' => $base . '?m=cards',
            'statusesUrl' => $base . '?m=cards&a=statuses',
            'taskUrl' => $base . '?m=tasks&a=view&task_id=',
            'nonce' => $AppUI->getFormToken(),
            'mode' => 'full',
            'openCard' => 0,
            'newCardTask' => 0,
        ), $options);

        echo '<div class="w2p-cards" data-options="'
            . htmlspecialchars(json_encode($options), ENT_QUOTES, 'UTF-8') . '">'
            . '<p class="w2p-cards-loading">' . htmlspecialchars($AppUI->_('Loading cards...'), ENT_QUOTES, 'UTF-8') . '</p>'
            . '<noscript>' . htmlspecialchars($AppUI->_('The board needs JavaScript.'), ENT_QUOTES, 'UTF-8') . '</noscript>'
            . '</div>';
    }

    /**
     * @param string $text
     *
     * @return string
     */
    public static function e($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
