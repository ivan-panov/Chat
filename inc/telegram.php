<?php

/**
 * Отправка сообщения в Telegram
 */
function cw_send_to_telegram($text) {

    // Загружаем настройки
    $token = get_option('cw_tg_bot_token');
    $chat_id = get_option('cw_tg_chat_id');

    // Если нет настроек — ничего не делаем
    if (empty($token) || empty($chat_id)) {
        return;
    }

    $url = "https://api.telegram.org/bot{$token}/sendMessage";

    $args = [
        'body' => [
            'chat_id'    => $chat_id,
            'text'       => $text,
            'parse_mode' => 'HTML'
        ],
        'timeout' => 10
    ];

    wp_remote_post($url, $args);
}


/**
 * Webhook endpoint для приема ответов из Telegram
 * (пока выключен, но легко активируется)
 */
add_action('rest_api_init', function () {

    register_rest_route('cw/v1', '/telegram', [
        'methods'  => 'POST',
        'callback' => 'cw_telegram_webhook_handler',
        'permission_callback' => '__return_true'
    ]);

});


/**
 * Обработка входящих сообщений из Telegram
 */
function cw_telegram_webhook_handler($request) {
    global $wpdb;

    $data = $request->get_json_params();

    if (!isset($data['message']['text'])) {
        return ['status' => 'no_text'];
    }

    $text = trim($data['message']['text']);

    /**
     * Формат ответа из Telegram:
     *   #ID Сообщение
     *
     * Например:
     *   #15 Здравствуйте! Мы свяжемся с вами.
     */

    if (!preg_match('/^#(\d+)\s+(.+)/u', $text, $m)) {
        return ['status' => 'ignored'];
    }

    $dialog_id = intval($m[1]);
    $reply = sanitize_textarea_field($m[2]);

    // Сохраняем ответ в базе
    $wpdb->insert($wpdb->prefix . 'cw_messages', [
        'dialog_id' => $dialog_id,
        'sender'    => 'telegram',
        'message'   => $reply
    ]);

    return ['status' => 'ok'];
}
