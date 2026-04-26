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

function cw_max_render_settings_alert(string $type, string $title, string $message): void {
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
                "Тестовое сообщение из Chat Widget через MAX.\n\nЕсли вы его видите — интеграция отправки работает.\nКнопки «Ответить», «История диалога», «Закрыть», «Статистика» и «СБП QR» появятся на реальных уведомлениях по диалогам сайта."
            );

            $test_result = $ok ? 'Тестовое сообщение отправлено.' : 'Не удалось отправить тестовое сообщение.';
        }
    }
    ?>
    <div class="wrap cw-settings-page cw-max-settings-page">
        <div class="cw-settings-hero">
            <div>
                <div class="cw-settings-kicker">Chat Widget</div>
                <h1>MAX интеграция</h1>
                <p>Настройка уведомлений, webhook и привязки оператора для связи сайта с MAX.</p>
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
            if ($enabled) {
                cw_max_render_settings_alert(
                    'success',
                    'MAX интеграция включена',
                    'Новые сообщения с сайта могут отправляться в MAX, webhook может обрабатывать входящие сообщения и нажатия на кнопки.'
                );
            } else {
                cw_max_render_settings_alert(
                    'warning',
                    'MAX интеграция выключена',
                    '<strong>Уведомления с сайта и входящие webhook-сообщения сейчас не обрабатываются.</strong>'
                );
            }

            if ($generated) {
                cw_max_render_settings_alert('success', 'Webhook Secret сгенерирован', 'Новый секрет сохранён в настройках.');
            }

            if ($saved) {
                cw_max_render_settings_alert('success', 'Настройки сохранены', 'Изменения применены.');
            }

            if ($webhook_result) {
                $type = (strpos(wp_strip_all_tags($webhook_result), 'Ошибка:') === 0) ? 'error' : 'success';
                cw_max_render_settings_alert($type, 'Результат установки Webhook', $webhook_result);
            }

            if ($delete_result) {
                $type = (strpos(wp_strip_all_tags($delete_result), 'Ошибка:') === 0) ? 'error' : 'info';
                cw_max_render_settings_alert($type, 'Результат удаления Webhook', $delete_result);
            }

            if ($subscriptions_info) {
                $type = (strpos(wp_strip_all_tags($subscriptions_info), 'Ошибка:') === 0) ? 'error' : 'info';
                cw_max_render_settings_alert($type, 'Информация /subscriptions', $subscriptions_info);
            }

            if ($token_check_result) {
                $type = (strpos(wp_strip_all_tags($token_check_result), 'Ошибка:') === 0) ? 'error' : 'success';
                cw_max_render_settings_alert($type, 'Проверка токена', $token_check_result);
            }

            if ($test_result) {
                $type = (strpos(wp_strip_all_tags($test_result), 'Не удалось') === 0 || strpos(wp_strip_all_tags($test_result), 'Сначала') === 0 || strpos(wp_strip_all_tags($test_result), 'Укажите') === 0) ? 'warning' : 'success';
                cw_max_render_settings_alert($type, 'Тестовое сообщение', $test_result);
            }

            if ($bound_user_id > 0) {
                cw_max_render_settings_alert(
                    'success',
                    'Оператор MAX привязан',
                    'Текущий User ID: <code>' . esc_html((string) $bound_user_id) . '</code><br>Этот User ID автоматически записывается после команды <code>/start</code> в чате с ботом.'
                );
            } else {
                cw_max_render_settings_alert(
                    'warning',
                    'Оператор MAX пока не привязан',
                    'Оставьте поле <code>Admin User ID</code> пустым и напишите боту <code>/start</code>.'
                );
            }
            ?>
        </div>

        <form method="post" class="cw-settings-form">
            <?php wp_nonce_field('cw_max_settings_nonce'); ?>

            <div class="cw-settings-grid">
                <section class="cw-settings-card">
                    <div class="cw-settings-card-head">
                        <div class="cw-settings-card-icon">MAX</div>
                        <div>
                            <h2>Основные настройки</h2>
                            <p>Токен бота MAX, User ID оператора и секрет webhook.</p>
                        </div>
                    </div>

                    <div class="cw-settings-field is-checkbox">
                        <label class="cw-settings-check">
                            <input type="checkbox" name="cw_max_enabled" value="1" <?php checked($enabled, 1); ?>>
                            <span>
                                <strong>MAX интеграция активна</strong>
                                <em>Если выключить, новые сообщения с сайта не будут отправляться в MAX, а webhook не будет обрабатывать входящие сообщения и нажатия на кнопки.</em>
                            </span>
                        </label>
                    </div>

                    <div class="cw-settings-field">
                        <label for="cw_max_token">MAX Token</label>
                        <input id="cw_max_token" type="text" name="cw_max_token" value="<?php echo esc_attr($token); ?>" autocomplete="off" />
                        <p>Токен бота MAX.</p>
                    </div>

                    <div class="cw-settings-field">
                        <label for="cw_max_admin_user_id">Admin User ID</label>
                        <input id="cw_max_admin_user_id" type="text" name="cw_max_admin_user_id" value="<?php echo esc_attr($admin_user_id); ?>" autocomplete="off" />
                        <p>Можно оставить пустым. Тогда первый пользователь, который напишет боту <code>/start</code>, будет сохранён как оператор MAX.</p>
                        <?php if ($bound_user_id > 0): ?>
                            <p>Сейчас сохранён User ID: <code><?php echo esc_html((string) $bound_user_id); ?></code></p>
                        <?php endif; ?>
                    </div>

                    <div class="cw-settings-field">
                        <label for="cw_max_webhook_secret">Webhook Secret</label>
                        <input id="cw_max_webhook_secret" type="text" name="cw_max_webhook_secret" value="<?php echo esc_attr($secret); ?>" autocomplete="off" />
                        <?php
                        $generate_url = add_query_arg(
                            [
                                'cw_max_generate_secret' => 1,
                                '_wpnonce'               => wp_create_nonce('cw_max_settings_nonce'),
                            ],
                            admin_url('admin.php?page=cw_max')
                        );
                        ?>
                        <a href="<?php echo esc_url($generate_url); ?>" class="button cw-settings-secondary-link">Сгенерировать секрет</a>
                        <p>Если секрет указан, MAX будет присылать его в заголовке <code>X-Max-Bot-Api-Secret</code>.</p>
                    </div>
                </section>

                <section class="cw-settings-card">
                    <div class="cw-settings-card-head">
                        <div class="cw-settings-card-icon">ID</div>
                        <div>
                            <h2>Привязка оператора</h2>
                            <p>Кто получает уведомления и отвечает из MAX.</p>
                        </div>
                    </div>

                    <div class="cw-settings-field is-checkbox">
                        <div class="cw-settings-check">
                            <span>
                                <?php if ($bound_user_id > 0): ?>
                                    <strong>Оператор привязан</strong>
                                    <em>Текущий User ID: <code><?php echo esc_html((string) $bound_user_id); ?></code></em>
                                <?php else: ?>
                                    <strong>Оператор пока не привязан</strong>
                                    <em>Оставьте Admin User ID пустым и отправьте боту MAX команду <code>/start</code>.</em>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>

                    <div class="cw-settings-field">
                        <label>Как работает привязка</label>
                        <p>После команды <code>/start</code> User ID автоматически сохранится в настройках и будет использоваться для уведомлений.</p>
                        <p>Кнопки <strong>Ответить</strong>, <strong>История диалога</strong>, <strong>Закрыть</strong>, <strong>Статистика</strong> и <strong>СБП QR</strong> появляются в реальных уведомлениях о новых сообщениях с сайта.</p>
                    </div>

                    <div class="cw-settings-field">
                        <label>События webhook</label>
                        <p>Для кнопок из <code>max.php</code> webhook должен быть установлен с типами событий: <code>message_created</code>, <code>message_callback</code>, <code>bot_started</code>.</p>
                    </div>
                </section>
            </div>

            <section class="cw-settings-card cw-settings-webhook-card">
                <div class="cw-settings-card-head">
                    <div class="cw-settings-card-icon">URL</div>
                    <div>
                        <h2>Webhook URL</h2>
                        <p>Адрес, который используется для входящих сообщений от MAX.</p>
                    </div>
                </div>
                <code class="cw-settings-code"><?php echo esc_html($webhook_url); ?></code>
            </section>

            <div class="cw-settings-actions">
                <button type="submit" name="cw_max_save" class="button button-primary">Сохранить настройки</button>
                <button type="submit" name="cw_max_check_token" class="button">Проверить токен (/me)</button>
                <button type="submit" name="cw_max_set_webhook" class="button">Установить Webhook</button>
                <button type="submit" name="cw_max_check_subscriptions" class="button">Проверить /subscriptions</button>
                <button type="submit" name="cw_max_send_test" class="button">Отправить тест</button>
                <button type="submit" name="cw_max_delete_webhook" class="button" onclick="return confirm('Удалить webhook MAX для этого сайта?');">Удалить Webhook</button>
            </div>
        </form>
    </div>

    <?php
}