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

function cw_telegram_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Access denied');
    }

    $saved               = false;
    $generated           = false;
    $webhook_result      = '';
    $webhook_info_result = '';
    $test_result         = '';

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

    if (isset($_POST['cw_tg_set_webhook'])) {
        if ($token !== '') {
            $body = [
                'url' => rest_url('cw/v1/tg-webhook'),
            ];

            if ($secret !== '') {
                $body['secret_token'] = $secret;
            }

            $response = cw_tg_api_request('setWebhook', $body, 'POST');

            if (is_wp_error($response)) {
                $webhook_result = 'Ошибка: ' . cw_tg_render_wp_error($response);
            } else {
                $webhook_result = 'Webhook установлен: ' . esc_html(wp_json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        } else {
            $webhook_result = 'Сначала сохраните Bot Token.';
        }
    }

    if (isset($_POST['cw_tg_check_webhook'])) {
        if ($token !== '') {
            $response = cw_tg_api_request('getWebhookInfo', [], 'GET');

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

    if (isset($_POST['cw_tg_send_test'])) {
        if ($token === '') {
            $test_result = 'Сначала сохраните Bot Token.';
        } elseif ((int) $admin <= 0) {
            $test_result = 'Сначала укажите Admin Chat ID.';
        } else {
            $response = cw_tg_api_request('sendMessage', [
                'chat_id'    => (int) $admin,
                'text'       => '✅ Тестовое сообщение из Chat Widget через Telegram API' . ($proxy_on ? ' (SOCKS5 включён)' : ''),
                'parse_mode' => 'HTML',
            ], 'POST');

            if (is_wp_error($response)) {
                $test_result = 'Ошибка: ' . cw_tg_render_wp_error($response);
            } else {
                $test_result = 'Тестовое сообщение отправлено.';
            }
        }
    }
    ?>

    <div class="wrap">
        <h1>Telegram интеграция</h1>

        <?php if (!function_exists('curl_init')): ?>
            <div class="notice notice-warning">
                <p><strong>Внимание:</strong> расширение cURL в PHP не найдено. SOCKS5 прокси без cURL работать не будет.</p>
            </div>
        <?php endif; ?>

        <?php if ($enabled): ?>
            <div class="notice notice-success"><p><strong>Telegram интеграция включена.</strong></p></div>
        <?php else: ?>
            <div class="notice notice-warning"><p><strong>Telegram интеграция выключена.</strong> Уведомления с сайта и входящие webhook-сообщения сейчас не обрабатываются.</p></div>
        <?php endif; ?>

        <?php if ($generated): ?>
            <div class="updated notice">
                <p><strong>Новый безопасный Webhook Secret сгенерирован.</strong></p>
            </div>
        <?php endif; ?>

        <?php if ($saved): ?>
            <div class="updated notice">
                <p>Настройки сохранены.</p>
            </div>
        <?php endif; ?>

        <?php if ($webhook_result): ?>
            <div class="notice notice-info"><p><?php echo $webhook_result; ?></p></div>
        <?php endif; ?>

        <?php if ($webhook_info_result): ?>
            <div class="notice notice-info"><p><?php echo $webhook_info_result; ?></p></div>
        <?php endif; ?>

        <?php if ($test_result): ?>
            <div class="notice notice-info"><p><?php echo $test_result; ?></p></div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('cw_tg_settings_nonce'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">Включить интеграцию</th>
                    <td>
                        <label>
                            <input type="checkbox" name="cw_tg_enabled" value="1" <?php checked($enabled, 1); ?>>
                            Telegram интеграция активна
                        </label>
                        <p class="description">Если выключить, новые сообщения из сайта не будут отправляться в Telegram, а Telegram webhook не будет обрабатывать входящие команды и ответы.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Bot Token</th>
                    <td>
                        <input type="text" name="cw_tg_token" value="<?php echo esc_attr($token); ?>" class="regular-text" autocomplete="off" />
                    </td>
                </tr>

                <tr>
                    <th scope="row">Admin Chat ID</th>
                    <td>
                        <input type="text" name="cw_tg_admin_chat" value="<?php echo esc_attr($admin); ?>" class="regular-text" autocomplete="off" />
                    </td>
                </tr>

                <tr>
                    <th scope="row">Webhook Secret</th>
                    <td>
                        <input type="text" name="cw_tg_webhook_secret" value="<?php echo esc_attr($secret); ?>" class="regular-text" autocomplete="off" />
                        <br><br>
                        <?php
                        $generate_url = add_query_arg(
                            [
                                'cw_generate_secret' => 1,
                                '_wpnonce'           => wp_create_nonce('cw_tg_settings_nonce'),
                            ],
                            admin_url('admin.php?page=cw_telegram')
                        );
                        ?>
                        <a href="<?php echo esc_url($generate_url); ?>" class="button">🔐 Сгенерировать безопасный секрет</a>
                    </td>
                </tr>

                <tr>
                    <th colspan="2"><hr><h2 style="margin:0;">SOCKS5 прокси</h2></th>
                </tr>

                <tr>
                    <th scope="row">Включить прокси</th>
                    <td>
                        <label>
                            <input type="checkbox" name="cw_tg_proxy_enabled" value="1" <?php checked($proxy_on, 1); ?>>
                            Использовать SOCKS5 для запросов к Telegram
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Host</th>
                    <td>
                        <input type="text" name="cw_tg_proxy_host" value="<?php echo esc_attr($proxy_host); ?>" class="regular-text" placeholder="127.0.0.1" autocomplete="off" />
                    </td>
                </tr>

                <tr>
                    <th scope="row">Port</th>
                    <td>
                        <input type="number" name="cw_tg_proxy_port" value="<?php echo esc_attr($proxy_port); ?>" class="small-text" min="1" max="65535" />
                        <p class="description">Обычно 1080.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Username</th>
                    <td>
                        <input type="text" name="cw_tg_proxy_user" value="<?php echo esc_attr($proxy_user); ?>" class="regular-text" autocomplete="off" />
                    </td>
                </tr>

                <tr>
                    <th scope="row">Password</th>
                    <td>
                        <input type="password" name="cw_tg_proxy_pass" value="<?php echo esc_attr($proxy_pass); ?>" class="regular-text" autocomplete="new-password" />
                    </td>
                </tr>

                <tr>
                    <th scope="row">DNS через прокси</th>
                    <td>
                        <label>
                            <input type="checkbox" name="cw_tg_proxy_rdns" value="1" <?php checked($proxy_rdns, 1); ?>>
                            Использовать socks5h / удалённое DNS-резолвинг через прокси
                        </label>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" name="cw_tg_save" class="button button-primary">Сохранить настройки</button>
                <button type="submit" name="cw_tg_set_webhook" class="button">Установить Webhook</button>
                <button type="submit" name="cw_tg_check_webhook" class="button">Проверить getWebhookInfo</button>
                <button type="submit" name="cw_tg_send_test" class="button">Отправить тестовое сообщение</button>
            </p>
        </form>

        <hr>

        <h2>Webhook URL</h2>
        <code><?php echo esc_html(rest_url('cw/v1/tg-webhook')); ?></code>
    </div>

    <?php
}
