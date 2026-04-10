<?php
if (!defined('ABSPATH')) exit;

function cw_bot_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Access denied');
    }

    $saved = false;

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        check_admin_referer('cw_bot_settings_nonce');

        $enabled = !empty($_POST['cw_bot_enabled']) ? 1 : 0;
        $bot_name = sanitize_text_field(wp_unslash($_POST['cw_bot_name'] ?? 'Бот'));
        $bot_command = sanitize_text_field(wp_unslash($_POST['cw_bot_command_label'] ?? '/бот'));
        $operator_command = sanitize_text_field(wp_unslash($_POST['cw_bot_operator_label'] ?? '/оператор'));
        $stop_command = sanitize_text_field(wp_unslash($_POST['cw_bot_stop_label'] ?? '/стоп'));

        $rules_text_raw = (string) wp_unslash($_POST['cw_bot_rules_text'] ?? '');
        $rules_text_raw = html_entity_decode($rules_text_raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $rules_text = wp_kses(
            $rules_text_raw,
            function_exists('cw_bot_allowed_html') ? cw_bot_allowed_html() : []
        );

        update_option('cw_bot_enabled', $enabled);
        update_option('cw_bot_name', $bot_name !== '' ? $bot_name : 'Бот');
        update_option('cw_bot_command_label', $bot_command !== '' ? $bot_command : '/бот');
        update_option('cw_bot_operator_label', $operator_command !== '' ? $operator_command : '/оператор');
        update_option('cw_bot_stop_label', $stop_command !== '' ? $stop_command : '/стоп');
        update_option('cw_bot_rules_text', trim($rules_text));

        $saved = true;
    }

    $enabled          = (int) get_option('cw_bot_enabled', 1);
    $bot_name         = (string) get_option('cw_bot_name', 'Бот');
    $bot_command      = (string) get_option('cw_bot_command_label', '/бот');
    $operator_command = (string) get_option('cw_bot_operator_label', '/оператор');
    $stop_command     = (string) get_option('cw_bot_stop_label', '/стоп');
    $rules_text       = (string) get_option('cw_bot_rules_text', '');

    if (trim($rules_text) === '' && function_exists('cw_bot_default_rules_text')) {
        $rules_text = cw_bot_default_rules_text();
    }
    ?>
    <div class="wrap">
        <h1>Бот</h1>

        <?php if ($saved): ?>
            <div class="updated notice">
                <p>Настройки бота сохранены.</p>
            </div>
        <?php endif; ?>

        <?php if ($enabled): ?>
            <div class="notice notice-success">
                <p><strong>Бот включён.</strong> Автоответ работает по правилам ниже.</p>
            </div>
        <?php else: ?>
            <div class="notice notice-warning">
                <p><strong>Бот выключен.</strong> Все сообщения будут уходить оператору как обычно.</p>
            </div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('cw_bot_settings_nonce'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">Включить бота</th>
                    <td>
                        <label>
                            <input type="checkbox" name="cw_bot_enabled" value="1" <?php checked($enabled, 1); ?>>
                            Бот активен
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Имя бота</th>
                    <td>
                        <input type="text" name="cw_bot_name" value="<?php echo esc_attr($bot_name); ?>" class="regular-text" autocomplete="off">
                    </td>
                </tr>

                <tr>
                    <th scope="row">Команда включения</th>
                    <td>
                        <input type="text" name="cw_bot_command_label" value="<?php echo esc_attr($bot_command); ?>" class="regular-text" autocomplete="off">
                        <p class="description">Эта команда снова включает автоответы в текущем диалоге.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Команда оператора</th>
                    <td>
                        <input type="text" name="cw_bot_operator_label" value="<?php echo esc_attr($operator_command); ?>" class="regular-text" autocomplete="off">
                        <p class="description">Эта команда отключает бота в текущем диалоге и сразу зовёт оператора.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Команда остановки</th>
                    <td>
                        <input type="text" name="cw_bot_stop_label" value="<?php echo esc_attr($stop_command); ?>" class="regular-text" autocomplete="off">
                        <p class="description">Альтернативная команда для отключения бота и подключения оператора.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Правила ответов</th>
                    <td>
                        <textarea name="cw_bot_rules_text" rows="18" class="large-text code"><?php echo esc_textarea($rules_text); ?></textarea>

                        <p class="description" style="margin-top:8px;">
                            Формат одной строки:
                            <code>ключ1|ключ2 =&gt; Ответ</code>
                        </p>

                        <p class="description">
                            Можно использовать плейсхолдеры:
                            <code>{bot_name}</code>,
                            <code>{operator_command}</code>,
                            <code>{bot_command}</code>,
                            <code>{stop_command}</code>.
                        </p>

                        <p class="description">
                            Допустимы ссылки HTML, например:
                            <code>&lt;a href="/contacts/" target="_blank" rel="noopener noreferrer"&gt;Контакты&lt;/a&gt;</code>
                        </p>

                        <p class="description">
                            Пустые строки и строки, начинающиеся с <code>#</code>, игнорируются.
                        </p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" name="cw_bot_save" class="button button-primary">
                    Сохранить настройки
                </button>
            </p>
        </form>
    </div>
    <?php
}