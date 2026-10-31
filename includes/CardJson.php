<?php
/**
 * JSON responses for the board's requests.
 *
 * Module-local stand-in for the core helper proposed in
 * web2project/web2project#528, with the same envelope:
 *   {"ok": true, "data": ...}
 *   {"ok": false, "error": {"code": "...", "message": "...", "fields": {...}}}
 *
 * The board sends the session's form token as a normal __nonce POST field,
 * which index.php's CSRF gate already accepts for a=do_* actions.
 *
 * @package web2project\modules\cards
 */
class CCardJson
{
    /**
     * @param mixed $data
     * @param int   $status
     */
    public static function send($data, $status = 200)
    {
        self::output(array('ok' => true, 'data' => $data), $status);
    }

    /**
     * @param int                  $status
     * @param string               $code
     * @param string               $message
     * @param array<string, mixed> $fields
     * @param mixed                $data
     */
    public static function error($status, $code, $message, array $fields = array(), $data = null)
    {
        $error = array('code' => $code, 'message' => $message);
        if ($fields) {
            $error['fields'] = $fields;
        }
        $body = array('ok' => false, 'error' => $error);
        if (null !== $data) {
            $body['data'] = $data;
        }
        self::output($body, $status);
    }

    /**
     * @param array<string, mixed> $body
     * @param int                  $status
     */
    protected static function output(array $body, $status)
    {
        // Drop anything already buffered (the theme is suppressed, but be safe).
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code((int) $status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($body, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * The request's JSON payload, posted as the "payload" form field.
     *
     * @return array<string, mixed>
     */
    public static function input()
    {
        $raw = isset($_POST['payload']) ? (string) $_POST['payload'] : '';
        $data = json_decode($raw, true);

        return is_array($data) ? $data : array();
    }
}
