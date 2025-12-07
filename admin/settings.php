<?php

function cw_admin_settings_page() {

    if (isset($_POST['cw_save_settings'])) {
        check_admin_referer('cw_save_settings');

        update_option('cw_widget_title', sanitize_text_field($_POST['cw_widget_title'] ?? ''));
        update_option('cw_widget_subtitle', sanitize_text_field($_POST['cw_widget_subtitle'] ?? ''));
        update_option('cw_widget_cta', sanitize_text_field($_POST['cw_widget_cta'] ?? ''));
        update_option('cw_widget_send', sanitize_text_field($_POST['cw_widget_send'] ?? ''));
        update_option('cw_widget_operator', sanitize_text_field($_POST['cw_widget_operator'] ?? ''));
        update_option('cw_poll_interval', max(3, intval($_POST['cw_poll_interval'] ?? 8)));

        update_option('cw_tg_bot_token', sanitize_text_field($_POST['cw_tg_bot_token'] ?? ''));
        update_option('cw_tg_chat_id', sanitize_text_field($_POST['cw_tg_chat_id'] ?? ''));

        echo '<div class="updated"><p>Настройки обновлены.</p></div>';
    }

    $title     = get_option('cw_widget_title', 'Поддержка онлайн');
    $subtitle  = get_option('cw_widget_subtitle', 'Ответим в течение нескольких минут');
    $cta       = get_option('cw_widget_cta', 'Начать чат');
    $send      = get_option('cw_widget_send', 'Отправить');
    $operator  = get_option('cw_widget_operator', 'Оператор скоро подключится');
    $interval  = intval(get_option('cw_poll_interval', 8));
    $token     = get_option('cw_tg_bot_token', '');
    $chat      = get_option('cw_tg_chat_id', '');
    ?>

    <div class="wrap">
        <h1>Настройки чата</h1>

        <form method="post">
            <?php wp_nonce_field('cw_save_settings'); ?>

            <h2>Тексты виджета</h2>
            <table class="form-table">
                <tr>
                    <th>Заголовок</th>
                    <td><input type="text" name="cw_widget_title" value="<?= esc_attr($title) ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th>Подзаголовок</th>
                    <td><input type="text" name="cw_widget_subtitle" value="<?= esc_attr($subtitle) ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th>Кнопка начала чата</th>
                    <td><input type="text" name="cw_widget_cta" value="<?= esc_attr($cta) ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th>Кнопка отправки</th>
                    <td><input type="text" name="cw_widget_send" value="<?= esc_attr($send) ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th>Сообщение оператора</th>
                    <td><input type="text" name="cw_widget_operator" value="<?= esc_attr($operator) ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th>Интервал обновления (секунды)</th>
                    <td><input type="number" min="3" name="cw_poll_interval" value="<?= esc_attr($interval) ?>" class="small-text"></td>
                </tr>
            </table>

            <h2>Интеграция Telegram</h2>
            <p class="description">Укажите токен и чат, чтобы отправлять входящие и исходящие сообщения в Telegram.</p>
            <table class="form-table">
                <tr>
                    <th>Bot Token</th>
                    <td><input type="text" name="cw_tg_bot_token" value="<?= esc_attr($token) ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th>Chat ID</th>
                    <td><input type="text" name="cw_tg_chat_id" value="<?= esc_attr($chat) ?>" class="regular-text"></td>
                </tr>
            </table>

            <p><button type="submit" class="button button-primary" name="cw_save_settings">Сохранить</button></p>
        </form>
    </div>
    <?php
}

