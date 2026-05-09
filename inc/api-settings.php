<?php
if (!defined('ABSPATH')) exit;

/* ============================================================
   CRM API SETTINGS + DISPATCH HELPERS
============================================================ */

function cw_api_enabled(): bool {
    return (int) get_option('cw_api_enabled', 0) === 1;
}

function cw_api_get_settings(): array {
    $timeout = (int) get_option('cw_api_timeout', 10);
    if ($timeout <= 0) {
        $timeout = 10;
    }

    return [
        'enabled'                => (int) get_option('cw_api_enabled', 0),
        'url'                    => trim((string) get_option('cw_api_crm_url', '')),
        'auth_header'            => trim((string) get_option('cw_api_auth_header', 'Authorization')),
        'auth_prefix'            => trim((string) get_option('cw_api_auth_prefix', 'Bearer')),
        'auth_token'             => (string) get_option('cw_api_auth_token', ''),
        'secret'                 => (string) get_option('cw_api_secret', ''),
        'timeout'                => max(1, min(60, $timeout)),
        'verify_ssl'             => (int) get_option('cw_api_verify_ssl', 1),
        'send_dialog_created'    => (int) get_option('cw_api_send_dialog_created', 1),
        'send_client_messages'   => (int) get_option('cw_api_send_client_messages', 1),
        'send_operator_messages' => (int) get_option('cw_api_send_operator_messages', 0),
    ];
}

function cw_api_sanitize_header_name(string $header): string {
    $header = trim($header);
    $header = preg_replace('/[^A-Za-z0-9\-]/', '', $header);
    return is_string($header) && $header !== '' ? $header : 'Authorization';
}

function cw_api_collect_post(): array {
    $timeout = (int) ($_POST['cw_api_timeout'] ?? 10);
    if ($timeout <= 0) {
        $timeout = 10;
    }

    return [
        'enabled'                => !empty($_POST['cw_api_enabled']) ? 1 : 0,
        'url'                    => esc_url_raw(trim((string) wp_unslash($_POST['cw_api_crm_url'] ?? ''))),
        'auth_header'            => cw_api_sanitize_header_name((string) wp_unslash($_POST['cw_api_auth_header'] ?? 'Authorization')),
        'auth_prefix'            => sanitize_text_field(wp_unslash($_POST['cw_api_auth_prefix'] ?? 'Bearer')),
        'auth_token'             => (string) wp_unslash($_POST['cw_api_auth_token'] ?? ''),
        'secret'                 => (string) wp_unslash($_POST['cw_api_secret'] ?? ''),
        'timeout'                => max(1, min(60, $timeout)),
        'verify_ssl'             => !empty($_POST['cw_api_verify_ssl']) ? 1 : 0,
        'send_dialog_created'    => !empty($_POST['cw_api_send_dialog_created']) ? 1 : 0,
        'send_client_messages'   => !empty($_POST['cw_api_send_client_messages']) ? 1 : 0,
        'send_operator_messages' => !empty($_POST['cw_api_send_operator_messages']) ? 1 : 0,
    ];
}

function cw_api_save_settings(array $data): void {
    update_option('cw_api_enabled', (int) $data['enabled']);
    update_option('cw_api_crm_url', (string) $data['url']);
    update_option('cw_api_auth_header', cw_api_sanitize_header_name((string) $data['auth_header']));
    update_option('cw_api_auth_prefix', sanitize_text_field((string) $data['auth_prefix']));
    update_option('cw_api_auth_token', (string) $data['auth_token']);
    update_option('cw_api_secret', (string) $data['secret']);
    update_option('cw_api_timeout', max(1, min(60, (int) $data['timeout'])));
    update_option('cw_api_verify_ssl', (int) $data['verify_ssl']);
    update_option('cw_api_send_dialog_created', (int) $data['send_dialog_created']);
    update_option('cw_api_send_client_messages', (int) $data['send_client_messages']);
    update_option('cw_api_send_operator_messages', (int) $data['send_operator_messages']);
}

function cw_api_render_settings_alert(string $type, string $title, string $message): void {
    $type = in_array($type, ['success', 'warning', 'info', 'error'], true) ? $type : 'info';

    $icons = [
        'success' => 'OK',
        'warning' => '!',
        'info'    => 'i',
        'error'   => '!',
    ];

    $allowed_html = [
        'br'     => [],
        'strong' => [],
        'b'      => [],
        'em'     => [],
        'span'   => ['class' => []],
        'code'   => ['class' => [], 'style' => []],
    ];
    ?>
    <div class="cw-settings-alert cw-settings-alert-<?php echo esc_attr($type); ?>" role="status">
        <div class="cw-settings-alert-icon" aria-hidden="true"><?php echo esc_html($icons[$type]); ?></div>
        <div class="cw-settings-alert-content">
            <div class="cw-settings-alert-title"><?php echo esc_html($title); ?></div>
            <?php if ($message !== ''): ?>
                <div class="cw-settings-alert-body"><?php echo wp_kses($message, $allowed_html); ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

function cw_api_response_message($response): string {
    if (is_wp_error($response)) {
        return 'Ошибка: ' . esc_html($response->get_error_message());
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $body = trim((string) wp_remote_retrieve_body($response));
    $body_short = mb_substr($body, 0, 2000);

    $msg = 'HTTP код: <code>' . esc_html((string) $code) . '</code>';

    if ($body_short !== '') {
        $msg .= '<br>Ответ CRM:<br><code style="white-space:pre-wrap;word-break:break-word;display:block;max-width:100%;">' . esc_html($body_short) . '</code>';
    }

    return $msg;
}

function cw_api_build_headers(array $settings, string $event, string $body): array {
    $headers = [
        'Content-Type'        => 'application/json; charset=utf-8',
        'Accept'              => 'application/json',
        'X-Chat-Widget-Event' => $event,
        'X-Chat-Widget-Site'  => home_url('/'),
    ];

    $token = (string) ($settings['auth_token'] ?? '');
    if ($token !== '') {
        $header = cw_api_sanitize_header_name((string) ($settings['auth_header'] ?? 'Authorization'));
        $prefix = trim((string) ($settings['auth_prefix'] ?? ''));
        $headers[$header] = $prefix !== '' ? ($prefix . ' ' . $token) : $token;
        $headers['X-Chat-Widget-Auth-Header'] = $header;
        $headers['X-Chat-Widget-Auth-Prefix'] = $prefix;
        $headers['X-Chat-Widget-Api-Token'] = $token;
    }

    $secret = (string) ($settings['secret'] ?? '');
    if ($secret !== '') {
        $timestamp = (string) time();
        $headers['X-Chat-Widget-Timestamp'] = $timestamp;
        $headers['X-Chat-Widget-Signature'] = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    return $headers;
}

function cw_api_send_payload(array $payload, string $event = 'event', bool $blocking = true, ?array $override_settings = null) {
    $settings = is_array($override_settings) ? $override_settings : cw_api_get_settings();
    $url = trim((string) ($settings['url'] ?? ''));

    if ($url === '') {
        return new WP_Error('cw_api_no_url', 'Не указан CRM Endpoint URL.');
    }

    $body = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body) || $body === '') {
        return new WP_Error('cw_api_bad_payload', 'Не удалось сформировать JSON для CRM.');
    }

    $timeout = max(1, min(60, (int) ($settings['timeout'] ?? 10)));

    return wp_remote_post($url, [
        'timeout'     => $blocking ? $timeout : 0.1,
        'redirection' => 3,
        'blocking'    => $blocking,
        'sslverify'   => !empty($settings['verify_ssl']),
        'headers'     => cw_api_build_headers($settings, $event, $body),
        'body'        => $body,
    ]);
}

function cw_api_get_dialog_row(int $dialog_id): array {
    global $wpdb;

    if ($dialog_id <= 0) {
        return [];
    }

    $D = $wpdb->prefix . 'cw_dialogs';
    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$D} WHERE id=%d", $dialog_id),
        ARRAY_A
    );

    return is_array($row) ? $row : [];
}

function cw_api_get_message_row(int $message_id): array {
    global $wpdb;

    if ($message_id <= 0) {
        return [];
    }

    $M = $wpdb->prefix . 'cw_messages';
    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$M} WHERE id=%d", $message_id),
        ARRAY_A
    );

    return is_array($row) ? $row : [];
}

function cw_api_build_event_payload(string $event, int $dialog_id = 0, int $message_id = 0): array {
    $dialog = cw_api_get_dialog_row($dialog_id);
    $message = cw_api_get_message_row($message_id);

    return [
        'event'      => $event,
        'source'     => 'chat_widget',
        'site_url'   => home_url('/'),
        'admin_url'  => admin_url('admin.php?page=cw_operator'),
        'created_at' => current_time('mysql'),
        'dialog'     => $dialog ? [
            'id'            => (int) ($dialog['id'] ?? 0),
            'status'        => (string) ($dialog['status'] ?? ''),
            'client_key'    => (string) ($dialog['client_key'] ?? ''),
            'geo_country'   => (string) ($dialog['geo_country'] ?? ''),
            'geo_city'      => (string) ($dialog['geo_city'] ?? ''),
            'geo_region'    => (string) ($dialog['geo_region'] ?? ''),
            'geo_org'       => (string) ($dialog['geo_org'] ?? ''),
            'geo_ip'        => (string) ($dialog['geo_ip'] ?? ''),
            'geo_browser'   => (string) ($dialog['geo_browser'] ?? ''),
            'contact_email' => (string) ($dialog['contact_email'] ?? ''),
            'contact_phone' => (string) ($dialog['contact_phone'] ?? ''),
            'created_at'    => (string) ($dialog['created_at'] ?? ''),
        ] : null,
        'message'    => $message ? [
            'id'          => (int) ($message['id'] ?? 0),
            'dialog_id'   => (int) ($message['dialog_id'] ?? 0),
            'text'        => (string) ($message['message'] ?? ''),
            'is_operator' => (int) ($message['is_operator'] ?? 0),
            'is_bot'      => (int) ($message['is_bot'] ?? 0),
            'created_at'  => (string) ($message['created_at'] ?? ''),
        ] : null,
    ];
}

function cw_api_dispatch_event(string $event, int $dialog_id = 0, int $message_id = 0): void {
    if (!cw_api_enabled()) {
        return;
    }

    $settings = cw_api_get_settings();
    if (empty($settings['url'])) {
        return;
    }

    if ($event === 'dialog.created' && empty($settings['send_dialog_created'])) {
        return;
    }

    if ($event === 'message.created') {
        $message = cw_api_get_message_row($message_id);
        if (!$message) {
            return;
        }

        $is_operator = (int) ($message['is_operator'] ?? 0) === 1;
        if ($is_operator && empty($settings['send_operator_messages'])) {
            return;
        }
        if (!$is_operator && empty($settings['send_client_messages'])) {
            return;
        }
    }

    $payload = cw_api_build_event_payload($event, $dialog_id, $message_id);
    cw_api_send_payload($payload, $event, false, $settings);
}

function cw_api_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Access denied');
    }

    $saved = false;
    $test_result = '';
    $settings = cw_api_get_settings();

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        check_admin_referer('cw_api_settings_nonce');
        $settings = cw_api_collect_post();
        cw_api_save_settings($settings);

        if (isset($_POST['cw_api_save'])) {
            $saved = true;
        }

        if (isset($_POST['cw_api_send_test'])) {
            $payload = [
                'event'      => 'test',
                'source'     => 'chat_widget',
                'site_url'   => home_url('/'),
                'admin_url'  => admin_url('admin.php?page=cw_operator'),
                'created_at' => current_time('mysql'),
                'message'    => 'Тестовое подключение CRM API из Chat Widget.',
            ];

            $response = cw_api_send_payload($payload, 'test', true, $settings);
            $test_result = cw_api_response_message($response);
        }
    }

    $enabled = (int) ($settings['enabled'] ?? 0);
    ?>
    <div class="wrap cw-settings-page cw-api-settings-page">
        <div class="cw-settings-hero">
            <div>
                <div class="cw-settings-kicker">Chat Widget</div>
                <h1>API / CRM</h1>
                <p>Настройки подключения внешней CRM: endpoint, авторизация, подпись запросов и события, которые отправляет чат.</p>
            </div>
            <div class="cw-settings-status <?php echo $enabled ? 'is-active' : 'is-inactive'; ?>">
                <span class="cw-settings-status-icon" aria-hidden="true">
                    <?php if ($enabled): ?>
                        <svg viewBox="0 0 24 24" focusable="false">
                            <path d="M20 6L9 17l-5-5"></path>
                        </svg>
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" focusable="false">
                            <path d="M12 3v9"></path>
                            <path d="M6.35 7.35a8 8 0 1 0 11.3 0"></path>
                        </svg>
                    <?php endif; ?>
                </span>
                <span><?php echo $enabled ? 'CRM API включен' : 'CRM API выключен'; ?></span>
            </div>
        </div>

        <div class="cw-settings-notices" aria-live="polite">
            <?php
            if ($enabled) {
                cw_api_render_settings_alert(
                    'success',
                    'CRM API включен',
                    'События чата будут отправляться во внешнюю CRM, если указан рабочий Endpoint URL.'
                );
            } else {
                cw_api_render_settings_alert(
                    'warning',
                    'CRM API выключен',
                    '<strong>Автоматическая отправка диалогов и сообщений в CRM сейчас отключена.</strong> Кнопка теста работает независимо от этого переключателя.'
                );
            }

            if ($saved) {
                cw_api_render_settings_alert('success', 'Настройки сохранены', 'Изменения применены.');
            }

            if ($test_result !== '') {
                $plain = wp_strip_all_tags($test_result);
                $type = (strpos($plain, 'Ошибка:') === 0 || strpos($plain, 'HTTP код: 4') === 0 || strpos($plain, 'HTTP код: 5') === 0) ? 'error' : 'success';
                cw_api_render_settings_alert($type, 'Проверка подключения CRM', $test_result);
            }
            ?>
        </div>

        <form method="post" class="cw-settings-form">
            <?php wp_nonce_field('cw_api_settings_nonce'); ?>

            <div class="cw-settings-grid">
                <section class="cw-settings-card cw-api-card-compact">
                    <div class="cw-settings-card-head">
                        <div class="cw-settings-card-icon">API</div>
                        <div>
                            <h2>Основные настройки</h2>
                            <p>Включение CRM и адрес обработчика.</p>
                        </div>
                    </div>

                    <div class="cw-api-compact-stack">
                        <label class="cw-api-inline-check">
                            <input type="checkbox" name="cw_api_enabled" value="1" <?php checked($enabled, 1); ?>>
                            <span><strong>CRM API активен</strong><em>Отправлять события чата во внешнюю CRM</em></span>
                        </label>

                        <div class="cw-settings-field">
                            <label for="cw_api_crm_url">CRM Endpoint URL</label>
                            <input id="cw_api_crm_url" type="url" name="cw_api_crm_url" value="<?php echo esc_attr((string) ($settings['url'] ?? '')); ?>" placeholder="https://crm.example.ru/api/chat-widget" autocomplete="off" />
                        </div>

                        <div class="cw-api-short-row">
                            <div class="cw-settings-field">
                                <label for="cw_api_timeout">Timeout, сек.</label>
                                <input id="cw_api_timeout" type="number" min="1" max="60" name="cw_api_timeout" value="<?php echo esc_attr((string) ($settings['timeout'] ?? 10)); ?>" />
                            </div>
                            <label class="cw-api-inline-check cw-api-ssl-check">
                                <input type="checkbox" name="cw_api_verify_ssl" value="1" <?php checked((int) ($settings['verify_ssl'] ?? 1), 1); ?>>
                                <span><strong>Проверять SSL</strong><em>Для боевого сервера</em></span>
                            </label>
                        </div>
                    </div>
                </section>

                <section class="cw-settings-card">
                    <div class="cw-settings-card-head">
                        <div class="cw-settings-card-icon">KEY</div>
                        <div>
                            <h2>Авторизация</h2>
                            <p>Заголовок авторизации, токен и подпись запросов.</p>
                        </div>
                    </div>

                    <div class="cw-settings-two-columns">
                        <div class="cw-settings-field">
                            <label for="cw_api_auth_header">Заголовок токена</label>
                            <input id="cw_api_auth_header" type="text" name="cw_api_auth_header" value="<?php echo esc_attr((string) ($settings['auth_header'] ?? 'Authorization')); ?>" placeholder="Authorization" autocomplete="off" />
                            <p>Например: <code>Authorization</code> или <code>X-API-Key</code>.</p>
                        </div>
                        <div class="cw-settings-field">
                            <label for="cw_api_auth_prefix">Префикс</label>
                            <input id="cw_api_auth_prefix" type="text" name="cw_api_auth_prefix" value="<?php echo esc_attr((string) ($settings['auth_prefix'] ?? 'Bearer')); ?>" placeholder="Bearer" autocomplete="off" />
                            <p>Оставьте пустым, если CRM ждёт только токен.</p>
                        </div>
                    </div>

                    <div class="cw-settings-field">
                        <label for="cw_api_auth_token">API Token</label>
                        <input id="cw_api_auth_token" type="text" name="cw_api_auth_token" value="<?php echo esc_attr((string) ($settings['auth_token'] ?? '')); ?>" autocomplete="off" spellcheck="false" />
                        <p>Это же значение укажите в CRM: <code>chat_widget_inbound_token</code>.</p>
                    </div>

                    <div class="cw-settings-field">
                        <label for="cw_api_secret">Секрет подписи</label>
                        <input id="cw_api_secret" type="text" name="cw_api_secret" value="<?php echo esc_attr((string) ($settings['secret'] ?? '')); ?>" autocomplete="off" spellcheck="false" />
                        <p>Это же значение укажите в CRM: <code>chat_widget_inbound_secret</code>. Подпись HMAC SHA-256 добавляется автоматически.</p>
                    </div>
                </section>

                <section class="cw-settings-card cw-api-card-compact">
                    <div class="cw-settings-card-head">
                        <div class="cw-settings-card-icon">EVT</div>
                        <div>
                            <h2>События для CRM</h2>
                            <p>Что отправлять автоматически.</p>
                        </div>
                    </div>

                    <div class="cw-api-events-list">
                        <label class="cw-api-event-check">
                            <input type="checkbox" name="cw_api_send_dialog_created" value="1" <?php checked((int) ($settings['send_dialog_created'] ?? 1), 1); ?>>
                            <span><strong>Новый диалог</strong><code>dialog.created</code></span>
                        </label>

                        <label class="cw-api-event-check">
                            <input type="checkbox" name="cw_api_send_client_messages" value="1" <?php checked((int) ($settings['send_client_messages'] ?? 1), 1); ?>>
                            <span><strong>Сообщения клиента</strong><code>message.created</code></span>
                        </label>

                        <label class="cw-api-event-check">
                            <input type="checkbox" name="cw_api_send_operator_messages" value="1" <?php checked((int) ($settings['send_operator_messages'] ?? 0), 1); ?>>
                            <span><strong>Ответы оператора</strong><code>message.created</code></span>
                        </label>
                    </div>
                </section>

                <section class="cw-settings-card">
                    <div class="cw-settings-card-head">
                        <div class="cw-settings-card-icon">JSON</div>
                        <div>
                            <h2>Формат запроса</h2>
                            <p>CRM должна принимать POST-запрос с JSON-телом.</p>
                        </div>
                    </div>

                    <div class="cw-settings-help-list">
                        <p><strong>Метод:</strong> <code>POST</code></p>
                        <p><strong>Content-Type:</strong> <code>application/json; charset=utf-8</code></p>
                        <p><strong>События:</strong> <code>dialog.created</code>, <code>message.created</code>, <code>test</code></p>
                        <p><strong>Поля:</strong> <code>event</code>, <code>source</code>, <code>site_url</code>, <code>dialog</code>, <code>message</code>, <code>created_at</code></p>
                    </div>
                </section>
            </div>

            <div class="cw-settings-actions">
                <button type="submit" name="cw_api_save" class="button button-primary">Сохранить настройки</button>
                <button type="submit" name="cw_api_send_test" class="button">Проверить подключение</button>
            </div>
        </form>
    </div>
    <?php
}

/* ============================================================
   CRM INBOUND API FOR EXTERNAL CRM
============================================================ */

function cw_api_get_request_header_value(WP_REST_Request $r, string $name): string {
    $name = trim($name);
    if ($name === '') return '';

    $value = $r->get_header($name);
    if (is_string($value) && trim($value) !== '') {
        return trim($value);
    }

    $server_key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (!empty($_SERVER[$server_key])) {
        return trim((string) $_SERVER[$server_key]);
    }

    if (strtolower($name) === 'authorization' && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return trim((string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    return '';
}

function cw_api_extract_request_token(WP_REST_Request $r, array $settings): string {
    $header = cw_api_sanitize_header_name((string) ($settings['auth_header'] ?? 'Authorization'));
    $value  = cw_api_get_request_header_value($r, $header);

    if ($value === '' && strtolower($header) !== 'authorization') {
        $value = cw_api_get_request_header_value($r, 'Authorization');
    }

    if ($value === '') {
        return '';
    }

    $prefix = trim((string) ($settings['auth_prefix'] ?? ''));
    if ($prefix !== '') {
        $pattern = '/^' . preg_quote($prefix, '/') . '\s+(.+)$/i';
        if (preg_match($pattern, $value, $m)) {
            return trim((string) ($m[1] ?? ''));
        }
    }

    if (preg_match('/^Bearer\s+(.+)$/i', $value, $m)) {
        return trim((string) ($m[1] ?? ''));
    }

    return trim($value);
}

function cw_api_verify_request_signature(WP_REST_Request $r, array $settings): bool {
    $secret = (string) ($settings['secret'] ?? '');
    if ($secret === '') {
        return true;
    }

    $signature = cw_api_get_request_header_value($r, 'X-Chat-Widget-Signature');
    $timestamp = cw_api_get_request_header_value($r, 'X-Chat-Widget-Timestamp');

    if ($signature === '' || $timestamp === '' || !ctype_digit($timestamp)) {
        return false;
    }

    $ts = (int) $timestamp;
    if (abs(time() - $ts) > 300) {
        return false;
    }

    $body = (string) $r->get_body();
    $expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);

    return hash_equals($expected, $signature);
}

function cw_api_crm_permission_callback(WP_REST_Request $r) {
    $settings = cw_api_get_settings();

    if (empty($settings['enabled'])) {
        return new WP_REST_Response(['error' => 'crm_api_disabled'], 403);
    }

    $expected_token = (string) ($settings['auth_token'] ?? '');
    if ($expected_token === '') {
        return new WP_REST_Response(['error' => 'crm_api_token_not_configured'], 403);
    }

    $request_token = cw_api_extract_request_token($r, $settings);
    if ($request_token === '' || !hash_equals($expected_token, $request_token)) {
        return new WP_REST_Response(['error' => 'unauthorized'], 401);
    }

    if (!cw_api_verify_request_signature($r, $settings)) {
        return new WP_REST_Response(['error' => 'bad_signature'], 401);
    }

    return true;
}

function cw_api_crm_dialog_to_array(array $dialog): array {
    return [
        'id'            => (int) ($dialog['id'] ?? 0),
        'status'        => (string) ($dialog['status'] ?? ''),
        'client_key'    => (string) ($dialog['client_key'] ?? ''),
        'geo_country'   => (string) ($dialog['geo_country'] ?? ''),
        'geo_city'      => (string) ($dialog['geo_city'] ?? ''),
        'geo_region'    => (string) ($dialog['geo_region'] ?? ''),
        'geo_org'       => (string) ($dialog['geo_org'] ?? ''),
        'geo_ip'        => (string) ($dialog['geo_ip'] ?? ''),
        'geo_browser'   => (string) ($dialog['geo_browser'] ?? ''),
        'contact_email' => (string) ($dialog['contact_email'] ?? ''),
        'contact_phone' => (string) ($dialog['contact_phone'] ?? ''),
        'created_at'    => (string) ($dialog['created_at'] ?? ''),
        'unread'        => isset($dialog['unread']) ? (int) $dialog['unread'] : 0,
        'last_message'  => (string) ($dialog['last_message'] ?? ''),
        'last_message_at' => (string) ($dialog['last_message_at'] ?? ''),
    ];
}

function cw_api_crm_message_to_array(array $message): array {
    return [
        'id'          => (int) ($message['id'] ?? 0),
        'dialog_id'   => (int) ($message['dialog_id'] ?? 0),
        'message'     => (string) ($message['message'] ?? ''),
        'is_operator' => (int) ($message['is_operator'] ?? 0),
        'is_bot'      => (int) ($message['is_bot'] ?? 0),
        'unread'      => (int) ($message['unread'] ?? 0),
        'created_at'  => (string) ($message['created_at'] ?? ''),
    ];
}

function cw_api_crm_ping(WP_REST_Request $r) {
    return [
        'status'   => 'ok',
        'site_url' => home_url('/'),
        'time'     => current_time('mysql'),
    ];
}

function cw_api_crm_dialogs(WP_REST_Request $r) {
    global $wpdb;

    $D = $wpdb->prefix . 'cw_dialogs';
    $M = $wpdb->prefix . 'cw_messages';

    $status = sanitize_text_field((string) $r->get_param('status'));
    $limit  = max(1, min(100, (int) ($r->get_param('limit') ?: 50)));
    $offset = max(0, (int) ($r->get_param('offset') ?: 0));

    $where = '';
    $params = [];

    if ($status !== '' && in_array($status, ['open', 'closed'], true)) {
        $where = 'WHERE d.status = %s';
        $params[] = $status;
    }

    $sql = "
        SELECT d.*,
               (SELECT COUNT(*) FROM {$M} um WHERE um.dialog_id = d.id AND um.unread = 1 AND um.is_operator = 0) AS unread,
               (SELECT lm.message FROM {$M} lm WHERE lm.dialog_id = d.id ORDER BY lm.id DESC LIMIT 1) AS last_message,
               (SELECT lm.created_at FROM {$M} lm WHERE lm.dialog_id = d.id ORDER BY lm.id DESC LIMIT 1) AS last_message_at
        FROM {$D} d
        {$where}
        ORDER BY COALESCE((SELECT lm2.id FROM {$M} lm2 WHERE lm2.dialog_id = d.id ORDER BY lm2.id DESC LIMIT 1), d.id) DESC
        LIMIT %d OFFSET %d
    ";

    $params[] = $limit;
    $params[] = $offset;

    $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    $items = [];

    foreach ((array) $rows as $row) {
        $items[] = cw_api_crm_dialog_to_array((array) $row);
    }

    nocache_headers();
    $res = new WP_REST_Response([
        'dialogs' => $items,
        'limit'   => $limit,
        'offset'  => $offset,
        'count'   => count($items),
    ]);
    $res->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    return $res;
}

function cw_api_crm_messages(WP_REST_Request $r) {
    global $wpdb;

    $dialog_id = max(0, (int) $r['id']);
    if ($dialog_id <= 0) {
        return new WP_REST_Response(['error' => 'invalid_dialog_id'], 400);
    }

    $D = $wpdb->prefix . 'cw_dialogs';
    $M = $wpdb->prefix . 'cw_messages';

    $dialog = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$D} WHERE id = %d", $dialog_id),
        ARRAY_A
    );

    if (!$dialog) {
        return new WP_REST_Response(['error' => 'dialog_not_found'], 404);
    }

    if ($r->get_method() === 'GET') {
        $after_id = max(0, (int) ($r->get_param('after_id') ?: 0));

        if ($after_id > 0) {
            $rows = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$M} WHERE dialog_id = %d AND id > %d ORDER BY id ASC", $dialog_id, $after_id),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$M} WHERE dialog_id = %d ORDER BY id ASC", $dialog_id),
                ARRAY_A
            );
        }

        $items = [];
        foreach ((array) $rows as $row) {
            $items[] = cw_api_crm_message_to_array((array) $row);
        }

        $res = new WP_REST_Response([
            'dialog'  => cw_api_crm_dialog_to_array((array) $dialog),
            'messages' => $items,
        ]);
        $res->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        return $res;
    }

    $data = json_decode((string) $r->get_body(), true);
    if (!is_array($data)) {
        $data = $r->get_params();
    }

    $raw_message = $data['message'] ?? '';
    if (!is_string($raw_message)) {
        $raw_message = '';
    }

    $message = sanitize_text_field(mb_substr(trim($raw_message), 0, 2000));
    if ($message === '') {
        return new WP_REST_Response(['error' => 'empty_message'], 400);
    }

    if ((string) ($dialog['status'] ?? '') === 'closed') {
        return new WP_REST_Response(['error' => 'dialog_closed'], 403);
    }

    if (function_exists('cw_mark_user_messages_read_by_operator')) {
        cw_mark_user_messages_read_by_operator($dialog_id);
    }

    $inserted = $wpdb->insert($M, [
        'dialog_id'   => $dialog_id,
        'message'     => $message,
        'is_operator' => 1,
        'is_bot'      => 0,
        'unread'      => 1,
        'created_at'  => current_time('mysql'),
    ], ['%d', '%s', '%d', '%d', '%d', '%s']);

    if (!$inserted) {
        return new WP_REST_Response(['error' => 'insert_failed'], 500);
    }

    $message_id = (int) $wpdb->insert_id;

    return [
        'status'     => 'ok',
        'message_id' => $message_id,
        'message'    => cw_api_crm_message_to_array(cw_api_get_message_row($message_id)),
    ];
}

function cw_api_crm_close_dialog(WP_REST_Request $r) {
    global $wpdb;

    $dialog_id = max(0, (int) $r['id']);
    if ($dialog_id <= 0) {
        return new WP_REST_Response(['error' => 'invalid_dialog_id'], 400);
    }

    $D = $wpdb->prefix . 'cw_dialogs';
    $M = $wpdb->prefix . 'cw_messages';

    $status = (string) $wpdb->get_var(
        $wpdb->prepare("SELECT status FROM {$D} WHERE id = %d", $dialog_id)
    );

    if ($status === '') {
        return new WP_REST_Response(['error' => 'dialog_not_found'], 404);
    }

    if ($status === 'closed') {
        return ['status' => 'already_closed'];
    }

    if (function_exists('cw_mark_user_messages_read_by_operator')) {
        cw_mark_user_messages_read_by_operator($dialog_id);
    }

    $wpdb->update($D, ['status' => 'closed'], ['id' => $dialog_id], ['%s'], ['%d']);

    $wpdb->insert($M, [
        'dialog_id'   => $dialog_id,
        'message'     => '[system]Диалог закрыт оператором CRM.',
        'is_operator' => 1,
        'is_bot'      => 0,
        'unread'      => 1,
        'created_at'  => current_time('mysql'),
    ], ['%d', '%s', '%d', '%d', '%d', '%s']);

    return ['status' => 'closed'];
}

add_action('rest_api_init', function () {
    register_rest_route('cw/v1', '/crm/ping', [
        'methods'  => 'GET',
        'callback' => 'cw_api_crm_ping',
        'permission_callback' => 'cw_api_crm_permission_callback',
    ]);

    register_rest_route('cw/v1', '/crm/dialogs', [
        'methods'  => 'GET',
        'callback' => 'cw_api_crm_dialogs',
        'permission_callback' => 'cw_api_crm_permission_callback',
    ]);

    register_rest_route('cw/v1', '/crm/dialogs/(?P<id>\d+)/messages', [
        'methods'  => ['GET', 'POST'],
        'callback' => 'cw_api_crm_messages',
        'permission_callback' => 'cw_api_crm_permission_callback',
    ]);

    register_rest_route('cw/v1', '/crm/dialogs/(?P<id>\d+)/close', [
        'methods'  => 'POST',
        'callback' => 'cw_api_crm_close_dialog',
        'permission_callback' => 'cw_api_crm_permission_callback',
    ]);
});
