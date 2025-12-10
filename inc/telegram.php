<?php
if (!defined('ABSPATH')) exit;

/* ============================================================
   Получение токена и ID администратора
============================================================ */
function cw_tg_get_token() {
    return get_option('cw_tg_token');
}

function cw_tg_get_admin_chat() {
    return get_option('cw_tg_admin_chat');
}

/* ============================================================
   Отправка сообщения в Telegram
============================================================ */
function cw_tg_send($chatId, $text, $keyboard = null) {

    $token = cw_tg_get_token();
    if (!$token) return;

    $body = [
        'chat_id' => $chatId,
        'text'    => $text,
        'parse_mode' => 'HTML'
    ];

    if ($keyboard) {
        $body['reply_markup'] = json_encode([
            'inline_keyboard' => $keyboard
        ]);
    }

    wp_remote_post("https://api.telegram.org/bot{$token}/sendMessage", [
        'timeout' => 10,
        'body'    => $body
    ]);
}

/* ============================================================
   Роут Webhook Telegram
============================================================ */
add_action('rest_api_init', function () {
    register_rest_route('cw/v1', '/tg-webhook', [
        'methods'  => 'POST',
        'callback' => 'cw_tg_webhook_handler',
        'permission_callback' => '__return_true'
    ]);
});

/* ============================================================
   Обработчик Telegram Webhook
============================================================ */
function cw_tg_webhook_handler(WP_REST_Request $r) {
    global $wpdb;

    $token = cw_tg_get_token();
    $admin = cw_tg_get_admin_chat();

    if (!$token || !$admin)
        return ['error' => 'telegram_not_configured'];

    $update = $r->get_json_params();
    if (!$update)
        return ['error' => 'empty_update'];

    $tableD = $wpdb->prefix . 'cw_dialogs';
    $tableM = $wpdb->prefix . 'cw_messages';

    /* ============================================================
       CALLBACK-КНОПКИ
    ============================================================= */
    if (!empty($update['callback_query'])) {

        $cb    = $update['callback_query'];
        $data  = $cb['data'];
        $chat  = $cb['message']['chat']['id'];

        if (!preg_match('/^cw_(\w+)_(\d+)$/', $data, $m))
            return ['error' => 'bad_callback'];

        $action = $m[1];
        $dialog = intval($m[2]);

        /* ---------- КНОПКА: СБП QR ---------- */
        if ($action === 'sbp') {

            $sbp_url = "https://qr.nspk.ru/AS1A005O0HP24U9I8P0AN5VA03QFOL1F?type=01&bank=100000000111&crc=30da";

            // HTML-кликабельная ссылка
            $html_link = '<a href="' . esc_url($sbp_url) . '" target="_blank">СБП QR</a>';

            // Вставляем ссылку в чат клиента
            $wpdb->insert($tableM, [
                'dialog_id'   => $dialog,
                'message'     => $html_link,
                'is_operator' => 1,
                'unread'      => 1,
                'created_at'  => current_time('mysql')
            ]);

            cw_tg_send($chat, "📲 СБП QR отправлен в диалог #{$dialog}");

            return ['status' => 'sbp_sent'];
        }

        /* ---------- Закрыть ---------- */
        if ($action === 'close') {

            $wpdb->update($tableD, ['status' => 'closed'], ['id' => $dialog]);

            $wpdb->insert($tableM, [
                'dialog_id'   => $dialog,
                'message'     => '[system]Диалог закрыт оператором.',
                'is_operator' => 1,
                'unread'      => 1,
                'created_at'  => current_time('mysql')
            ]);

            cw_tg_send($chat, "🔒 Диалог #{$dialog} закрыт.");
            return ['status' => 'closed'];
        }

        /* ---------- Удалить ---------- */
        if ($action === 'delete') {

            $wpdb->delete($tableM, ['dialog_id' => $dialog]);
            $wpdb->delete($tableD, ['id' => $dialog]);

            cw_tg_send($chat, "❌ Диалог #{$dialog} удалён.");
            return ['status' => 'deleted'];
        }

        /* ---------- Ответить ---------- */
        if ($action === 'reply') {

            cw_tg_send($chat, "✍ Введите ответ для диалога #{$dialog}");

            update_option('cw_tg_reply_to_dialog', $dialog);

            return ['status' => 'reply_wait'];
        }

        return ['status' => 'callback_ok'];
    }

    /* ============================================================
       ОТВЕТ АДМИНА (без /d<ID>)
    ============================================================= */
    if (!empty($update['message']['text'])) {

        $msg  = $update['message'];
        $text = trim($msg['text']);
        $from = intval($msg['from']['id']);
        $cid  = $msg['chat']['id'];

        if ($from !== intval($admin))
            return ['status' => 'ignored_not_admin'];

        // Если ждём ответ после кнопки "Ответить"
        $replyDialog = get_option('cw_tg_reply_to_dialog');

        if ($replyDialog) {

            delete_option('cw_tg_reply_to_dialog');

            $clean = sanitize_text_field($text);

            $wpdb->insert($tableM, [
                'dialog_id'   => $replyDialog,
                'message'     => $clean,
                'is_operator' => 1,
                'unread'      => 1,
                'created_at'  => current_time('mysql')
            ]);

            cw_tg_send($cid, "✔ Ответ отправлен в диалог #{$replyDialog}");

            return ['status' => 'reply_sent'];
        }

        /* --- Fallback команда /d<ID> text --- */
        if (preg_match('/^\/d(\d+)\s+(.+)$/ui', $text, $m)) {

            $dialogId = intval($m[1]);
            $answer   = sanitize_text_field($m[2]);

            $exists = $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$tableD} WHERE id=%d", $dialogId)
            );

            if (!$exists) {
                cw_tg_send($cid, "❗ Диалог #{$dialogId} не найден.");
                return ['error' => 'not_found'];
            }

            $wpdb->insert($tableM, [
                'dialog_id'   => $dialogId,
                'message'     => $answer,
                'is_operator' => 1,
                'unread'      => 1,
                'created_at'  => current_time('mysql')
            ]);

            cw_tg_send($cid, "✔ Отправлено в диалог #{$dialogId}");

            return ['status' => 'fallback_reply'];
        }

        return ['status' => 'ignored_text'];
    }

    return ['status' => 'ignored_update'];
}

/* ============================================================
   Уведомление оператора о новом сообщении клиента
============================================================ */
function cw_tg_notify_operator($dialogId, $messageText) {

    $admin = cw_tg_get_admin_chat();
    if (!$admin) return;

    $keyboard = [
        [
            ["text" => "💬 Ответить", "callback_data" => "cw_reply_{$dialogId}"],
            ["text" => "💳 СБП QR",   "callback_data" => "cw_sbp_{$dialogId}"]
        ],
        [
            ["text" => "🔒 Закрыть", "callback_data" => "cw_close_{$dialogId}"],
            ["text" => "❌ Удалить", "callback_data" => "cw_delete_{$dialogId}"]
        ]
    ];

    $msg =
        "📩 <b>Новое сообщение</b>\n".
        "Диалог: <b>#{$dialogId}</b>\n".
        "Сообщение: <i>{$messageText}</i>";

    cw_tg_send($admin, $msg, $keyboard);
}
