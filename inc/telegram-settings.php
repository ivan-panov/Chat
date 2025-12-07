<?php

function cw_telegram_settings_page() {

    // Сохранение настроек
    if (isset($_POST['cw_save_telegram'])) {

        update_option('cw_tg_bot_token', sanitize_text_field($_POST['cw_tg_bot_token']));
        update_option('cw_tg_chat_id', sanitize_text_field($_POST['cw_tg_chat_id']));

        echo '<div class="updated"><p>Настройки сохранены.</p></div>';
    }

    $token = get_option('cw_tg_bot_token', '');
    $chat  = get_option('cw_tg_chat_id', '');
    ?>

    <div class="wrap">
        <h1>Настройки Telegram</h1>

        <form method="post">
            <table class="form-table">

                <tr>
                    <th>Bot Token</th>
                    <td>
                        <input type="text" name="cw_tg_bot_token" value="<?= esc_attr($token) ?>" class="regular-text">
                        <p class="description">Токен вашего Telegram бота, например: 123456789:ABC-defGHIjklMN_opQRstuVWxyz</p>
                    </td>
                </tr>

                <tr>
                    <th>Chat ID</th>
                    <td>
                        <input type="text" name="cw_tg_chat_id" value="<?= esc_attr($chat) ?>" class="regular-text">
                        <p class="description">ID чата или канала, куда будут отправляться сообщения.</p>
                    </td>
                </tr>

            </table>

            <p><button class="button button-primary" name="cw_save_telegram">Сохранить</button></p>
        </form>
    </div>
    <?php
}
