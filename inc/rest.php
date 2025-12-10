<?php
if (!defined('ABSPATH')) exit;

global $wpdb;

/* ============================================================
   Безопасное получение IP (учёт прокси/forwarded)
============================================================ */
function cw_get_ip() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/* ============================================================
   GEO ПО IP
============================================================ */
function cw_geo($ip) {
    // Переключаемся на https
    $url = "https://ip-api.com/json/{$ip}?lang=ru";

    $response = wp_remote_get($url, ['timeout' => 5]);
    if (is_wp_error($response)) {
        return [
            'country' => '-', 'city' => '-', 'region' => '-', 'ip' => $ip,
            'browser' => ($_SERVER['HTTP_USER_AGENT'] ?? '-')
        ];
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    return [
        'country' => $data['country'] ?? '-',
        'city'    => $data['city'] ?? '-',
        'region'  => $data['regionName'] ?? '-',
        'ip'      => $ip,
        'browser' => ($_SERVER['HTTP_USER_AGENT'] ?? '-')
    ];
}

add_action('rest_api_init', function () {

    register_rest_route('cw/v1', '/dialogs', [
        'methods' => ['POST', 'GET'],
        'callback' => 'cw_rest_dialogs',
        'permission_callback' => '__return_true'
    ]);

    register_rest_route('cw/v1', '/dialogs/(?P<id>\d+)/messages', [
        'methods' => ['GET','POST'],
        'callback' => 'cw_rest_messages',
        'permission_callback' => '__return_true'
    ]);

    register_rest_route('cw/v1', '/dialogs/(?P<id>\d+)/close', [
        'methods' => 'POST',
        'callback' => 'cw_rest_close',
        'permission_callback' => '__return_true'
    ]);

    register_rest_route('cw/v1', '/dialogs/(?P<id>\d+)/delete', [
        'methods' => 'POST',
        'callback' => 'cw_rest_delete',
        'permission_callback' => '__return_true'
    ]);

    register_rest_route('cw/v1', '/dialogs/(?P<id>\d+)/read', [
        'methods' => 'POST',
        'callback' => 'cw_rest_read',
        'permission_callback' => '__return_true'
    ]);

    register_rest_route('cw/v1', '/dialogs/(?P<id>\d+)/geo', [
        'methods' => 'GET',
        'callback' => 'cw_rest_geo',
        'permission_callback' => '__return_true'
    ]);
});



/* ============================================================
   ДИАЛОГИ
============================================================ */
function cw_rest_dialogs(WP_REST_Request $r) {
    global $wpdb;
    $D = $wpdb->prefix . "cw_dialogs";
    $M = $wpdb->prefix . "cw_messages";

    /* ----- СОЗДАНИЕ НОВОГО ДИАЛОГА ----- */
    if ($r->get_method() === "POST") {

        // --- простая защита от частых запросов (rate-limit на IP) ---
        $ip = cw_get_ip();
        $key = 'cw_create_dialog_' . md5($ip);
        if (get_transient($key)) {
            return new WP_REST_Response(['error' => 'too_many_requests'], 429);
        }
        set_transient($key, 1, 5); // 1 запрос в 5 секунд

        // создаём диалог
        $wpdb->insert($D, [
            'status' => 'open',
            'created_at' => current_time('mysql')
        ]);

        $id = $wpdb->insert_id;

        // добавляем GEO
        $geo = cw_geo($ip);

        $wpdb->update($D, [
            'geo_ip'      => $geo['ip'],
            'geo_country' => $geo['country'],
            'geo_city'    => $geo['city'],
            'geo_region'  => $geo['region'],
            'geo_browser' => $geo['browser']
        ], ['id' => $id]);

        // ВАЖНО! создаём системное сообщение
        $wpdb->insert($M, [
            'dialog_id' => $id,
            'message' => '[system]Создан новый диалог.',
            'is_operator' => 1,
            'unread' => 0,
            'created_at' => current_time('mysql')
        ]);

        return ['id' => $id];
    }

    /* ----- СПИСОК ДИАЛОГОВ ----- */
    return $wpdb->get_results("
        SELECT d.*,
        (
            SELECT COUNT(*) FROM $M m
            WHERE m.dialog_id = d.id
            AND m.is_operator = 0
            AND m.unread = 1
        ) AS unread
        FROM $D d
        ORDER BY d.id DESC
    ");
}



/* ============================================================
   СООБЩЕНИЯ
============================================================ */
function cw_rest_messages(WP_REST_Request $r) {
    global $wpdb;

    $id = intval($r['id']);
    $D = $wpdb->prefix . "cw_dialogs";
    $M = $wpdb->prefix . "cw_messages";

    // используем prepare
    $status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$D} WHERE id=%d", $id ) );

    /* ---- GET ---- */
    if ($r->get_method() === "GET") {

        // возвращаем историю — используем prepare
        $msgs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$M} WHERE dialog_id=%d ORDER BY id ASC", $id ) );

        $res = new WP_REST_Response($msgs);
        $res->header("X-Dialog-Status", $status);
        return $res;
    }

    /* ---- POST ---- */
    // Если диалог закрыт — запрет для клиентов (non-operator)
    $isOp = intval($r->get_param("operator"));

    // Проверяем header nonce для возможности авторизации через REST
    $nonce = $r->get_header('X-WP-Nonce') ?: $r->get_header('X_WP_Nonce');

    // Если кто-то пытается выставить operator=1 — разрешаем только admin / валидный nonce
    if ($isOp) {
        if ( ! current_user_can('manage_options') ) {
            if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
                return new WP_REST_Response(['error' => 'permission_denied'], 403);
            }
        }
    }

    if ($status === 'closed' && !$isOp) {
        return new WP_REST_Response(['error' => 'dialog_closed'], 403);
    }

    // Ограничим длину входящего сообщения
    $raw_msg = $r->get_param("message");
    if (!is_string($raw_msg)) $raw_msg = '';
    $msg = sanitize_text_field( mb_substr($raw_msg, 0, 2000) );

    $wpdb->insert($M, [
        'dialog_id' => $id,
        'message' => $msg,
        'is_operator' => $isOp,
        'unread' => $isOp ? 0 : 1,
        'created_at' => current_time('mysql')
    ]);

    // Telegram уведомление — только для клиентских сообщений
    if (!$isOp) {
        if (function_exists('cw_tg_notify_operator')) {
            cw_tg_notify_operator($id, $msg);
        }
    }

    return ['status' => 'ok'];
}


/* ============================================================
   ЗАКРЫТЬ ДИАЛОГ
============================================================ */
function cw_rest_close(WP_REST_Request $r) {
    global $wpdb;
    $id = intval($r['id']);
    $D = $wpdb->prefix . "cw_dialogs";
    $M = $wpdb->prefix . "cw_messages";

    // только админ может закрыть диалог
    $nonce = $r->get_header('X-WP-Nonce') ?: $r->get_header('X_WP_Nonce');
    if ( ! current_user_can('manage_options') ) {
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new WP_REST_Response(['error' => 'permission_denied'], 403);
        }
    }

    $wpdb->update($D, ['status' => 'closed'], ['id'=>$id]);

    $wpdb->insert($M, [
        'dialog_id' => $id,
        'message' => '[system]Диалог закрыт оператором.',
        'is_operator' => 1,
        'unread' => 1,
        'created_at' => current_time('mysql')
    ]);

    return ['status' => 'closed'];
}


/* ============================================================
   УДАЛИТЬ ДИАЛОГ
============================================================ */
function cw_rest_delete(WP_REST_Request $r) {
    global $wpdb;
    $id = intval($r['id']);

    // только админ
    $nonce = $r->get_header('X-WP-Nonce') ?: $r->get_header('X_WP_Nonce');
    if ( ! current_user_can('manage_options') ) {
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new WP_REST_Response(['error' => 'permission_denied'], 403);
        }
    }

    $wpdb->delete($wpdb->prefix . "cw_messages", ['dialog_id' => $id]);
    $wpdb->delete($wpdb->prefix . "cw_dialogs", ['id' => $id]);

    return ['status' => 'deleted'];
}


/* ============================================================
   ПОМЕТИТЬ ПРОЧИТАННЫМИ
============================================================ */
function cw_rest_read(WP_REST_Request $r) {
    global $wpdb;
    $id = intval($r['id']);

    // Только оператор/админ может пометить прочитанным
    $nonce = $r->get_header('X-WP-Nonce') ?: $r->get_header('X_WP_Nonce');
    if ( ! current_user_can('manage_options') ) {
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new WP_REST_Response(['error' => 'permission_denied'], 403);
        }
    }

    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->prefix}cw_messages SET unread=0 WHERE dialog_id=%d AND is_operator=0",
            $id
        )
    );

    return ['status' => 'read'];
}


/* ============================================================
   GEO
============================================================ */
function cw_rest_geo(WP_REST_Request $r) {
    global $wpdb;
    $id = intval($r['id']);

    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT geo_country, geo_city, geo_region, geo_ip, geo_browser FROM {$wpdb->prefix}cw_dialogs WHERE id=%d",
            $id
        )
    );
}
