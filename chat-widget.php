<?php
/**
 * Plugin Name: Chat Widget (LiveChat Style)
 * Description: Чат-виджет с диалогами, админкой и Telegram-интеграцией.
 * Version: 1.0.1
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
        'rest'  => rest_url('cw/v1/dialog'),
        'nonce' => wp_create_nonce('wp_rest')
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
            <h3>Поддержка онлайн</h3>

            <div id="cw-modal-content">
                <input type="text" id="cw-name" placeholder="Ваше имя">
                <input type="text" id="cw-phone" placeholder="Телефон">
                <textarea id="cw-message" placeholder="Ваше сообщение"></textarea>

                <button id="cw-send">Отправить</button>
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

});

