<?php
if (!defined('ABSPATH')) exit;

function cw_cmd_render_settings_alert(string $type, string $title, string $message): void {
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
        'code'   => [],
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

function cw_commands_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Access denied');
    }

    $saved = false;

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        check_admin_referer('cw_commands_settings_nonce');

        update_option('cw_cmd_office_enabled', !empty($_POST['cw_cmd_office_enabled']) ? 1 : 0);
        update_option('cw_cmd_login_enabled', !empty($_POST['cw_cmd_login_enabled']) ? 1 : 0);

        update_option(
            'cw_cmd_office_label',
            sanitize_text_field(wp_unslash($_POST['cw_cmd_office_label'] ?? '/офис'))
        );

        update_option(
            'cw_cmd_login_label',
            sanitize_text_field(wp_unslash($_POST['cw_cmd_login_label'] ?? '/вход'))
        );

        update_option(
            'cw_cmd_office_description',
            sanitize_textarea_field(wp_unslash($_POST['cw_cmd_office_description'] ?? ''))
        );

        update_option(
            'cw_cmd_login_description',
            sanitize_textarea_field(wp_unslash($_POST['cw_cmd_login_description'] ?? ''))
        );

        $saved = true;
    }

    $office_enabled     = (int) get_option('cw_cmd_office_enabled', 1);
    $login_enabled      = (int) get_option('cw_cmd_login_enabled', 1);
    $office_label       = (string) get_option('cw_cmd_office_label', '/офис');
    $login_label        = (string) get_option('cw_cmd_login_label', '/вход');
    $office_description = (string) get_option('cw_cmd_office_description', 'Запросить временный код для подключения сотрудника к текущему диалогу.');
    $login_description  = (string) get_option('cw_cmd_login_description', 'Подключение к диалогу по временному коду, например: /вход 123456');

    $commands_active = ($office_enabled || $login_enabled);
    ?>
    <div class="wrap cw-settings-page cw-commands-settings-page">
        <div class="cw-settings-hero">
            <div>
                <div class="cw-settings-kicker">Chat Widget</div>
                <h1>Команды</h1>
                <p>Настройка служебных команд для подключения сотрудников к диалогам.</p>
            </div>
            <div class="cw-settings-status <?php echo $commands_active ? 'is-active' : 'is-inactive'; ?>">
                <span class="cw-settings-status-icon" aria-hidden="true">
                    <?php if ($commands_active): ?>
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
                <span><?php echo $commands_active ? 'Команды активны' : 'Команды выключены'; ?></span>
            </div>
        </div>

        <div class="cw-settings-notices" aria-live="polite">
            <?php
            if ($commands_active) {
                cw_cmd_render_settings_alert(
                    'success',
                    'Команды включены',
                    'Активные команды доступны операторам и сотрудникам: <code>' . esc_html($office_label) . '</code> и <code>' . esc_html($login_label) . '</code>.'
                );
            } else {
                cw_cmd_render_settings_alert(
                    'warning',
                    'Команды выключены',
                    'Обе служебные команды отключены. Подключение сотрудника по временному коду работать не будет.'
                );
            }

            if ($saved) {
                cw_cmd_render_settings_alert('success', 'Настройки сохранены', 'Изменения применены.');
            }
            ?>
        </div>

        <form method="post" class="cw-settings-form">
            <?php wp_nonce_field('cw_commands_settings_nonce'); ?>

            <div class="cw-settings-grid">
                <section class="cw-settings-card cw-command-card">
                    <div class="cw-settings-card-head">
                        <div class="cw-settings-card-icon">OF</div>
                        <div>
                            <h2>Команда <?php echo esc_html($office_label !== '' ? $office_label : '/офис'); ?></h2>
                            <p>Запрос временного кода для подключения сотрудника к текущему диалогу.</p>
                        </div>
                    </div>

                    <div class="cw-settings-field is-checkbox">
                        <label class="cw-settings-check">
                            <input type="checkbox" name="cw_cmd_office_enabled" value="1" <?php checked($office_enabled, 1); ?>>
                            <span>
                                <strong>Активировать команду</strong>
                                <em>Если выключить, команда не будет показываться и обрабатываться.</em>
                            </span>
                        </label>
                    </div>

                    <div class="cw-settings-field">
                        <label for="cw_cmd_office_label">Название команды</label>
                        <input id="cw_cmd_office_label" type="text" name="cw_cmd_office_label" value="<?php echo esc_attr($office_label); ?>" autocomplete="off" />
                        <p>Например: <code>/офис</code>.</p>
                    </div>

                    <div class="cw-settings-field">
                        <label for="cw_cmd_office_description">Описание</label>
                        <textarea id="cw_cmd_office_description" name="cw_cmd_office_description" rows="3" class="cw-command-description"><?php echo esc_textarea($office_description); ?></textarea>
                        <p>Краткое пояснение назначения команды.</p>
                    </div>
                </section>

                <section class="cw-settings-card cw-command-card">
                    <div class="cw-settings-card-head">
                        <div class="cw-settings-card-icon">IN</div>
                        <div>
                            <h2>Команда <?php echo esc_html($login_label !== '' ? $login_label : '/вход'); ?></h2>
                            <p>Подключение сотрудника к диалогу по временному коду.</p>
                        </div>
                    </div>

                    <div class="cw-settings-field is-checkbox">
                        <label class="cw-settings-check">
                            <input type="checkbox" name="cw_cmd_login_enabled" value="1" <?php checked($login_enabled, 1); ?>>
                            <span>
                                <strong>Активировать команду</strong>
                                <em>Если выключить, подключение по временному коду будет недоступно.</em>
                            </span>
                        </label>
                    </div>

                    <div class="cw-settings-field">
                        <label for="cw_cmd_login_label">Название команды</label>
                        <input id="cw_cmd_login_label" type="text" name="cw_cmd_login_label" value="<?php echo esc_attr($login_label); ?>" autocomplete="off" />
                        <p>Например: <code>/вход</code>.</p>
                    </div>

                    <div class="cw-settings-field">
                        <label for="cw_cmd_login_description">Описание</label>
                        <textarea id="cw_cmd_login_description" name="cw_cmd_login_description" rows="3" class="cw-command-description"><?php echo esc_textarea($login_description); ?></textarea>
                        <p>Можно указать пример использования: <code><?php echo esc_html($login_label !== '' ? $login_label : '/вход'); ?> 123456</code>.</p>
                    </div>
                </section>

                <section class="cw-settings-card cw-settings-card-full cw-command-info-card">
                    <div class="cw-settings-card-head">
                        <div class="cw-settings-card-icon">?</div>
                        <div>
                            <h2>Как это работает</h2>
                            <p>Команды помогают сотруднику подключиться к нужному диалогу без ручного поиска.</p>
                        </div>
                    </div>

                    <div class="cw-settings-help-list">
                        <p><strong><code><?php echo esc_html($office_label !== '' ? $office_label : '/офис'); ?></code></strong> — запрашивает временный код для текущего диалога.</p>
                        <p><strong><code><?php echo esc_html($login_label !== '' ? $login_label : '/вход'); ?> 123456</code></strong> — подключает сотрудника к диалогу по временному коду.</p>
                        <p>Изменение названий команд не меняет логику работы, а только задаёт текст команды и её описание.</p>
                    </div>
                </section>
            </div>

            <div class="cw-settings-actions">
                <button type="submit" name="cw_commands_save" class="button button-primary">Сохранить настройки</button>
            </div>
        </form>
    </div>
    <?php
}
