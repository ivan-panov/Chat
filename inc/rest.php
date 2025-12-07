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

    // Админские маршруты для работы с диалогами в реальном времени
    register_rest_route('cw/v1', '/admin/dialog/(?P<id>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'cw_rest_admin_get_dialog',
        'permission_callback' => function () { return current_user_can('manage_options'); },
        'args'                => [
            'id' => [ 'validate_callback' => 'is_numeric' ],
        ],
    ]);

    register_rest_route('cw/v1', '/admin/dialog/(?P<id>\d+)/message', [
        'methods'             => 'POST',
        'callback'            => 'cw_rest_admin_add_message',
        'permission_callback' => function () { return current_user_can('manage_options'); },
        'args'                => [
            'id' => [ 'validate_callback' => 'is_numeric' ],
        ],
    ]);

    register_rest_route('cw/v1', '/admin/dialog/(?P<id>\d+)/close', [
        'methods'             => 'POST',
        'callback'            => 'cw_rest_admin_close_dialog',
        'permission_callback' => function () { return current_user_can('manage_options'); },
        'args'                => [
            'id' => [ 'validate_callback' => 'is_numeric' ],
        ],
    ]);

    register_rest_route('cw/v1', '/admin/dialog/(?P<id>\d+)', [
        'methods'             => 'DELETE',
        'callback'            => 'cw_rest_admin_delete_dialog',
        'permission_callback' => function () { return current_user_can('manage_options'); },
        'args'                => [
            'id' => [ 'validate_callback' => 'is_numeric' ],
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

/**
 * Admin: получить диалог и все сообщения.
 */
function cw_rest_admin_get_dialog( WP_REST_Request $request ) {
    global $wpdb;

    $dialog_id = intval($request->get_param('id'));

    $dialog = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}cw_dialogs WHERE id = %d",
        $dialog_id
    ));

    if (!$dialog) {
        return new WP_Error('cw_not_found', 'Диалог не найден.', ['status' => 404]);
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
 * Admin: добавить сообщение от оператора.
 */
function cw_rest_admin_add_message( WP_REST_Request $request ) {
    global $wpdb;

    $dialog_id = intval($request->get_param('id'));
    $message   = sanitize_textarea_field($request->get_param('message'));

    if (empty($message)) {
        return new WP_Error('cw_bad_request', 'Сообщение не может быть пустым.', ['status' => 400]);
    }

    $dialog = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}cw_dialogs WHERE id = %d",
        $dialog_id
    ));

    if (!$dialog) {
        return new WP_Error('cw_not_found', 'Диалог не найден.', ['status' => 404]);
    }

    $insert_message = $wpdb->insert(
        $wpdb->prefix . 'cw_messages',
        [
            'dialog_id'  => $dialog_id,
            'sender'     => 'admin',
            'message'    => $message,
            'created_at' => current_time('mysql'),
        ]
    );

    if ($insert_message === false) {
        return new WP_Error('cw_db_error', 'Не удалось сохранить сообщение.', ['status' => 500]);
    }

    if (function_exists('cw_send_to_telegram')) {
        $text = "📨 Ответ оператора\n<b>Диалог #{$dialog_id}</b>\n\n" . esc_html($message);
        cw_send_to_telegram($text);
    }

    return new WP_REST_Response(['status' => 'ok'], 200);
}

/**
 * Admin: закрыть диалог.
 */
function cw_rest_admin_close_dialog( WP_REST_Request $request ) {
    global $wpdb;

    $dialog_id = intval($request->get_param('id'));

    $updated = $wpdb->update(
        $wpdb->prefix . 'cw_dialogs',
        [ 'status' => 'closed' ],
        [ 'id' => $dialog_id ]
    );

    if ($updated === false) {
        return new WP_Error('cw_db_error', 'Не удалось закрыть диалог.', ['status' => 500]);
    }

    return new WP_REST_Response(['status' => 'ok'], 200);
}

/**
 * Admin: удалить диалог вместе с сообщениями.
 */
function cw_rest_admin_delete_dialog( WP_REST_Request $request ) {
    global $wpdb;

    $dialog_id = intval($request->get_param('id'));

    $wpdb->delete($wpdb->prefix . 'cw_messages', [ 'dialog_id' => $dialog_id ]);
    $deleted = $wpdb->delete($wpdb->prefix . 'cw_dialogs', [ 'id' => $dialog_id ]);

    if ($deleted === false) {
        return new WP_Error('cw_db_error', 'Не удалось удалить диалог.', ['status' => 500]);
    }

    return new WP_REST_Response(['status' => 'deleted'], 200);
}
