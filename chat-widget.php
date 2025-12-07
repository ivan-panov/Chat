<?php
/**
 * Plugin Name: Chat Widget (LiveChat Style)
 * Description: Чат-виджет с диалогами, админкой и Telegram-интеграцией.
 * Version: 1.1.0
 * Author: Your Name
 */

if (!defined('ABSPATH')) exit;

define('CW_DIR', plugin_dir_path(__FILE__));
define('CW_URL', plugin_dir_url(__FILE__));

/*
|--------------------------------------------------------------------------
| Подключение файлов (обязательно)
|--------------------------------------------------------------------------
*/
require_once CW_DIR . 'inc/db.php';
require_once CW_DIR . 'inc/rest.php';
require_once CW_DIR . 'inc/telegram.php';
require_once CW_DIR . 'inc/telegram-settings.php';
require_once CW_DIR . 'inc/helpers.php';

require_once CW_DIR . 'admin/dialogs-list.php';
require_once CW_DIR . 'admin/dialog-view.php';
require_once CW_DIR . 'admin/settings.php';

/*
|--------------------------------------------------------------------------
| Активация плагина — создаём таблицы
|--------------------------------------------------------------------------
*/
register_activation_hook(__FILE__, 'cw_install_tables');


/*
|--------------------------------------------------------------------------
| Подключение JS и CSS (файлы находятся в корне!)
|--------------------------------------------------------------------------
*/
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('cw-style', CW_URL . 'chat-widget.css');
    wp_enqueue_script('cw-script', CW_URL . 'chat-widget.js', ['jquery'], false, true);

    wp_localize_script('cw-script', 'CW_API', [
        'rest'      => rest_url('cw/v1/dialog'),
        'nonce'     => wp_create_nonce('wp_rest'),
        'pollEvery' => intval(get_option('cw_poll_interval', 8)),
        'texts'     => [
            'title'     => esc_html(get_option('cw_widget_title', 'Поддержка онлайн')),
            'subtitle'  => esc_html(get_option('cw_widget_subtitle', 'Ответим в течение нескольких минут')),
            'startCta'  => esc_html(get_option('cw_widget_cta', 'Начать чат')),
            'sendCta'   => esc_html(get_option('cw_widget_send', 'Отправить')),
            'operator'  => esc_html(get_option('cw_widget_operator', 'Оператор скоро подключится'))
        ],
    ]);
});


/*
|--------------------------------------------------------------------------
| HTML LiveChat-виджета на фронтенде
|--------------------------------------------------------------------------
*/
add_action('wp_footer', function () {
    ?>
    <div id="cw-widget">
        <button id="cw-open"></button>

        <div id="cw-modal" style="display:none">
            <div class="cw-header">
                <div>
                    <h3><?php echo esc_html(get_option('cw_widget_title', 'Поддержка онлайн')); ?></h3>
                    <p class="cw-subtitle"><?php echo esc_html(get_option('cw_widget_subtitle', 'Ответим в течение нескольких минут')); ?></p>
                </div>
                <span class="cw-operator-status">●</span>
            </div>

            <div id="cw-modal-content">
                <div id="cw-start-form" class="cw-section">
                    <p class="cw-operator-hint"><?php echo esc_html(get_option('cw_widget_operator', 'Оператор скоро подключится')); ?></p>
                    <div class="cw-two-columns">
                        <input type="text" id="cw-name" placeholder="Ваше имя">
                        <input type="text" id="cw-phone" placeholder="Телефон">
                    </div>
                    <textarea id="cw-message" placeholder="Первое сообщение"></textarea>

                    <button id="cw-start"><?php echo esc_html(get_option('cw_widget_cta', 'Начать чат')); ?></button>
                </div>

                <div id="cw-chat" class="cw-section" style="display:none">
                    <div class="cw-chat-meta">
                        <div>
                            <strong id="cw-chat-username"></strong>
                            <span id="cw-chat-phone"></span>
                        </div>
                        <small class="cw-chat-status">Ожидание оператора</small>
                    </div>

                    <div id="cw-messages"></div>

                    <div class="cw-reply">
                        <textarea id="cw-reply-text" placeholder="Введите сообщение"></textarea>
                        <button id="cw-send"><?php echo esc_html(get_option('cw_widget_send', 'Отправить')); ?></button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
});


/*
|--------------------------------------------------------------------------
| Админ-меню WordPress
|--------------------------------------------------------------------------
*/

add_action('admin_menu', function () {

    add_menu_page(
        'Диалоги чата',
        'Чат-диалоги',
        'manage_options',
        'cw-dialogs',
        'cw_admin_dialogs_page',
        'dashicons-format-chat'
    );

    // Страница просмотра диалога (скрытая)
    add_submenu_page(
        'cw-dialogs',
        'Просмотр диалога',
        '',
        'manage_options',
        'cw-dialog-view',
        'cw_admin_dialog_view'
    );

    // Настройки Telegram
    add_submenu_page(
        'cw-dialogs',
        'Telegram настройки',
        'Telegram',
        'manage_options',
        'cw-telegram-settings',
        'cw_telegram_settings_page'
    );

    add_submenu_page(
        'cw-dialogs',
        'Настройки плагина',
        'Настройки',
        'manage_options',
        'cw-settings',
        'cw_admin_settings_page'
    );

});

