<?php
if (!defined('ABSPATH')) exit;

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

    ?>
    <div class="wrap">
        <h1>Команды</h1>

        <?php if ($saved): ?>
            <div class="updated notice">
                <p>Настройки команд сохранены.</p>
            </div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('cw_commands_settings_nonce'); ?>

            <table class="form-table">
                <tr>
                    <th colspan="2">
                        <h2 style="margin:0;">Команда /офис</h2>
                    </th>
                </tr>

                <tr>
                    <th scope="row">Включить</th>
                    <td>
                        <label>
                            <input type="checkbox" name="cw_cmd_office_enabled" value="1" <?php checked($office_enabled, 1); ?>>
                            Активировать команду
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Название команды</th>
                    <td>
                        <input type="text" name="cw_cmd_office_label" value="<?php echo esc_attr($office_label); ?>" class="regular-text">
                    </td>
                </tr>

                <tr>
                    <th scope="row">Описание</th>
                    <td>
                        <textarea name="cw_cmd_office_description" rows="3" class="large-text"><?php echo esc_textarea($office_description); ?></textarea>
                    </td>
                </tr>

                <tr>
                    <th colspan="2">
                        <hr>
                        <h2 style="margin:0;">Команда /вход</h2>
                    </th>
                </tr>

                <tr>
                    <th scope="row">Включить</th>
                    <td>
                        <label>
                            <input type="checkbox" name="cw_cmd_login_enabled" value="1" <?php checked($login_enabled, 1); ?>>
                            Активировать команду
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Название команды</th>
                    <td>
                        <input type="text" name="cw_cmd_login_label" value="<?php echo esc_attr($login_label); ?>" class="regular-text">
                    </td>
                </tr>

                <tr>
                    <th scope="row">Описание</th>
                    <td>
                        <textarea name="cw_cmd_login_description" rows="3" class="large-text"><?php echo esc_textarea($login_description); ?></textarea>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" name="cw_commands_save" class="button button-primary">
                    Сохранить настройки
                </button>
            </p>
        </form>
    </div>
    <?php
}