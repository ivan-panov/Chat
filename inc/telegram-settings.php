<?php
if (!defined('ABSPATH')) exit;

function cw_telegram_settings_page() {

    /* ----------------------------------------------------------
       Сохранение настроек
    ---------------------------------------------------------- */
    if (!empty($_POST['cw_tg_save'])) {

        update_option('cw_tg_token', sanitize_text_field($_POST['cw_tg_token']));
        update_option('cw_tg_admin_chat', sanitize_text_field($_POST['cw_tg_admin_chat']));

        echo '<div class="updated notice"><p><strong>Настройки Telegram сохранены.</strong></p></div>';
    }

    $token      = get_option('cw_tg_token');
    $adminChat  = get_option('cw_tg_admin_chat');

    // URL хука → https://site.ru/wp-json/cw/v1/tg-webhook
    $webhookUrl = esc_url_raw(rest_url('cw/v1/tg-webhook'));

    ?>
    <div class="wrap">

        <h1>Интеграция с Telegram</h1>

        <p>Здесь вы можете подключить Telegram-бота для получения сообщений из чата.</p>

        <form method="post" style="margin-top:20px;">
            <input type="hidden" name="cw_tg_save" value="1">

            <table class="form-table">

                <tr>
                    <th><label for="cw_tg_token">Токен Telegram-бота</label></th>
                    <td>
                        <input type="text" id="cw_tg_token" name="cw_tg_token"
                               value="<?php echo esc_attr($token); ?>"
                               style="width:400px;">
                        <p class="description">
                            Получите токен у <strong>@BotFather</strong> командой <code>/newbot</code>.
                        </p>
                    </td>
                </tr>

                <tr>
                    <th><label for="cw_tg_admin_chat">ID администратора</label></th>
                    <td>
                        <input type="text" id="cw_tg_admin_chat" name="cw_tg_admin_chat"
                               value="<?php echo esc_attr($adminChat); ?>"
                               style="width:400px;">

                        <p class="description">
                            Узнать свой Telegram ID можно через бота <strong>@userinfobot</strong>.
                        </p>
                    </td>
                </tr>

            </table>

            <p>
                <button type="submit" class="button button-primary">💾 Сохранить настройки</button>
            </p>
        </form>

        <hr>

        <h2>Webhook URL</h2>

        <input type="text"
               value="<?php echo $webhookUrl; ?>"
               readonly
               style="width:100%; background:#f0f0f0;">

        <?php if (!$token): ?>

            <p style="color:red; font-weight:bold; margin-top:15px;">
                ⚠ Укажите токен Telegram-бота, чтобы активировать Webhook.
            </p>

        <?php else: ?>

            <h2 style="margin-top:25px;">Установка Webhook</h2>

            <?php
            $setWebhookUrl = "https://api.telegram.org/bot{$token}/setWebhook?url={$webhookUrl}";
            $getWebhookInfo = "https://api.telegram.org/bot{$token}/getWebhookInfo";
            ?>

            <p>
                <a href="<?php echo esc_url($setWebhookUrl); ?>" target="_blank"
                   class="button button-secondary">
                    🔗 Установить Webhook
                </a>

                <a href="<?php echo esc_url($getWebhookInfo); ?>" target="_blank"
                   class="button">
                    Проверить Webhook
                </a>
            </p>

        <?php endif; ?>

    </div>
    <?php
}
