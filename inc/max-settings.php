<?php
if (!defined('ABSPATH')) exit;

function cw_max_render_wp_error($error): string {
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

function cw_max_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Access denied');
    }

    $saved              = false;
    $generated          = false;
    $webhook_result     = '';
    $delete_result      = '';
    $subscriptions_info = '';
    $token_check_result = '';
    $test_result        = '';

    $enabled       = (int) get_option('cw_max_enabled', 1);
    $token         = (string) get_option('cw_max_token', '');
    $admin_user_id = (string) get_option('cw_max_admin_user_id', '');
    $secret        = (string) get_option('cw_max_webhook_secret', '');

    if (isset($_GET['cw_max_generate_secret'])) {
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'cw_max_settings_nonce')) {
            wp_die('Nonce verification failed');
        }

        try {
            $secret = bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            $secret = wp_generate_password(64, false, false);
        }

        update_option('cw_max_webhook_secret', $secret);
        $generated = true;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        check_admin_referer('cw_max_settings_nonce');

        $enabled       = !empty($_POST['cw_max_enabled']) ? 1 : 0;
        $token         = trim((string) wp_unslash($_POST['cw_max_token'] ?? ''));
        $admin_user_id = trim((string) wp_unslash($_POST['cw_max_admin_user_id'] ?? ''));
        $secret        = trim((string) wp_unslash($_POST['cw_max_webhook_secret'] ?? ''));

        update_option('cw_max_enabled', $enabled);
        update_option('cw_max_token', $token);
        update_option('cw_max_admin_user_id', absint($admin_user_id));
        update_option('cw_max_webhook_secret', $secret);

        if (isset($_POST['cw_max_save'])) {
            $saved = true;
        }
    }

    $bound_user_id = (int) get_option('cw_max_admin_user_id', 0);
    $webhook_url   = rest_url('cw/v1/max-webhook');

    if (isset($_POST['cw_max_check_token'])) {
        $response = cw_max_api_request('/me', [], 'GET');

        if (is_wp_error($response)) {
            $token_check_result = 'Ошибка: ' . cw_max_render_wp_error($response);
        } else {
            $token_check_result = 'Токен валиден: <code style="white-space:pre-wrap;word-break:break-word;">' .
                esc_html(wp_json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) .
                '</code>';
        }
    }

    if (isset($_POST['cw_max_set_webhook'])) {
        if ($token === '') {
            $webhook_result = 'Сначала сохраните MAX Token.';
        } else {
            $payload = [
                'url'          => $webhook_url,
                'update_types' => ['message_created', 'message_callback', 'bot_started'],
            ];

            if ($secret !== '') {
                $payload['secret'] = $secret;
            }

            $response = cw_max_api_request('/subscriptions', $payload, 'POST');

            if (is_wp_error($response)) {
                $webhook_result = 'Ошибка: ' . cw_max_render_wp_error($response);
            } else {
                $webhook_result = 'Webhook установлен: <code style="white-space:pre-wrap;word-break:break-word;">' .
                    esc_html(wp_json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) .
                    '</code>';
            }
        }
    }

    if (isset($_POST['cw_max_delete_webhook'])) {
        if ($token === '') {
            $delete_result = 'Сначала сохраните MAX Token.';
        } else {
            $response = cw_max_api_request('/subscriptions', ['url' => $webhook_url], 'DELETE');

            if (is_wp_error($response)) {
                $delete_result = 'Ошибка: ' . cw_max_render_wp_error($response);
            } else {
                $delete_result = 'Webhook удалён: <code style="white-space:pre-wrap;word-break:break-word;">' .
                    esc_html(wp_json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) .
                    '</code>';
            }
        }
    }

    if (isset($_POST['cw_max_check_subscriptions'])) {
        if ($token === '') {
            $subscriptions_info = 'Сначала сохраните MAX Token.';
        } else {
            $response = cw_max_api_request('/subscriptions', [], 'GET');

            if (is_wp_error($response)) {
                $subscriptions_info = 'Ошибка: ' . cw_max_render_wp_error($response);
            } else {
                $subscriptions_info = '<code style="white-space:pre-wrap;word-break:break-word;display:block;max-width:100%;">' .
                    esc_html(wp_json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) .
                    '</code>';
            }
        }
    }

    if (isset($_POST['cw_max_send_test'])) {
        $admin_user_id_int = (int) $admin_user_id;

        if ($token === '') {
            $test_result = 'Сначала сохраните MAX Token.';
        } elseif ($admin_user_id_int <= 0) {
            $test_result = 'Укажите Admin User ID или сначала напишите боту /start из MAX для автопривязки.';
        } else {
            $ok = cw_max_send_to_user(
                $admin_user_id_int,
                "Тестовое сообщение из Chat Widget через MAX.\n\nЕсли вы его видите — интеграция отправки работает.\nКнопки появятся на реальных уведомлениях по диалогам сайта."
            );

            $test_result = $ok ? 'Тестовое сообщение отправлено.' : 'Не удалось отправить тестовое сообщение.';
        }
    }
    ?>
    <div class="wrap">
        <h1>MAX интеграция</h1>

        <?php if ($enabled): ?>
            <div class="notice notice-success"><p><strong>MAX интеграция включена.</strong></p></div>
        <?php else: ?>
            <div class="notice notice-warning"><p><strong>MAX интеграция выключена.</strong> Уведомления с сайта и входящие webhook-сообщения сейчас не обрабатываются.</p></div>
        <?php endif; ?>

        <?php if ($generated): ?>
            <div class="updated notice"><p><strong>Новый секрет для webhook сгенерирован.</strong></p></div>
        <?php endif; ?>

        <?php if ($saved): ?>
            <div class="updated notice"><p>Настройки сохранены.</p></div>
        <?php endif; ?>

        <?php if ($webhook_result): ?>
            <div class="notice notice-info"><p><?php echo $webhook_result; ?></p></div>
        <?php endif; ?>

        <?php if ($delete_result): ?>
            <div class="notice notice-info"><p><?php echo $delete_result; ?></p></div>
        <?php endif; ?>

        <?php if ($subscriptions_info): ?>
            <div class="notice notice-info"><p><?php echo $subscriptions_info; ?></p></div>
        <?php endif; ?>

        <?php if ($token_check_result): ?>
            <div class="notice notice-info"><p><?php echo $token_check_result; ?></p></div>
        <?php endif; ?>

        <?php if ($test_result): ?>
            <div class="notice notice-info"><p><?php echo $test_result; ?></p></div>
        <?php endif; ?>

        <?php if ($bound_user_id > 0): ?>
            <div class="notice notice-success">
                <p>
                    <strong>Текущий привязанный оператор MAX:</strong>
                    <code><?php echo esc_html((string) $bound_user_id); ?></code>
                </p>
                <p>Этот User ID автоматически записывается после команды <code>/start</code> в чате с ботом.</p>
            </div>
        <?php else: ?>
            <div class="notice notice-warning">
                <p><strong>Оператор MAX пока не привязан.</strong></p>
                <p>Оставьте поле <code>Admin User ID</code> пустым и напишите боту <code>/start</code>.</p>
            </div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('cw_max_settings_nonce'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">Включить интеграцию</th>
                    <td>
                        <label>
                            <input type="checkbox" name="cw_max_enabled" value="1" <?php checked($enabled, 1); ?>>
                            MAX интеграция активна
                        </label>
                        <p class="description">Если выключить, новые сообщения из сайта не будут отправляться в MAX, а MAX webhook не будет обрабатывать входящие сообщения и нажатия на кнопки.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">MAX Token</th>
                    <td>
                        <input type="text" name="cw_max_token" value="<?php echo esc_attr($token); ?>" class="regular-text" autocomplete="off" />
                        <p class="description">Токен бота MAX.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Admin User ID</th>
                    <td>
                        <input type="text" name="cw_max_admin_user_id" value="<?php echo esc_attr($admin_user_id); ?>" class="regular-text" autocomplete="off" />
                        <p class="description">Можно оставить пустым. Тогда первый пользователь, который напишет боту <code>/start</code>, будет сохранён как оператор MAX.</p>
                        <?php if ($bound_user_id > 0): ?>
                            <p class="description">Сейчас сохранён User ID: <code><?php echo esc_html((string) $bound_user_id); ?></code></p>
                        <?php endif; ?>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Webhook Secret</th>
                    <td>
                        <input type="text" name="cw_max_webhook_secret" value="<?php echo esc_attr($secret); ?>" class="regular-text" autocomplete="off" />
                        <br><br>
                        <?php
                        $generate_url = add_query_arg(
                            [
                                'cw_max_generate_secret' => 1,
                                '_wpnonce'               => wp_create_nonce('cw_max_settings_nonce'),
                            ],
                            admin_url('admin.php?page=cw_max')
                        );
                        ?>
                        <a href="<?php echo esc_url($generate_url); ?>" class="button">Сгенерировать секрет</a>
                        <p class="description">Если секрет указан, MAX будет присылать его в заголовке <code>X-Max-Bot-Api-Secret</code>.</p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" name="cw_max_save" class="button button-primary">Сохранить настройки</button>
                <button type="submit" name="cw_max_check_token" class="button">Проверить токен (/me)</button>
                <button type="submit" name="cw_max_set_webhook" class="button">Установить Webhook</button>
                <button type="submit" name="cw_max_check_subscriptions" class="button">Проверить /subscriptions</button>
                <button type="submit" name="cw_max_send_test" class="button">Отправить тест</button>
                <button type="submit" name="cw_max_delete_webhook" class="button" onclick="return confirm('Удалить webhook MAX для этого сайта?');">Удалить Webhook</button>
            </p>
        </form>

        <hr>

        <h2>Webhook URL</h2>
        <code><?php echo esc_html($webhook_url); ?></code>

        <p style="margin-top:12px;">
            Для кнопок из <code>max.php</code> webhook должен быть установлен с типами событий:
            <code>message_created</code>, <code>message_callback</code>, <code>bot_started</code>.
        </p>

        <p>После привязки оператор может использовать в MAX:</p>
        <pre style="background:#fff;padding:12px;border:1px solid #ddd;">/start
/dialogs
/reply 123 Ваш ответ
/close 123
/cancel</pre>

        <p>Кнопки <strong>Ответить</strong>, <strong>Закрыть</strong> и <strong>СБП QR</strong> появятся на реальных уведомлениях о новых сообщениях из сайта.</p>
    </div>
    <?php
}
