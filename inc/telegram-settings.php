<?php
if (!defined('ABSPATH')) exit;

function cw_tg_settings_collect_post(): array {
    $enabled = !empty($_POST['cw_tg_enabled']) ? 1 : 0;
    $token   = sanitize_text_field(wp_unslash($_POST['cw_tg_token'] ?? ''));
    $admin   = sanitize_text_field(wp_unslash($_POST['cw_tg_admin_chat'] ?? ''));
    $secret  = sanitize_text_field(wp_unslash($_POST['cw_tg_webhook_secret'] ?? ''));

    $proxy_on   = !empty($_POST['cw_tg_proxy_enabled']) ? 1 : 0;
    $proxy_host = sanitize_text_field(wp_unslash($_POST['cw_tg_proxy_host'] ?? ''));
    $proxy_port = sanitize_text_field(wp_unslash($_POST['cw_tg_proxy_port'] ?? '1080'));
    $proxy_user = sanitize_text_field(wp_unslash($_POST['cw_tg_proxy_user'] ?? ''));
    $proxy_pass = isset($_POST['cw_tg_proxy_pass']) ? (string) wp_unslash($_POST['cw_tg_proxy_pass']) : '';
    $proxy_rdns = !empty($_POST['cw_tg_proxy_rdns']) ? 1 : 0;

    $proxy_port_num = intval($proxy_port);
    if ($proxy_port_num <= 0) {
        $proxy_port_num = 1080;
    }

    return [
        'enabled'    => $enabled,
        'token'      => $token,
        'admin'      => $admin,
        'secret'     => $secret,
        'proxy_on'   => $proxy_on,
        'proxy_host' => $proxy_host,
        'proxy_port' => $proxy_port_num,
        'proxy_user' => $proxy_user,
        'proxy_pass' => $proxy_pass,
        'proxy_rdns' => $proxy_rdns,
    ];
}

function cw_tg_settings_save(array $data): void {
    update_option('cw_tg_enabled', $data['enabled']);
    update_option('cw_tg_token', $data['token']);
    update_option('cw_tg_admin_chat', $data['admin']);
    update_option('cw_tg_webhook_secret', $data['secret']);
    delete_option('cw_tg_webhook_ip');

    update_option('cw_tg_proxy_enabled', $data['proxy_on']);
    update_option('cw_tg_proxy_host', $data['proxy_host']);
    update_option('cw_tg_proxy_port', $data['proxy_port']);
    update_option('cw_tg_proxy_user', $data['proxy_user']);
    update_option('cw_tg_proxy_pass', $data['proxy_pass']);
    update_option('cw_tg_proxy_rdns', $data['proxy_rdns']);
}

function cw_tg_render_wp_error($error): string {
    if (!is_wp_error($error)) {
        return '';
    }

    $msg  = esc_html($error->get_error_message());
    $data = $error->get_error_data();

    if (is_array($data) && !empty($data['body'])) {
        $msg .= '<br><code style="white-space:pre-wrap;word-break:break-word;display:block;max-width:100%;">' . esc_html((string) $data['body']) . '</code>';
    }

    return $msg;
}

function cw_tg_render_settings_alert(string $type, string $title, string $message): void {
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
        'span'   => [
            'class' => [],
        ],
        'code'   => [
            'class' => [],
            'style' => [],
        ],
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

function cw_telegram_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Access denied');
    }

    $saved               = false;
    $generated           = false;
    $webhook_result      = '';
    $webhook_delete_result = '';
    $webhook_info_result = '';
    $test_result         = '';
    $queue_result        = '';

    $enabled    = (int) get_option('cw_tg_enabled', 1);
    $token      = (string) get_option('cw_tg_token', '');
    $admin      = (string) get_option('cw_tg_admin_chat', '');
    $secret     = (string) get_option('cw_tg_webhook_secret', '');
    $proxy_on   = (int) get_option('cw_tg_proxy_enabled', 0);
    $proxy_host = (string) get_option('cw_tg_proxy_host', '');
    $proxy_port = (string) get_option('cw_tg_proxy_port', '1080');
    $proxy_user = (string) get_option('cw_tg_proxy_user', '');
    $proxy_pass = (string) get_option('cw_tg_proxy_pass', '');
    $proxy_rdns = (int) get_option('cw_tg_proxy_rdns', 1);

    if (isset($_GET['cw_generate_secret'])) {
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'cw_tg_settings_nonce')) {
            wp_die('Nonce verification failed');
        }

        try {
            $secret = bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            $secret = wp_generate_password(64, false, false);
        }

        update_option('cw_tg_webhook_secret', $secret);
        $generated = true;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        check_admin_referer('cw_tg_settings_nonce');

        $posted = cw_tg_settings_collect_post();
        cw_tg_settings_save($posted);

        $enabled    = $posted['enabled'];
        $token      = $posted['token'];
        $admin      = $posted['admin'];
        $secret     = $posted['secret'];
        $proxy_on   = $posted['proxy_on'];
        $proxy_host = $posted['proxy_host'];
        $proxy_port = (string) $posted['proxy_port'];
        $proxy_user = $posted['proxy_user'];
        $proxy_pass = $posted['proxy_pass'];
        $proxy_rdns = $posted['proxy_rdns'];

        if (isset($_POST['cw_tg_save'])) {
            $saved = true;
        }
    }

    if (isset($_POST['cw_tg_delete_webhook'])) {
        if ($token !== '') {
            $response = cw_tg_api_request('deleteWebhook', ['drop_pending_updates' => 'true'], 'POST', 45, 25);

            if (is_wp_error($response)) {
                $webhook_delete_result = 'Ошибка: ' . cw_tg_render_wp_error($response);
            } else {
                $webhook_delete_result = 'Webhook отключён: ' . esc_html(wp_json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        } else {
            $webhook_delete_result = 'Сначала сохраните Bot Token.';
        }
    }

    if (isset($_POST['cw_tg_set_webhook'])) {
        if ($token !== '') {
            if (function_exists('cw_tg_is_valid_webhook_secret') && !cw_tg_is_valid_webhook_secret((string) $secret)) {
                $webhook_result = 'Ошибка: Webhook Secret может содержать только A-Z, a-z, 0-9, подчёркивание и дефис, длина 1-256 символов.';
            } else {
                $body = [
                    'url'                  => cw_tg_direct_webhook_url(),
                    'max_connections'      => 10,
                    'allowed_updates'      => wp_json_encode(['message', 'callback_query']),
                    'drop_pending_updates' => 'true',
                ];


                if ($secret !== '') {
                    $body['secret_token'] = $secret;
                }

                $response = cw_tg_api_request('setWebhook', $body, 'POST', 45, 25);

                if (is_wp_error($response)) {
                    $webhook_result = 'Ошибка: ' . cw_tg_render_wp_error($response);
                } else {
                    $webhook_result = 'Webhook подключён: ' . esc_html(wp_json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
            }
        } else {
            $webhook_result = 'Сначала сохраните Bot Token.';
        }
    }

    if (isset($_POST['cw_tg_check_webhook'])) {
        if ($token !== '') {
            $response = cw_tg_api_request('getWebhookInfo', [], 'GET', 45, 25);

            if (is_wp_error($response)) {
                $webhook_info_result = 'Ошибка: ' . cw_tg_render_wp_error($response);
            } else {
                $info = $response['result'] ?? [];

                $webhook_info_result  = '<strong>Webhook Info:</strong><br>';
                $webhook_info_result .= 'URL: ' . esc_html($info['url'] ?? '-') . '<br>';
                $webhook_info_result .= 'Pending Updates: ' . esc_html((string) ($info['pending_update_count'] ?? '0')) . '<br>';
                $webhook_info_result .= 'IP Address: ' . esc_html($info['ip_address'] ?? '-') . '<br>';
                $webhook_info_result .= 'Last Error Date: ' . (!empty($info['last_error_date']) ? esc_html(date_i18n('Y-m-d H:i:s', intval($info['last_error_date']))) : '-') . '<br>';
                $webhook_info_result .= 'Last Error Message: <span style="color:red;">' . esc_html($info['last_error_message'] ?? '-') . '</span><br>';
                $webhook_info_result .= 'Max Connections: ' . esc_html((string) ($info['max_connections'] ?? '-'));
            }
        } else {
            $webhook_info_result = 'Сначала сохраните Bot Token.';
        }
    }

    if (isset($_POST['cw_tg_process_queue'])) {
        if (function_exists('cw_tg_process_file_queue')) {
            $result = cw_tg_process_file_queue(50);
            $queue_result = 'Очередь обработана: ' . esc_html(wp_json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $queue_result = 'Ошибка: обработчик очереди недоступен.';
        }
    }

    if (isset($_POST['cw_tg_send_test'])) {
        if ($token === '') {
            $test_result = 'Сначала сохраните Bot Token.';
        } elseif ((int) $admin === 0) {
            $test_result = 'Сначала укажите Admin Chat ID.';
        } else {
            $response = cw_tg_api_request('sendMessage', [
                'chat_id'    => (int) $admin,
                'text'       => '✅ Тестовое сообщение из Chat Widget через Telegram API' . ($proxy_on ? ' (SOCKS5 включён)' : ''),
                'parse_mode' => 'HTML',
            ], 'POST', 45, 25);

            if (is_wp_error($response)) {
                $test_result = 'Ошибка: ' . cw_tg_render_wp_error($response);
            } else {
                $test_result = 'Тестовое сообщение отправлено.';
            }
        }
    }
    ?>

    <div class="wrap cw-settings-page cw-telegram-settings-page">
        <div class="cw-settings-hero">
            <div>
                <div class="cw-settings-kicker">Chat Widget</div>
                <h1>Telegram интеграция</h1>
                <p>Настройка уведомлений, webhook и SOCKS5 прокси для связи сайта с Telegram.</p>
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
                <span><?php echo $enabled ? 'Интеграция включена' : 'Интеграция выключена'; ?></span>
            </div>
        </div>

        <div class="cw-settings-notices" aria-live="polite">
            <?php
            if (!function_exists('curl_init')) {
                cw_tg_render_settings_alert(
                    'warning',
                    'cURL не найден',
                    '<strong>SOCKS5 прокси без cURL работать не будет.</strong> Включите расширение cURL в PHP на сервере.'
                );
            }

            if ($enabled) {
                cw_tg_render_settings_alert(
                    'success',
                    'Telegram интеграция включена',
                    'Новые сообщения с сайта могут отправляться в Telegram, webhook может обрабатывать входящие команды и ответы.'
                );
            } else {
                cw_tg_render_settings_alert(
                    'warning',
                    'Telegram интеграция выключена',
                    '<strong>Уведомления с сайта и входящие webhook-сообщения сейчас не обрабатываются.</strong>'
                );
            }

            if ($generated) {
                cw_tg_render_settings_alert('success', 'Webhook Secret сгенерирован', 'Новый безопасный секрет сохранён в настройках.');
            }

            if ($saved) {
                cw_tg_render_settings_alert('success', 'Настройки сохранены', 'Изменения применены.');
            }

            if ($webhook_delete_result) {
                $type = (strpos(wp_strip_all_tags($webhook_delete_result), 'Ошибка:') === 0) ? 'error' : 'success';
                cw_tg_render_settings_alert($type, 'Результат отключения Webhook', $webhook_delete_result);
            }

            if ($webhook_result) {
                $type = (strpos(wp_strip_all_tags($webhook_result), 'Ошибка:') === 0) ? 'error' : 'success';
                cw_tg_render_settings_alert($type, 'Результат установки Webhook', $webhook_result);
            }

            if ($webhook_info_result) {
                cw_tg_render_settings_alert('info', 'Информация Webhook', $webhook_info_result);
            }

            if ($queue_result) {
                $type = (strpos(wp_strip_all_tags($queue_result), 'Ошибка:') === 0) ? 'error' : 'info';
                cw_tg_render_settings_alert($type, 'Очередь Telegram', $queue_result);
            }

            if ($test_result) {
                $type = (strpos(wp_strip_all_tags($test_result), 'Ошибка:') === 0) ? 'error' : 'info';
                cw_tg_render_settings_alert($type, 'Тестовое сообщение', $test_result);
            }
            ?>
        </div>

        <form method="post" class="cw-settings-form">
            <?php wp_nonce_field('cw_tg_settings_nonce'); ?>

            <div class="cw-settings-grid">
                <section class="cw-settings-card">
                    <div class="cw-settings-card-head">
                        <div class="cw-settings-card-icon">TG</div>
                        <div>
                            <h2>Основные настройки</h2>
                            <p>Токен бота, chat ID администратора и секрет webhook.</p>
                        </div>
                    </div>

                    <div class="cw-settings-field is-checkbox">
                        <label class="cw-settings-check">
                            <input type="checkbox" name="cw_tg_enabled" value="1" <?php checked($enabled, 1); ?>>
                            <span>
                                <strong>Telegram интеграция активна</strong>
                                <em>Если выключить, новые сообщения с сайта не будут отправляться в Telegram, а webhook не будет обрабатывать входящие команды и ответы.</em>
                            </span>
                        </label>
                    </div>

                    <div class="cw-settings-field">
                        <label for="cw_tg_token">Bot Token</label>
                        <input id="cw_tg_token" type="text" name="cw_tg_token" value="<?php echo esc_attr($token); ?>" autocomplete="off" />
                    </div>

                    <div class="cw-settings-field">
                        <label for="cw_tg_admin_chat">Admin Chat ID</label>
                        <input id="cw_tg_admin_chat" type="text" name="cw_tg_admin_chat" value="<?php echo esc_attr($admin); ?>" autocomplete="off" />
                    </div>

                    <div class="cw-settings-field">
                        <label for="cw_tg_webhook_secret">Webhook Secret</label>
                        <input id="cw_tg_webhook_secret" type="text" name="cw_tg_webhook_secret" value="<?php echo esc_attr($secret); ?>" autocomplete="off" />
                        <?php
                        $generate_url = add_query_arg(
                            [
                                'cw_generate_secret' => 1,
                                '_wpnonce'           => wp_create_nonce('cw_tg_settings_nonce'),
                            ],
                            admin_url('admin.php?page=cw_telegram')
                        );
                        ?>
                        <a href="<?php echo esc_url($generate_url); ?>" class="button cw-settings-secondary-link">Сгенерировать безопасный секрет</a>
                    </div>
                </section>

                <section class="cw-settings-card">
                    <div class="cw-settings-card-head">
                        <div class="cw-settings-card-icon">S5</div>
                        <div>
                            <h2>SOCKS5 прокси</h2>
                            <p>Прокси для запросов WordPress к Telegram API.</p>
                        </div>
                    </div>

                    <div class="cw-settings-field is-checkbox">
                        <label class="cw-settings-check">
                            <input type="checkbox" name="cw_tg_proxy_enabled" value="1" <?php checked($proxy_on, 1); ?>>
                            <span>
                                <strong>Использовать SOCKS5 для запросов к Telegram</strong>
                            </span>
                        </label>
                    </div>

                    <div class="cw-settings-two-columns">
                        <div class="cw-settings-field">
                            <label for="cw_tg_proxy_host">Host</label>
                            <input id="cw_tg_proxy_host" type="text" name="cw_tg_proxy_host" value="<?php echo esc_attr($proxy_host); ?>" placeholder="127.0.0.1" autocomplete="off" />
                        </div>

                        <div class="cw-settings-field">
                            <label for="cw_tg_proxy_port">Port</label>
                            <input id="cw_tg_proxy_port" type="number" name="cw_tg_proxy_port" value="<?php echo esc_attr($proxy_port); ?>" min="1" max="65535" />
                            <p>Обычно 1080.</p>
                        </div>
                    </div>

                    <div class="cw-settings-two-columns">
                        <div class="cw-settings-field">
                            <label for="cw_tg_proxy_user">Username</label>
                            <input id="cw_tg_proxy_user" type="text" name="cw_tg_proxy_user" value="<?php echo esc_attr($proxy_user); ?>" autocomplete="off" />
                        </div>

                        <div class="cw-settings-field">
                            <label for="cw_tg_proxy_pass">Password</label>
                            <input id="cw_tg_proxy_pass" type="password" name="cw_tg_proxy_pass" value="<?php echo esc_attr($proxy_pass); ?>" autocomplete="new-password" />
                        </div>
                    </div>

                    <div class="cw-settings-field is-checkbox">
                        <label class="cw-settings-check">
                            <input type="checkbox" name="cw_tg_proxy_rdns" value="1" <?php checked($proxy_rdns, 1); ?>>
                            <span>
                                <strong>DNS через прокси</strong>
                                <em>Использовать socks5h / удалённое DNS-резолвинг через прокси.</em>
                            </span>
                        </label>
                    </div>
                </section>
            </div>

            <section class="cw-settings-card cw-settings-webhook-card">
                <div class="cw-settings-card-head">
                    <div class="cw-settings-card-icon">URL</div>
                    <div>
                        <h2>Webhook URL</h2>
                        <p>Адрес, который используется для входящих сообщений от Telegram.</p>
                    </div>
                </div>
                <code class="cw-settings-code"><?php echo esc_html(cw_tg_direct_webhook_url()); ?></code>

            </section>

            <div class="cw-settings-actions">
                <button type="submit" name="cw_tg_save" class="button button-primary">Сохранить настройки</button>
                <button type="submit" name="cw_tg_set_webhook" class="button button-primary">Подключить Webhook</button>
                <button type="submit" name="cw_tg_delete_webhook" class="button cw-settings-danger-button">Отключить Webhook</button>
                <button type="submit" name="cw_tg_check_webhook" class="button">Проверить getWebhookInfo</button>
                <button type="submit" name="cw_tg_process_queue" class="button">Обработать очередь TG</button>
                <button type="submit" name="cw_tg_send_test" class="button">Отправить тестовое сообщение</button>
            </div>
        </form>
    </div>

    <?php
}
