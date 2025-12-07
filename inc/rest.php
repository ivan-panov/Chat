<?php

// REST маршрут для создания диалога и первого сообщения
add_action('rest_api_init', function () {
    register_rest_route('cw/v1', '/dialog', [
        'methods'             => 'POST',
        'callback'            => 'cw_rest_create_dialog',
        'permission_callback' => '__return_true', // без авторизации
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
    $insert_dialog = $wpdb->insert(
        $dialogs_table,
        [
            'user_name'  => $name,
            'phone'      => $phone,
            'status'     => 'open',
            'created_at' => current_time('mysql'), // время WordPress
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
        ],
        200
    );
}
