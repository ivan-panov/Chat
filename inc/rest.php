<?php

// REST маршрут для создания диалога и первого сообщения
add_action('rest_api_init', function () {
    register_rest_route('cw/v1', '/dialog', [
        'methods'             => 'POST',
        'callback'            => 'cw_rest_create_dialog',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('cw/v1', '/dialog/(?P<id>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'cw_rest_get_dialog',
        'permission_callback' => '__return_true',
        'args'                => [
            'id'    => [ 'validate_callback' => 'is_numeric' ],
            'token' => [ 'required' => true ],
        ],
    ]);

    register_rest_route('cw/v1', '/dialog/(?P<id>\d+)/message', [
        'methods'             => 'POST',
        'callback'            => 'cw_rest_add_message',
        'permission_callback' => '__return_true',
        'args'                => [
            'id'    => [ 'validate_callback' => 'is_numeric' ],
            'token' => [ 'required' => true ],
        ],
    ]);
});

/**
 * Создание диалога из виджета
 *
 * POST /wp-json/cw/v1/dialog
 *  - name
 *  - phone
 *  - message
 */
function cw_rest_create_dialog( WP_REST_Request $request ) {
    global $wpdb;

    // Забираем параметры
    $name    = sanitize_text_field( $request->get_param('name') );
    $phone   = sanitize_text_field( $request->get_param('phone') );
    $message = sanitize_textarea_field( $request->get_param('message') );

    // Простая валидация
    if ( empty($name) || empty($phone) || empty($message) ) {
        return new WP_Error(
            'cw_bad_request',
            'Не заполнены все поля формы.',
            ['status' => 400]
        );
    }

    $dialogs_table  = $wpdb->prefix . 'cw_dialogs';
    $messages_table = $wpdb->prefix . 'cw_messages';

    // Создаём диалог
    $token = bin2hex(wp_generate_password(16, false));

    $insert_dialog = $wpdb->insert(
        $dialogs_table,
        [
            'user_name'  => $name,
            'phone'      => $phone,
            'user_token' => $token,
            'status'     => 'open',
            'created_at' => current_time('mysql'),
        ]
    );

    if ( $insert_dialog === false ) {
        return new WP_Error(
            'cw_db_error',
            'Ошибка сохранения диалога в базе данных.',
            ['status' => 500]
        );
    }

    $dialog_id = (int) $wpdb->insert_id;

    // Создаём первое сообщение пользователя
    $insert_message = $wpdb->insert(
        $messages_table,
        [
            'dialog_id'  => $dialog_id,
            'sender'     => 'user',
            'message'    => $message,
            'created_at' => current_time('mysql'), // время WP
        ]
    );

    if ( $insert_message === false ) {
        return new WP_Error(
            'cw_db_error',
            'Ошибка сохранения сообщения в базе данных.',
            ['status' => 500]
        );
    }

    // Отправляем уведомление в Telegram (если настроено)
    if ( function_exists('cw_send_to_telegram') ) {
        $text = "💬 Новое сообщение с сайта\n"
              . "<b>Имя:</b> {$name}\n"
              . "<b>Телефон:</b> {$phone}\n"
              . "<b>Диалог #{$dialog_id}</b>\n"
              . "\n"
              . esc_html($message);

        cw_send_to_telegram( $text );
    }

    // Успешный ответ
    return new WP_REST_Response(
        [
            'status'    => 'ok',
            'dialog_id' => $dialog_id,
            'token'     => $token,
        ],
        200
    );
}

/**
 * Получение диалога и сообщений
 */
function cw_rest_get_dialog( WP_REST_Request $request ) {
    global $wpdb;

    $dialog_id = intval($request->get_param('id'));
    $token     = sanitize_text_field($request->get_param('token'));

    $dialog = cw_rest_validate_dialog($dialog_id, $token);
    if (is_wp_error($dialog)) {
        return $dialog;
    }

    $messages = $wpdb->get_results($wpdb->prepare(
        "SELECT sender, message, created_at FROM {$wpdb->prefix}cw_messages WHERE dialog_id = %d ORDER BY id ASC",
        $dialog_id
    ));

    return new WP_REST_Response([
        'dialog'   => $dialog,
        'messages' => $messages,
    ], 200);
}

/**
 * Добавление сообщения в существующий диалог
 */
function cw_rest_add_message( WP_REST_Request $request ) {
    global $wpdb;

    $dialog_id = intval($request->get_param('id'));
    $token     = sanitize_text_field($request->get_param('token'));
    $message   = sanitize_textarea_field($request->get_param('message'));

    if (empty($message)) {
        return new WP_Error('cw_bad_request', 'Сообщение не может быть пустым.', ['status' => 400]);
    }

    $dialog = cw_rest_validate_dialog($dialog_id, $token);
    if (is_wp_error($dialog)) {
        return $dialog;
    }

    $insert_message = $wpdb->insert(
        $wpdb->prefix . 'cw_messages',
        [
            'dialog_id'  => $dialog_id,
            'sender'     => 'user',
            'message'    => $message,
            'created_at' => current_time('mysql'),
        ]
    );

    if ($insert_message === false) {
        return new WP_Error('cw_db_error', 'Не удалось сохранить сообщение.', ['status' => 500]);
    }

    // Уведомляем Telegram
    if (function_exists('cw_send_to_telegram')) {
        $text = "💬 Новое сообщение\n<b>Диалог #{$dialog_id}</b>\n\n" . esc_html($message);
        cw_send_to_telegram($text);
    }

    return new WP_REST_Response(['status' => 'ok'], 200);
}

/**
 * Проверка прав пользователя на диалог
 */
function cw_rest_validate_dialog(int $dialog_id, string $token) {
    global $wpdb;

    $dialog = $wpdb->get_row($wpdb->prepare(
        "SELECT id, user_name, phone, status, created_at FROM {$wpdb->prefix}cw_dialogs WHERE id = %d AND user_token = %s",
        $dialog_id,
        $token
    ));

    if (!$dialog) {
        return new WP_Error('cw_forbidden', 'Диалог не найден или ключ недействителен.', ['status' => 403]);
    }

    return $dialog;
}
