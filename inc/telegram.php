<?php
if (!defined('ABSPATH')) exit;

function cw_tg_enabled(): bool {
    return (int) get_option('cw_tg_enabled', 1) === 1;
}

function cw_tg_get_token(): string {
    return trim((string) get_option('cw_tg_token', ''));
}

function cw_tg_get_admin_chat(): int {
    return intval(get_option('cw_tg_admin_chat', 0));
}

function cw_tg_get_webhook_secret(): string {
    return trim((string) get_option('cw_tg_webhook_secret', ''));
}

function cw_tg_proxy_enabled(): bool {
    return (int) get_option('cw_tg_proxy_enabled', 0) === 1;
}

function cw_tg_proxy_host(): string {
    return trim((string) get_option('cw_tg_proxy_host', ''));
}

function cw_tg_proxy_port(): int {
    $port = intval(get_option('cw_tg_proxy_port', 1080));
    return $port > 0 ? $port : 1080;
}

function cw_tg_proxy_user(): string {
    return trim((string) get_option('cw_tg_proxy_user', ''));
}

function cw_tg_proxy_pass(): string {
    return (string) get_option('cw_tg_proxy_pass', '');
}

function cw_tg_proxy_rdns(): bool {
    return (int) get_option('cw_tg_proxy_rdns', 1) === 1;
}

function cw_tg_is_telegram_url(string $url): bool {
    $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    return in_array($host, ['api.telegram.org'], true);
}

function cw_tg_log_error(string $message): void {
    error_log('CW TG: ' . $message);
}

function cw_tg_http_request(string $url, array $args = []) {
    $method  = strtoupper((string) ($args['method'] ?? 'GET'));
    $body    = $args['body'] ?? [];
    $headers = (array) ($args['headers'] ?? []);
    $timeout = max(1, intval($args['timeout'] ?? 25));

    $use_proxy = cw_tg_proxy_enabled() && cw_tg_is_telegram_url($url);

    if ($use_proxy) {
        if (!function_exists('curl_init')) {
            return new WP_Error('cw_tg_curl_missing', 'Для SOCKS5 прокси требуется расширение cURL в PHP.');
        }

        $host = cw_tg_proxy_host();
        $port = cw_tg_proxy_port();

        if ($host === '' || $port <= 0) {
            return new WP_Error('cw_tg_proxy_invalid', 'Не заполнены host/port SOCKS5 прокси.');
        }

        if ($method === 'GET' && !empty($body)) {
            $query = is_array($body) ? http_build_query($body) : (string) $body;
            if ($query !== '') {
                $url .= (strpos($url, '?') === false ? '?' : '&') . $query;
            }
        }

        $ch = curl_init();
        if ($ch === false) {
            return new WP_Error('cw_tg_curl_init_failed', 'Не удалось инициализировать cURL.');
        }

        $curl_headers = [];
        foreach ($headers as $k => $v) {
            if (is_int($k)) {
                $curl_headers[] = (string) $v;
            } else {
                $curl_headers[] = $k . ': ' . $v;
            }
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_PROXY, $host);
        curl_setopt($ch, CURLOPT_PROXYPORT, $port);

        if (cw_tg_proxy_rdns() && defined('CURLPROXY_SOCKS5_HOSTNAME')) {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
        } else {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
        }

        $proxy_user = cw_tg_proxy_user();
        $proxy_pass = cw_tg_proxy_pass();
        if ($proxy_user !== '' || $proxy_pass !== '') {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy_user . ':' . $proxy_pass);
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if (!empty($body)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : (string) $body);
            }
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if (!empty($body)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : (string) $body);
            }
        }

        if (!empty($curl_headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_headers);
        }

        $response_body = curl_exec($ch);
        $curl_error    = curl_error($ch);
        $response_code = intval(curl_getinfo($ch, CURLINFO_RESPONSE_CODE));
        curl_close($ch);

        if ($response_body === false) {
            return new WP_Error('cw_tg_curl_error', $curl_error !== '' ? $curl_error : 'Неизвестная ошибка cURL.');
        }

        return [
            'response' => ['code' => $response_code],
            'body'     => $response_body,
            'headers'  => [],
        ];
    }

    $request_args = [
        'method'  => $method,
        'timeout' => $timeout,
        'headers' => $headers,
    ];

    if (!empty($body)) {
        $request_args['body'] = $body;
    }

    return wp_remote_request($url, $request_args);
}

function cw_tg_api_request(string $method, array $body = [], string $http_method = 'POST') {
    $token = cw_tg_get_token();
    if ($token === '') {
        return new WP_Error('cw_tg_no_token', 'Bot Token не заполнен.');
    }

    $response = cw_tg_http_request(
        "https://api.telegram.org/bot{$token}/{$method}",
        [
            'method'  => $http_method,
            'body'    => $body,
            'timeout' => 25,
        ]
    );

    if (is_wp_error($response)) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $resp = (string) wp_remote_retrieve_body($response);
    $json = json_decode($resp, true);

    if ($code !== 200 || !is_array($json) || empty($json['ok'])) {
        return new WP_Error(
            'cw_tg_bad_response',
            'Telegram вернул ошибку.',
            [
                'http_code' => $code,
                'body'      => $resp,
            ]
        );
    }

    return $json;
}

function cw_tg_api(string $method, array $body = []) {
    $response = cw_tg_api_request($method, $body, 'POST');

    if (is_wp_error($response)) {
        $extra = $response->get_error_data();
        $body_text = is_array($extra) && !empty($extra['body']) ? ' | body: ' . $extra['body'] : '';
        cw_tg_log_error($response->get_error_message() . $body_text);
        return false;
    }

    return $response;
}

function cw_tg_send(int $chat_id, string $text, $keyboard = null): void {
    if ($chat_id <= 0) return;

    $body = [
        'chat_id'    => $chat_id,
        'text'       => $text,
        'parse_mode' => 'HTML',
    ];

    if ($keyboard) {
        $body['reply_markup'] = wp_json_encode([
            'inline_keyboard' => $keyboard,
        ]);
    }

    cw_tg_api('sendMessage', $body);
}

function cw_tg_send_photo(int $chat_id, $attachment_or_url, string $caption = '', $keyboard = null): void {
    if ($chat_id <= 0 || empty($attachment_or_url)) return;

    $photo_url = $attachment_or_url;

    if (is_numeric($attachment_or_url)) {
        $attachment_url = wp_get_attachment_url((int) $attachment_or_url);
        if ($attachment_url) {
            $photo_url = $attachment_url;
        }
    }

    if (!$photo_url) return;

    $body = [
        'chat_id' => $chat_id,
        'photo'   => $photo_url,
    ];

    if ($caption !== '') {
        $body['caption'] = $caption;
        $body['parse_mode'] = 'HTML';
    }

    if ($keyboard) {
        $body['reply_markup'] = wp_json_encode([
            'inline_keyboard' => $keyboard,
        ]);
    }

    cw_tg_api('sendPhoto', $body);
}

function cw_tg_escape_html_text(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cw_tg_prepare_message_preview(string $message): array {
    $message = trim((string) $message);

    if ($message === '') {
        return [
            'role' => 'other',
            'text' => '',
        ];
    }

    if (stripos($message, '[system]') === 0) {
        $text = trim((string) mb_substr($message, 8));
        $text = wp_strip_all_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);

        return [
            'role' => 'system',
            'text' => trim((string) $text),
        ];
    }

    if (stripos($message, '[image]') === 0) {
        return [
            'role' => 'media',
            'text' => '[Изображение]',
        ];
    }

    if (stripos($message, '[file]') === 0) {
        $payload = trim((string) mb_substr($message, 6));
        $name = 'Файл';

        $pos = strpos($payload, '|');
        if ($pos !== false) {
            $name = trim((string) substr($payload, $pos + 1));
        }

        if ($name === '') {
            $name = 'Файл';
        }

        return [
            'role' => 'media',
            'text' => '[Файл] ' . $name,
        ];
    }

    $message = wp_strip_all_tags($message);
    $message = html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $message = preg_replace('/\s+/u', ' ', $message);
    $message = trim((string) $message);

    if (mb_strlen($message) > 280) {
        $message = mb_substr($message, 0, 280) . '...';
    }

    return [
        'role' => 'text',
        'text' => $message,
    ];
}

function cw_tg_history_time_label(string $datetime): string {
    $datetime = trim($datetime);
    if ($datetime === '') {
        return '';
    }

    $ts = strtotime($datetime);
    if (!$ts) {
        return $datetime;
    }

    return date_i18n('H:i', $ts);
}

function cw_tg_extract_timezone_from_browser_label(string $browser_label): string {
    $browser_label = trim($browser_label);
    if ($browser_label === '') {
        return '';
    }

    if (preg_match('/(?:^|\|)\s*TZ:\s*([^|]+)/u', $browser_label, $m)) {
        return trim((string) ($m[1] ?? ''));
    }

    return '';
}

function cw_tg_format_dialog_ids_for_stats(array $ids): string {
    $ids = array_values(array_filter(array_map('intval', $ids), static function ($id) {
        return $id > 0;
    }));

    if (!$ids) {
        return '-';
    }

    $parts = array_map(static function ($id) {
        return '#' . $id;
    }, $ids);

    $result = implode(', ', $parts);

    if (mb_strlen($result) > 3000) {
        $result = mb_substr($result, 0, 3000) . '...';
    }

    return $result;
}

function cw_tg_get_dialog_history_text(int $dialog_id, int $limit = 12): string {
    global $wpdb;

    if ($dialog_id <= 0) {
        return 'Диалог не найден.';
    }

    if (function_exists('cw_ensure_dialog_geo_loaded')) {
        cw_ensure_dialog_geo_loaded($dialog_id);
    }

    $limit = max(1, min(30, (int) $limit));
    $tableD = $wpdb->prefix . 'cw_dialogs';
    $tableM = $wpdb->prefix . 'cw_messages';

    $dialog = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT geo_org, geo_ip, geo_browser
             FROM {$tableD}
             WHERE id = %d",
            $dialog_id
        ),
        ARRAY_A
    );

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, message, is_operator, is_bot, created_at
             FROM {$tableM}
             WHERE dialog_id = %d
             ORDER BY id DESC
             LIMIT %d",
            $dialog_id,
            $limit
        ),
        ARRAY_A
    );

    if (!$rows) {
        return 'История диалога #' . intval($dialog_id) . ' пуста.';
    }

    $rows = array_reverse($rows);

    $provider = trim((string) ($dialog['geo_org'] ?? ''));
    $ip = trim((string) ($dialog['geo_ip'] ?? ''));
    $tz = cw_tg_extract_timezone_from_browser_label((string) ($dialog['geo_browser'] ?? ''));

    $lines = [];
    $lines[] = '📜 <b>История диалога #' . intval($dialog_id) . '</b>';

    if ($ip !== '' || $provider !== '' || $tz !== '') {
        $lines[] = '';

        if ($ip !== '') {
            $lines[] = '<b>IP:</b> ' . esc_html($ip);
        }

        if ($provider !== '') {
            $lines[] = '<b>Провайдер:</b> ' . esc_html($provider);
        }

        if ($tz !== '') {
            $lines[] = '<b>TZ:</b> ' . esc_html($tz);
        }
    }

    $lines[] = '';

    foreach ($rows as $row) {
        $preview = cw_tg_prepare_message_preview((string) ($row['message'] ?? ''));
        $message_text = trim((string) ($preview['text'] ?? ''));

        if ($message_text === '') {
            continue;
        }

        $time = cw_tg_history_time_label((string) ($row['created_at'] ?? ''));
        $is_operator = (int) ($row['is_operator'] ?? 0) === 1;
        $is_bot = (int) ($row['is_bot'] ?? 0) === 1;

        if (($preview['role'] ?? '') === 'system') {
            $title = '🔔 <b>Система</b>';
        } elseif ($is_bot) {
            $title = '🤖 <b>Бот</b>';
        } elseif ($is_operator) {
            $title = '🧑‍💼 <b>Оператор</b>';
        } else {
            $title = '👤 <b>Пользователь</b>';
        }

        if ($time !== '') {
            $title .= ' · <code>' . esc_html($time) . '</code>';
        }

        $lines[] = $title;
        $lines[] = esc_html($message_text);
        $lines[] = '';
    }

    while (!empty($lines) && end($lines) === '') {
        array_pop($lines);
    }

    $result = implode("
", $lines);

    if (mb_strlen(wp_strip_all_tags($result)) > 3500) {
        $result = mb_substr($result, 0, 3900) . "
...";
    }

    return $result;
}

function cw_tg_dialog_stats_text(int $dialog_id): string {
    global $wpdb;

    unset($dialog_id);

    $D = $wpdb->prefix . 'cw_dialogs';

    $open_ids = $wpdb->get_col(
        "SELECT id
         FROM {$D}
         WHERE status = 'open'
         ORDER BY id DESC"
    );

    $closed_ids = $wpdb->get_col(
        "SELECT id
         FROM {$D}
         WHERE status = 'closed'
         ORDER BY id DESC"
    );

    $open_ids = is_array($open_ids) ? $open_ids : [];
    $closed_ids = is_array($closed_ids) ? $closed_ids : [];

    $lines = [];
    $lines[] = '📊 <b>Статистика диалогов</b>';
    $lines[] = '';
    $lines[] = '<b>Открытые:</b> ' . count($open_ids);
    $lines[] = esc_html(cw_tg_format_dialog_ids_for_stats($open_ids));
    $lines[] = '';
    $lines[] = '<b>Закрытые:</b> ' . count($closed_ids);
    $lines[] = esc_html(cw_tg_format_dialog_ids_for_stats($closed_ids));

    $result = implode("
", $lines);

    if (mb_strlen(wp_strip_all_tags($result)) > 3500) {
        $result = mb_substr($result, 0, 3900) . "
...";
    }

    return $result;
}

function cw_tg_notify_operator(int $dialog_id, string $message): void {
    if (!cw_tg_enabled()) return;

    $admin_chat = cw_tg_get_admin_chat();
    if ($admin_chat <= 0) return;

    $trimmed = trim((string) $message);

    $keyboard = [
        [
            [
                'text' => '✉ Ответить',
                'callback_data' => 'cw_reply_' . $dialog_id,
            ],
            [
                'text' => '📜 История диалога',
                'callback_data' => 'cw_history_' . $dialog_id,
            ],
        ],
        [
            [
                'text' => '❌ Закрыть',
                'callback_data' => 'cw_close_' . $dialog_id,
            ],
            [
                'text' => '📊 Статистика',
                'callback_data' => 'cw_stats_' . $dialog_id,
            ],
        ],
        [
            [
                'text' => '💳 СБП QR',
                'callback_data' => 'cw_sbp_' . $dialog_id,
            ],
        ],
    ];

    if (stripos($trimmed, '[image]') === 0) {
        $url = trim((string) mb_substr($trimmed, 7));
        $url = esc_url($url);

        $caption  = "🖼 <b>Новое изображение</b>\n";
        $caption .= "<b>Диалог:</b> #{$dialog_id}";

        if ($url !== '') {
            cw_tg_send_photo($admin_chat, $url, $caption, $keyboard);
        } else {
            cw_tg_send($admin_chat, "🖼 <b>Новое изображение</b>\n<b>Диалог:</b> #{$dialog_id}\n(не удалось получить URL)", $keyboard);
        }
        return;
    }

    if (stripos($trimmed, '[file]') === 0) {
        $payload = trim((string) mb_substr($trimmed, 6));
        $url  = $payload;
        $name = '';

        $pos = strpos($payload, '|');
        if ($pos !== false) {
            $url  = trim((string) substr($payload, 0, $pos));
            $name = trim((string) substr($payload, $pos + 1));
        }

        $url = esc_url($url);
        if ($name === '') {
            $name = $url !== '' ? basename((string) wp_parse_url($url, PHP_URL_PATH)) : '';
        }
        if ($name === '') {
            $name = 'Файл';
        }

        $safe_name = esc_html($name);

        $text  = "📎 <b>Новый файл</b>\n\n";
        $text .= "<b>Диалог:</b> #{$dialog_id}\n";
        if ($url !== '') {
            $text .= "<b>Файл:</b>\n<a href=\"{$url}\">{$safe_name}</a>";
        } else {
            $text .= "<b>Файл:</b>\n" . esc_html($payload);
        }

        cw_tg_send($admin_chat, $text, $keyboard);
        return;
    }

    $safe_message = esc_html($message);

    $text  = "📩 <b>Новое сообщение</b>\n\n";
    $text .= "<b>Диалог:</b> #{$dialog_id}\n";
    $text .= "<b>Текст:</b>\n{$safe_message}";

    cw_tg_send($admin_chat, $text, $keyboard);
}

function cw_tg_get_async_secret(): string {
    return hash_hmac('sha256', 'cw_tg_async_notify|' . home_url('/'), wp_salt('auth'));
}

function cw_tg_build_async_signature(int $message_id, int $timestamp): string {
    return hash_hmac('sha256', $message_id . '|' . $timestamp, cw_tg_get_async_secret());
}

function cw_tg_verify_async_signature(int $message_id, int $timestamp, string $signature): bool {
    $signature = trim($signature);
    if ($message_id <= 0 || $timestamp <= 0 || $signature === '') {
        return false;
    }

    if (abs(time() - $timestamp) > 300) {
        return false;
    }

    $expected = cw_tg_build_async_signature($message_id, $timestamp);

    return hash_equals($expected, $signature);
}

function cw_tg_process_message_notification(int $message_id): void {
    if (!cw_tg_enabled()) return;
    if ($message_id <= 0) return;

    global $wpdb;

    $table = $wpdb->prefix . 'cw_messages';
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT dialog_id, message, is_operator FROM {$table} WHERE id=%d",
            $message_id
        ),
        ARRAY_A
    );

    if (!$row) return;
    if ((int) ($row['is_operator'] ?? 0) === 1) return;

    $dialog_id = (int) ($row['dialog_id'] ?? 0);
    $message   = (string) ($row['message'] ?? '');

    if ($dialog_id <= 0 || $message === '') return;
    if (stripos($message, '[system]') === 0) return;

    cw_tg_notify_operator($dialog_id, $message);
}

function cw_tg_schedule_post_response_notification(int $message_id): void {
    if ($message_id <= 0) return;

    global $cw_tg_post_response_queue;
    if (!isset($cw_tg_post_response_queue) || !is_array($cw_tg_post_response_queue)) {
        $cw_tg_post_response_queue = [];
    }

    if (!in_array($message_id, $cw_tg_post_response_queue, true)) {
        $cw_tg_post_response_queue[] = $message_id;
    }

    static $registered = false;
    if ($registered) {
        return;
    }

    $registered = true;

    register_shutdown_function(function () {
        global $cw_tg_post_response_queue;

        ignore_user_abort(true);

        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        $queue = is_array($cw_tg_post_response_queue)
            ? array_values(array_unique(array_map('intval', $cw_tg_post_response_queue)))
            : [];

        $cw_tg_post_response_queue = [];

        foreach ($queue as $queued_message_id) {
            if ($queued_message_id > 0) {
                cw_tg_process_message_notification($queued_message_id);
            }
        }
    });
}

function cw_tg_dispatch_message_notification_async(int $message_id): bool {
    if (!cw_tg_enabled()) return false;
    if ($message_id <= 0) return false;
    if (cw_tg_get_token() === '') return false;
    if (cw_tg_get_admin_chat() <= 0) return false;

    $timestamp = time();
    $signature = cw_tg_build_async_signature($message_id, $timestamp);
    $url = rest_url('cw/v1/tg-notify');

    $args = [
        'timeout'     => 0.01,
        'blocking'    => false,
        'sslverify'   => apply_filters('https_local_ssl_verify', false),
        'redirection' => 0,
        'body'        => [
            'message_id' => $message_id,
            'ts'         => $timestamp,
            'sig'        => $signature,
        ],
    ];

    $response = wp_remote_post($url, $args);

    if (is_wp_error($response)) {
        cw_tg_log_error('Async notify dispatch failed, switched to post-response fallback: ' . $response->get_error_message());
        cw_tg_schedule_post_response_notification($message_id);
        return false;
    }

    return true;
}

function cw_tg_queue_message_notification(int $message_id, ?int $delay_seconds = null): void {
    unset($delay_seconds);
    cw_tg_dispatch_message_notification_async($message_id);
}

function cw_tg_download_photo_to_media(string $file_id) {
    $token = cw_tg_get_token();
    if ($token === '' || $file_id === '') return false;

    $info = cw_tg_api('getFile', ['file_id' => $file_id]);
    if (!$info || empty($info['result']['file_path'])) return false;

    $file_path = (string) $info['result']['file_path'];
    $file_url  = "https://api.telegram.org/file/bot{$token}/{$file_path}";

    $response = cw_tg_http_request($file_url, [
        'method'  => 'GET',
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        cw_tg_log_error('FILE DOWNLOAD ERROR: ' . $response->get_error_message());
        return false;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $data = wp_remote_retrieve_body($response);

    if ($code !== 200 || $data === '') {
        cw_tg_log_error('FILE DOWNLOAD BAD: ' . $code);
        return false;
    }

    $name = basename($file_path);
    if ($name === '') {
        $name = 'tg_photo_' . time() . '.jpg';
    }

    $upload = wp_upload_bits($name, null, $data);
    if (!empty($upload['error'])) {
        cw_tg_log_error('UPLOAD BITS ERROR: ' . $upload['error']);
        return false;
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';

    $filetype = wp_check_filetype($upload['file'], null);

    $attachment = [
        'post_mime_type' => $filetype['type'] ?: 'image/jpeg',
        'post_title'     => sanitize_file_name($name),
        'post_content'   => '',
        'post_status'    => 'inherit',
    ];

    $attach_id = wp_insert_attachment($attachment, $upload['file']);
    if (is_wp_error($attach_id) || !$attach_id) {
        cw_tg_log_error('wp_insert_attachment ERROR');
        return false;
    }

    $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
    wp_update_attachment_metadata($attach_id, $attach_data);

    return $attach_id;
}

add_action('rest_api_init', function () {
    register_rest_route('cw/v1', '/tg-webhook', [
        'methods'  => ['POST'],
        'callback' => 'cw_tg_webhook_handler',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('cw/v1', '/tg-notify', [
        'methods'  => ['POST'],
        'callback' => 'cw_tg_async_notify_handler',
        'permission_callback' => '__return_true',
    ]);
});

function cw_tg_async_notify_handler(WP_REST_Request $r) {
    if (!cw_tg_enabled()) {
        return new WP_REST_Response(['status' => 'integration_disabled'], 200);
    }

    $message_id = (int) $r->get_param('message_id');
    $timestamp  = (int) $r->get_param('ts');
    $signature  = (string) $r->get_param('sig');

    if (!cw_tg_verify_async_signature($message_id, $timestamp, $signature)) {
        return new WP_REST_Response(['error' => 'forbidden'], 403);
    }

    cw_tg_process_message_notification($message_id);

    return ['status' => 'processed'];
}

function cw_tg_get_dialog_status(int $dialog_id): string {
    global $wpdb;

    if ($dialog_id <= 0) {
        return '';
    }

    return (string) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}cw_dialogs WHERE id = %d",
            $dialog_id
        )
    );
}

function cw_tg_webhook_handler(WP_REST_Request $r) {
    global $wpdb;

    $admin  = cw_tg_get_admin_chat();
    $secret = cw_tg_get_webhook_secret();

    if ($secret !== '') {
        $header_secret = $r->get_header('X-Telegram-Bot-Api-Secret-Token');
        if (!$header_secret || !hash_equals($secret, $header_secret)) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }
    }

    if (!cw_tg_enabled()) {
        return new WP_REST_Response(['status' => 'integration_disabled'], 200);
    }

    $update = $r->get_json_params();
    if (!is_array($update)) {
        return new WP_REST_Response(['status' => 'invalid'], 200);
    }

    $tableD = $wpdb->prefix . 'cw_dialogs';
    $tableM = $wpdb->prefix . 'cw_messages';

    if (!empty($update['callback_query'])) {
        $cb   = $update['callback_query'];
        $data = $cb['data'] ?? '';
        $from = intval($cb['from']['id'] ?? 0);

        if ($from !== $admin) return ['status' => 'ignored'];

        if (!preg_match('/^cw_(\w+)_(\d+)$/', (string) $data, $matches)) {
            return ['status' => 'bad_callback'];
        }

        $action = sanitize_key($matches[1]);
        $dialog = intval($matches[2]);

        if ($action === 'close') {
            $current_status = (string) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT status FROM {$tableD} WHERE id = %d",
                    $dialog
                )
            );

            if ($current_status === '') {
                cw_tg_send($admin, "Диалог #{$dialog} не найден.");
                return ['status' => 'dialog_not_found'];
            }

            if ($current_status === 'closed') {
                cw_tg_send($admin, "Диалог #{$dialog} ранее закрыт.");
                return ['status' => 'already_closed'];
            }

            if (function_exists('cw_mark_user_messages_read_by_operator')) {
                cw_mark_user_messages_read_by_operator($dialog);
            }

            $wpdb->update($tableD, ['status' => 'closed'], ['id' => $dialog]);

            $wpdb->insert($tableM, [
                'dialog_id'   => $dialog,
                'message'     => '[system]Диалог закрыт через Telegram.',
                'is_operator' => 1,
                'unread'      => 1,
                'created_at'  => current_time('mysql'),
            ]);

            cw_tg_send($admin, "Диалог #{$dialog} закрыт.");
        }

        if ($action === 'reply') {
            $current_status = cw_tg_get_dialog_status($dialog);

            if ($current_status === '') {
                delete_user_meta($admin, 'cw_reply_dialog');
                cw_tg_send($admin, "Диалог #{$dialog} не найден.");
                return ['status' => 'dialog_not_found'];
            }

            if ($current_status === 'closed') {
                delete_user_meta($admin, 'cw_reply_dialog');
                cw_tg_send($admin, "Диалог #{$dialog} ранее закрыт.");
                return ['status' => 'already_closed'];
            }

            update_user_meta($admin, 'cw_reply_dialog', $dialog);
            cw_tg_send($admin, "Отправьте сообщение или фото для диалога #{$dialog}");
        }

        if ($action === 'history') {
            $history_text = cw_tg_get_dialog_history_text($dialog, 12);
            cw_tg_send($admin, $history_text);
        }

        if ($action === 'stats') {
            $stats_text = cw_tg_dialog_stats_text($dialog);
            cw_tg_send($admin, $stats_text);
        }

        if ($action === 'sbp') {
            $payment_url = 'https://qr.nspk.ru/AS1A005O0HP24U9I8P0AN5VA03QFOL1F?type=01&bank=100000000111&crc=30da';
            $payment_text = '<a href="' . esc_url($payment_url) . '" target="_blank">СБП QR</a>';

            if (function_exists('cw_mark_user_messages_read_by_operator')) {
                cw_mark_user_messages_read_by_operator($dialog);
            }

            $wpdb->insert($tableM, [
                'dialog_id'   => $dialog,
                'message'     => $payment_text,
                'is_operator' => 1,
                'unread'      => 1,
                'created_at'  => current_time('mysql'),
            ]);

            cw_tg_send($admin, "СБП QR отправлено в диалог #{$dialog}");
        }

        return ['status' => 'callback_processed'];
    }

    if (!empty($update['message'])) {
        $msg  = $update['message'];
        $from = intval($msg['from']['id'] ?? 0);

        if ($from !== $admin) {
            return ['status' => 'ignored'];
        }

        $dialog = intval(get_user_meta($admin, 'cw_reply_dialog', true));
        if ($dialog <= 0) {
            cw_tg_send($admin, 'Сначала нажмите «✉ Ответить» у нужного диалога.');
            return ['status' => 'no_dialog_selected'];
        }

        $current_status = cw_tg_get_dialog_status($dialog);

        if ($current_status === '') {
            delete_user_meta($admin, 'cw_reply_dialog');
            cw_tg_send($admin, "Диалог #{$dialog} не найден.");
            return ['status' => 'dialog_not_found'];
        }

        if ($current_status === 'closed') {
            delete_user_meta($admin, 'cw_reply_dialog');
            cw_tg_send($admin, "Диалог #{$dialog} ранее закрыт.");
            return ['status' => 'already_closed'];
        }

        if (!empty($msg['text'])) {
            $text = sanitize_text_field($msg['text']);

            if (function_exists('cw_mark_user_messages_read_by_operator')) {
                cw_mark_user_messages_read_by_operator($dialog);
            }

            $wpdb->insert($tableM, [
                'dialog_id'   => $dialog,
                'message'     => $text,
                'is_operator' => 1,
                'unread'      => 1,
                'created_at'  => current_time('mysql'),
            ]);

            delete_user_meta($admin, 'cw_reply_dialog');
            cw_tg_send($admin, "Ответ отправлен в диалог #{$dialog}");

            return ['status' => 'text_processed'];
        }

        if (!empty($msg['photo']) && is_array($msg['photo'])) {
            $photo = end($msg['photo']);
            $file_id = $photo['file_id'] ?? '';

            if ($file_id === '') {
                cw_tg_send($admin, 'Не удалось получить file_id фото.');
                return ['status' => 'photo_no_fileid'];
            }

            $attach_id = cw_tg_download_photo_to_media($file_id);
            if (!$attach_id) {
                cw_tg_send($admin, 'Не удалось скачать или сохранить фото на сайт.');
                return ['status' => 'photo_save_failed'];
            }

            $url = wp_get_attachment_url($attach_id);
            if (!$url) {
                cw_tg_send($admin, 'Фото сохранено, но URL не получен.');
                return ['status' => 'photo_url_failed'];
            }

            if (function_exists('cw_mark_user_messages_read_by_operator')) {
                cw_mark_user_messages_read_by_operator($dialog);
            }

            $wpdb->insert($tableM, [
                'dialog_id'   => $dialog,
                'message'     => '[image]' . esc_url_raw($url),
                'is_operator' => 1,
                'unread'      => 1,
                'created_at'  => current_time('mysql'),
            ]);

            delete_user_meta($admin, 'cw_reply_dialog');
            cw_tg_send($admin, "Фото отправлено в диалог #{$dialog}");

            return ['status' => 'photo_processed'];
        }

        cw_tg_send($admin, 'Поддерживается только текст или фото.');
        return ['status' => 'unsupported_message'];
    }

    return ['status' => 'ok'];
}