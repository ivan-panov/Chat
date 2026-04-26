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


function cw_tg_direct_webhook_url(): string {
    return plugins_url('tg-webhook.php', dirname(__FILE__));
}

function cw_tg_is_valid_webhook_secret(string $secret): bool {
    if ($secret === '') {
        return true;
    }

    return (bool) preg_match('/^[A-Za-z0-9_-]{1,256}$/', $secret);
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
    $method          = strtoupper((string) ($args['method'] ?? 'GET'));
    $body            = $args['body'] ?? [];
    $headers         = (array) ($args['headers'] ?? []);
    $timeout         = max(1, intval($args['timeout'] ?? 25));
    $connect_timeout = max(1, intval($args['connect_timeout'] ?? min(20, $timeout)));

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
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connect_timeout);
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

function cw_tg_api_request(string $method, array $body = [], string $http_method = 'POST', int $timeout = 25, int $connect_timeout = 20) {
    $token = cw_tg_get_token();
    if ($token === '') {
        return new WP_Error('cw_tg_no_token', 'Bot Token не заполнен.');
    }

    $response = cw_tg_http_request(
        "https://api.telegram.org/bot{$token}/{$method}",
        [
            'method'          => $http_method,
            'body'            => $body,
            'timeout'         => $timeout,
            'connect_timeout' => $connect_timeout,
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
    if ($chat_id === 0) return;

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
    if ($chat_id === 0 || empty($attachment_or_url)) return;

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


function cw_tg_answer_callback_query(string $callback_id, string $text = '', bool $show_alert = false): void {
    $callback_id = trim((string) $callback_id);
    if ($callback_id === '') return;

    $body = [
        'callback_query_id' => $callback_id,
        'show_alert'        => $show_alert ? 'true' : 'false',
    ];

    if ($text !== '') {
        $body['text'] = mb_substr($text, 0, 200);
    }

    cw_tg_api('answerCallbackQuery', $body);
}


function cw_tg_callback_payload(string $callback_id, string $text = '', bool $show_alert = false): array {
    $callback_id = trim((string) $callback_id);

    $payload = [
        'method'     => 'answerCallbackQuery',
        'show_alert' => $show_alert,
    ];

    if ($callback_id !== '') {
        $payload['callback_query_id'] = $callback_id;
    }

    if ($text !== '') {
        $payload['text'] = mb_substr($text, 0, 200);
    }

    return $payload;
}

function cw_tg_callback_response(string $callback_id, string $text = '', bool $show_alert = false): WP_REST_Response {
    return new WP_REST_Response(cw_tg_callback_payload($callback_id, $text, $show_alert), 200);
}

function cw_tg_finish_webhook_response_now($payload, int $status = 200): void {
    if (!headers_sent()) {
        status_header($status);
        nocache_headers();
        header('Content-Type: application/json; charset=' . get_option('blog_charset'));
        header('Connection: close');
    }

    $json = wp_json_encode($payload);
    if ($json === false) {
        $json = '{"status":"ok"}';
    }

    echo $json;

    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
        return;
    }

    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @flush();
}

function cw_tg_is_authorized_callback(array $callback, int $admin_chat_id): bool {
    if ($admin_chat_id === 0) {
        return false;
    }

    $from_id = intval($callback['from']['id'] ?? 0);
    $chat_id = intval($callback['message']['chat']['id'] ?? 0);

    return $from_id === $admin_chat_id || $chat_id === $admin_chat_id;
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
            "SELECT geo_city, geo_org, geo_ip, geo_browser
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

    $city = trim((string) ($dialog['geo_city'] ?? ''));
    $provider = trim((string) ($dialog['geo_org'] ?? ''));
    $ip = trim((string) ($dialog['geo_ip'] ?? ''));
    $tz = cw_tg_extract_timezone_from_browser_label((string) ($dialog['geo_browser'] ?? ''));

    $lines = [];
    $lines[] = '📜 <b>История диалога #' . intval($dialog_id) . '</b>';

    if ($city !== '' || $ip !== '' || $provider !== '' || $tz !== '') {
        $lines[] = '';

        if ($city !== '') {
            $lines[] = '<b>Город:</b> ' . esc_html($city);
        }

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
    if ($admin_chat === 0) return;

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


function cw_tg_build_callback_signature(string $action, int $dialog_id, int $timestamp): string {
    return hash_hmac('sha256', sanitize_key($action) . '|' . intval($dialog_id) . '|' . intval($timestamp), cw_tg_get_async_secret());
}

function cw_tg_verify_callback_signature(string $action, int $dialog_id, int $timestamp, string $signature): bool {
    $action = sanitize_key($action);
    $signature = trim($signature);

    if ($action === '' || $dialog_id <= 0 || $timestamp <= 0 || $signature === '') {
        return false;
    }

    if (abs(time() - $timestamp) > 300) {
        return false;
    }

    $expected = cw_tg_build_callback_signature($action, $dialog_id, $timestamp);

    return hash_equals($expected, $signature);
}

function cw_tg_register_post_response_queue_runner(): void {
    static $registered = false;
    if ($registered) {
        return;
    }

    $registered = true;

    register_shutdown_function(function () {
        global $cw_tg_post_response_jobs;

        ignore_user_abort(true);

        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        $jobs = is_array($cw_tg_post_response_jobs) ? $cw_tg_post_response_jobs : [];
        $cw_tg_post_response_jobs = [];

        $processed = 0;
        foreach ($jobs as $job) {
            if ($processed >= 25) {
                break;
            }

            $type = sanitize_key($job['type'] ?? '');
            $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];

            if ($type === 'callback_action') {
                cw_tg_process_callback_action(
                    sanitize_key((string) ($payload['action'] ?? '')),
                    intval($payload['dialog'] ?? 0)
                );
            } elseif ($type === 'incoming_message') {
                $message = is_array($payload['message'] ?? null) ? $payload['message'] : [];
                cw_tg_process_incoming_message_job($message);
            }

            $processed++;
        }
    });
}

function cw_tg_add_queue_job(string $type, array $payload): bool {
    $type = sanitize_key($type);
    if ($type === '') {
        return false;
    }

    global $cw_tg_post_response_jobs;
    if (!isset($cw_tg_post_response_jobs) || !is_array($cw_tg_post_response_jobs)) {
        $cw_tg_post_response_jobs = [];
    }

    $cw_tg_post_response_jobs[] = [
        'type'       => $type,
        'payload'    => $payload,
        'created_at' => time(),
    ];

    cw_tg_register_post_response_queue_runner();

    return true;
}

function cw_tg_schedule_callback_action(string $action, int $dialog_id): void {
    $action = sanitize_key($action);
    $dialog_id = intval($dialog_id);

    if ($action === '' || $dialog_id <= 0) return;

    cw_tg_add_queue_job('callback_action', [
        'action' => $action,
        'dialog' => $dialog_id,
    ]);
}

function cw_tg_queue_incoming_message_job(array $message): bool {
    return cw_tg_add_queue_job('incoming_message', [
        'message' => $message,
    ]);
}

function cw_tg_process_queue(): void {
    // Backward compatibility stub. Telegram webhook updates are processed by tg-worker.php.
    if (function_exists('cw_tg_process_file_queue')) {
        cw_tg_process_file_queue(25);
    }
}

function cw_tg_file_queue_dir(): string {
    $dir = '';

    if (function_exists('wp_upload_dir')) {
        $upload = wp_upload_dir(null, false);
        if (is_array($upload) && !empty($upload['basedir'])) {
            $dir = trailingslashit((string) $upload['basedir']) . 'cw-tg-queue';
        }
    }

    if ($dir === '' && defined('WP_CONTENT_DIR')) {
        $dir = trailingslashit(WP_CONTENT_DIR) . 'uploads/cw-tg-queue';
    }

    if ($dir !== '') {
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        if (is_dir($dir)) {
            @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
            @file_put_contents($dir . '/.htaccess', "Deny from all\n");
            return $dir;
        }
    }

    $fallback = rtrim(sys_get_temp_dir(), '/\\') . '/cw-tg-queue-' . md5(dirname(__DIR__));
    if (!is_dir($fallback)) {
        @mkdir($fallback, 0755, true);
    }
    return $fallback;
}

function cw_tg_process_file_queue(int $limit = 25): array {
    $limit = max(1, min(100, intval($limit)));
    $dir = cw_tg_file_queue_dir();

    if ($dir === '' || !is_dir($dir)) {
        return ['status' => 'queue_dir_missing'];
    }

    $queue_file = $dir . '/queue.jsonl';
    $lock_file  = $dir . '/worker.lock';

    if (!file_exists($queue_file)) {
        return ['status' => 'empty', 'processed' => 0, 'remaining' => 0];
    }

    $lock = @fopen($lock_file, 'c');
    if (!$lock) {
        return ['status' => 'lock_open_failed'];
    }

    if (!@flock($lock, LOCK_EX | LOCK_NB)) {
        @fclose($lock);
        return ['status' => 'locked'];
    }

    $lines = @file($queue_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines) || empty($lines)) {
        @file_put_contents($queue_file, '');
        @flock($lock, LOCK_UN);
        @fclose($lock);
        return ['status' => 'empty', 'processed' => 0, 'remaining' => 0];
    }

    $batch = array_slice($lines, 0, $limit);
    $remaining = array_slice($lines, $limit);
    @file_put_contents($queue_file, empty($remaining) ? '' : implode("\n", $remaining) . "\n", LOCK_EX);

    @flock($lock, LOCK_UN);
    @fclose($lock);

    $processed = 0;
    $errors = 0;

    foreach ($batch as $line) {
        $job = json_decode((string) $line, true);
        if (!is_array($job) || !isset($job['update']) || !is_array($job['update'])) {
            $errors++;
            continue;
        }

        $header_secret = (string) ($job['header_secret'] ?? '');

        try {
            if (function_exists('cw_tg_webhook_process_update_after_ack')) {
                cw_tg_webhook_process_update_after_ack($job['update'], $header_secret);
                $processed++;
            } else {
                $errors++;
            }
        } catch (Throwable $e) {
            $errors++;
            if (function_exists('cw_tg_log_error')) {
                cw_tg_log_error('Queue worker error: ' . $e->getMessage());
            }
        }
    }

    return [
        'status'    => 'processed',
        'processed' => $processed,
        'errors'    => $errors,
        'remaining' => count($remaining),
    ];
}

function cw_tg_get_webhook_ip(): string {
    return '';
}

function cw_tg_async_endpoint_path(string $route): string {
    $route = '/' . ltrim($route, '/');
    $url = rest_url('cw/v1' . $route);
    $path = (string) wp_parse_url($url, PHP_URL_PATH);
    $query = (string) wp_parse_url($url, PHP_URL_QUERY);

    if ($path === '') {
        $path = '/wp-json/cw/v1' . $route;
    }

    if ($query !== '') {
        $path .= '?' . $query;
    }

    return $path;
}

function cw_tg_fire_and_forget_post(string $route, array $body, float $timeout = 0.35): bool {
    $home = home_url('/');
    $scheme = strtolower((string) wp_parse_url($home, PHP_URL_SCHEME));
    $host = strtolower((string) wp_parse_url($home, PHP_URL_HOST));

    if ($host === '') {
        return false;
    }

    $is_https = $scheme !== 'http';
    $port = $is_https ? 443 : 80;
    $connect_host = cw_tg_get_webhook_ip();
    if ($connect_host === '') {
        $connect_host = $host;
    }

    $path = cw_tg_async_endpoint_path($route);
    $payload = http_build_query($body, '', '&');

    $headers = "POST {$path} HTTP/1.1\r\n";
    $headers .= "Host: {$host}\r\n";
    $headers .= "User-Agent: CW-Telegram-Async/1.0\r\n";
    $headers .= "Content-Type: application/x-www-form-urlencoded; charset=UTF-8\r\n";
    $headers .= "Content-Length: " . strlen($payload) . "\r\n";
    $headers .= "Connection: Close\r\n\r\n";

    $context_options = [];
    if ($is_https) {
        $context_options['ssl'] = [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'SNI_enabled'       => true,
            'SNI_server_name'   => $host,
            'peer_name'         => $host,
        ];
    }

    $context = stream_context_create($context_options);
    $target = ($is_https ? 'ssl://' : 'tcp://') . $connect_host . ':' . $port;
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client($target, $errno, $errstr, max(0.05, $timeout), STREAM_CLIENT_CONNECT, $context);

    if (!$fp) {
        cw_tg_log_error('Async socket dispatch failed: ' . $errstr . ' (' . $errno . ')');
        return false;
    }

    stream_set_timeout($fp, 1);
    $request = $headers . $payload;
    $written = @fwrite($fp, $request);
    @fflush($fp);
    @fclose($fp);

    return $written !== false && $written > 0;
}

function cw_tg_dispatch_callback_action_async(string $action, int $dialog_id): bool {
    if (!cw_tg_enabled()) return false;

    $action = sanitize_key($action);
    $dialog_id = intval($dialog_id);

    if ($action === '' || $dialog_id <= 0) return false;

    $timestamp = time();
    $signature = cw_tg_build_callback_signature($action, $dialog_id, $timestamp);

    return cw_tg_fire_and_forget_post('/tg-callback', [
        'action' => $action,
        'dialog' => $dialog_id,
        'ts'     => $timestamp,
        'sig'    => $signature,
    ], 0.35);
}

function cw_tg_build_incoming_signature(string $payload, int $timestamp): string {
    return hash_hmac('sha256', $payload . '|' . intval($timestamp), cw_tg_get_async_secret());
}

function cw_tg_verify_incoming_signature(string $payload, int $timestamp, string $signature): bool {
    $signature = trim($signature);

    if ($payload === '' || $timestamp <= 0 || $signature === '') {
        return false;
    }

    if (abs(time() - $timestamp) > 300) {
        return false;
    }

    $expected = cw_tg_build_incoming_signature($payload, $timestamp);

    return hash_equals($expected, $signature);
}

function cw_tg_dispatch_incoming_message_async(array $message): bool {
    if (!cw_tg_enabled()) return false;

    $payload = wp_json_encode($message);
    if (!is_string($payload) || $payload === '') {
        return false;
    }

    $timestamp = time();
    $signature = cw_tg_build_incoming_signature($payload, $timestamp);

    return cw_tg_fire_and_forget_post('/tg-incoming', [
        'message' => $payload,
        'ts'      => $timestamp,
        'sig'     => $signature,
    ], 0.35);
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
    if (cw_tg_get_admin_chat() === 0) return false;

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

    register_rest_route('cw/v1', '/tg-callback', [
        'methods'  => ['POST'],
        'callback' => 'cw_tg_async_callback_handler',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('cw/v1', '/tg-incoming', [
        'methods'  => ['POST'],
        'callback' => 'cw_tg_async_incoming_handler',
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


function cw_tg_async_callback_handler(WP_REST_Request $r) {
    if (!cw_tg_enabled()) {
        return new WP_REST_Response(['status' => 'integration_disabled'], 200);
    }

    $action = sanitize_key((string) $r->get_param('action'));
    $dialog = intval($r->get_param('dialog'));
    $timestamp = intval($r->get_param('ts'));
    $signature = (string) $r->get_param('sig');

    if (!cw_tg_verify_callback_signature($action, $dialog, $timestamp, $signature)) {
        return new WP_REST_Response(['error' => 'forbidden'], 403);
    }

    $result = cw_tg_process_callback_action($action, $dialog);

    return new WP_REST_Response($result, 200);
}

function cw_tg_async_incoming_handler(WP_REST_Request $r) {
    if (!cw_tg_enabled()) {
        return new WP_REST_Response(['status' => 'integration_disabled'], 200);
    }

    $payload = (string) $r->get_param('message');
    $timestamp = intval($r->get_param('ts'));
    $signature = (string) $r->get_param('sig');

    if (!cw_tg_verify_incoming_signature($payload, $timestamp, $signature)) {
        return new WP_REST_Response(['error' => 'forbidden'], 403);
    }

    $message = json_decode($payload, true);
    if (!is_array($message)) {
        return new WP_REST_Response(['status' => 'invalid_message'], 200);
    }

    $result = cw_tg_process_incoming_message_job($message);

    return new WP_REST_Response($result, 200);
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


function cw_tg_process_incoming_message_job(array $msg): array {
    global $wpdb;

    $admin = cw_tg_get_admin_chat();
    if ($admin === 0) {
        return ['status' => 'no_admin_chat'];
    }

    $tableD = $wpdb->prefix . 'cw_dialogs';
    $tableM = $wpdb->prefix . 'cw_messages';

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

function cw_tg_process_callback_action(string $action, int $dialog): array {
    global $wpdb;

    $action = sanitize_key($action);
    $dialog = intval($dialog);
    $admin  = cw_tg_get_admin_chat();

    if ($admin === 0) {
        return ['status' => 'no_admin_chat'];
    }

    if ($dialog <= 0 || $action === '') {
        return ['status' => 'bad_callback'];
    }

    $tableD = $wpdb->prefix . 'cw_dialogs';
    $tableM = $wpdb->prefix . 'cw_messages';

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
        return ['status' => 'closed'];
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
        return ['status' => 'reply_selected'];
    }

    if ($action === 'history') {
        $history_text = cw_tg_get_dialog_history_text($dialog, 12);
        cw_tg_send($admin, $history_text);
        return ['status' => 'history_sent'];
    }

    if ($action === 'stats') {
        $stats_text = cw_tg_dialog_stats_text($dialog);
        cw_tg_send($admin, $stats_text);
        return ['status' => 'stats_sent'];
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
        return ['status' => 'sbp_sent'];
    }

    cw_tg_send($admin, 'Неизвестная команда Telegram-кнопки.');
    return ['status' => 'unknown_action'];
}

function cw_tg_webhook_process_update_after_ack(array $update, string $header_secret = ''): array {
    $admin  = cw_tg_get_admin_chat();
    $secret = cw_tg_get_webhook_secret();

    if ($secret !== '' && (!$header_secret || !hash_equals($secret, $header_secret))) {
        return ['status' => 'forbidden'];
    }

    if (!cw_tg_enabled()) {
        return ['status' => 'integration_disabled'];
    }

    $update_id = isset($update['update_id']) ? intval($update['update_id']) : 0;
    if ($update_id > 0) {
        $update_lock_key = 'cw_tg_update_' . $update_id;
        if (get_transient($update_lock_key)) {
            return ['status' => 'duplicate_update'];
        }
        set_transient($update_lock_key, 1, 10 * MINUTE_IN_SECONDS);
    }

    if (!empty($update['callback_query']) && is_array($update['callback_query'])) {
        $cb          = $update['callback_query'];
        $callback_id = (string) ($cb['id'] ?? '');
        $data        = (string) ($cb['data'] ?? '');

        if (!cw_tg_is_authorized_callback($cb, $admin)) {
            return ['status' => 'forbidden_callback'];
        }

        if (!preg_match('/^cw_(\w+)_(\d+)$/', $data, $matches)) {
            return ['status' => 'bad_callback_data'];
        }

        $action = sanitize_key($matches[1]);
        $dialog = intval($matches[2]);

        if ($callback_id !== '') {
            $callback_lock_key = 'cw_tg_cb_' . md5($callback_id);
            if (get_transient($callback_lock_key)) {
                return ['status' => 'duplicate_callback'];
            }
            set_transient($callback_lock_key, 1, 3 * MINUTE_IN_SECONDS);
        }

        return cw_tg_process_callback_action($action, $dialog);
    }

    if (!empty($update['message']) && is_array($update['message'])) {
        $msg  = $update['message'];
        $from = intval($msg['from']['id'] ?? 0);

        if ($from !== $admin) {
            return ['status' => 'ignored'];
        }

        return cw_tg_process_incoming_message_job($msg);
    }

    return ['status' => 'ok'];
}

function cw_tg_webhook_process_update(array $update, string $header_secret = ''): array {
    $admin  = cw_tg_get_admin_chat();
    $secret = cw_tg_get_webhook_secret();

    if ($secret !== '' && (!$header_secret || !hash_equals($secret, $header_secret))) {
        return [403, ['error' => 'forbidden']];
    }

    if (!cw_tg_enabled()) {
        return [200, ['status' => 'integration_disabled']];
    }

    if (!empty($update['callback_query']) && is_array($update['callback_query'])) {
        $cb          = $update['callback_query'];
        $callback_id = (string) ($cb['id'] ?? '');
        $data        = (string) ($cb['data'] ?? '');

        if (!cw_tg_is_authorized_callback($cb, $admin)) {
            return [200, cw_tg_callback_payload($callback_id, 'Нет доступа к этой кнопке.', true)];
        }

        if (!preg_match('/^cw_(\w+)_(\d+)$/', $data, $matches)) {
            return [200, cw_tg_callback_payload($callback_id, 'Неизвестная кнопка.', true)];
        }

        $action = sanitize_key($matches[1]);
        $dialog = intval($matches[2]);

        if ($callback_id !== '') {
            $callback_lock_key = 'cw_tg_cb_' . md5($callback_id);
            if (get_transient($callback_lock_key)) {
                return [200, cw_tg_callback_payload($callback_id, 'Команда уже принята.')];
            }
        }

        $dispatched = cw_tg_dispatch_callback_action_async($action, $dialog);
        if (!$dispatched) {
            return [200, cw_tg_callback_payload($callback_id, 'Не удалось запустить команду.', true)];
        }

        if ($callback_id !== '') {
            set_transient($callback_lock_key, 1, 3 * MINUTE_IN_SECONDS);
        }

        return [200, cw_tg_callback_payload($callback_id, 'Команда принята.')];
    }

    if (!empty($update['message']) && is_array($update['message'])) {
        $msg  = $update['message'];
        $from = intval($msg['from']['id'] ?? 0);

        if ($from !== $admin) {
            return [200, ['status' => 'ignored']];
        }

        $dispatched = cw_tg_dispatch_incoming_message_async($msg);

        return [200, [
            'status'     => $dispatched ? 'accepted' : 'dispatch_failed',
            'dispatched' => $dispatched,
        ]];
    }

    return [200, ['status' => 'ok']];
}

function cw_tg_webhook_handler(WP_REST_Request $r) {
    $update = $r->get_json_params();
    if (!is_array($update)) {
        return new WP_REST_Response(['status' => 'invalid'], 200);
    }

    [$status, $payload] = cw_tg_webhook_process_update(
        $update,
        (string) $r->get_header('X-Telegram-Bot-Api-Secret-Token')
    );

    return new WP_REST_Response($payload, $status);
}
