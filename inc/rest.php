<?php
if (!defined('ABSPATH')) exit;

global $wpdb;

/* ============================================================
   GEO ПО IP
============================================================ */
function cw_geo($ip) {
    $url = "http://ip-api.com/json/{$ip}?lang=ru";

    $response = wp_remote_get($url);
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


/* ============================================================
   REST API РОУТЫ
============================================================ */
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

        // создаём диалог
        $wpdb->insert($D, [
            'status' => 'open',
            'created_at' => current_time('mysql')
        ]);

        $id = $wpdb->insert_id;

        // добавляем GEO
        $geo = cw_geo($_SERVER['REMOTE_ADDR']);

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

    $status = $wpdb->get_var("SELECT status FROM $D WHERE id=$id");

    /* ---- GET ---- */
    if ($r->get_method() === "GET") {

        // теперь НЕ возвращаем пустой массив — выдаём историю даже если closed
        $msgs = $wpdb->get_results("SELECT * FROM $M WHERE dialog_id=$id ORDER BY id ASC");

        $res = new WP_REST_Response($msgs);
        $res->header("X-Dialog-Status", $status);
        return $res;
    }

    /* ---- POST ---- */
    if ($status === 'closed' && !$r->get_param('operator')) {
        return new WP_REST_Response(['error' => 'dialog_closed'], 403);
    }

    $msg = sanitize_text_field($r->get_param("message"));
    $isOp = intval($r->get_param("operator"));

    $wpdb->insert($M, [
        'dialog_id' => $id,
        'message' => $msg,
        'is_operator' => $isOp,
        'unread' => $isOp ? 0 : 1,
        'created_at' => current_time('mysql')
    ]);

    // Telegram уведомление
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

    $wpdb->query("
        UPDATE {$wpdb->prefix}cw_messages
        SET unread=0
        WHERE dialog_id=$id AND is_operator=0
    ");

    return ['status' => 'read'];
}


/* ============================================================
   GEO
============================================================ */
function cw_rest_geo(WP_REST_Request $r) {
    global $wpdb;
    $id = intval($r['id']);

    return $wpdb->get_row("
        SELECT geo_country, geo_city, geo_region, geo_ip, geo_browser
        FROM {$wpdb->prefix}cw_dialogs
        WHERE id=$id
    ");
}
