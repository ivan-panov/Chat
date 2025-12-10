<?php
/*
Plugin Name: Chat Widget
Description: Онлайн-чат + операторская панель + Telegram интеграция.
Version: 3.1
Author: Fakel
*/

if (!defined('ABSPATH')) exit;

/* =====================================================================
   ПОДКЛЮЧЕНИЕ operator-panel.php (ДОЛЖНО БЫТЬ ДО admin_menu !!!)
===================================================================== */
require_once __DIR__ . '/admin/operator-panel.php';

/* =====================================================================
   FRONTEND — CSS и JS клиента
===================================================================== */
add_action('wp_enqueue_scripts', function () {

    wp_enqueue_style(
        'cw-style',
        plugin_dir_url(__FILE__) . 'chat-widget.css',
        [],
        filemtime(__DIR__ . '/chat-widget.css')
    );

    wp_enqueue_script(
        'cw-script',
        plugin_dir_url(__FILE__) . 'chat-widget.js',
        ['jquery'],
        filemtime(__DIR__ . '/chat-widget.js'),
        true
    );

    wp_localize_script('cw-script', 'CW_API', [
        'root'   => esc_url_raw(rest_url('cw/v1/')),
        'nonce'  => wp_create_nonce('wp_rest')
    ]);
});


/* =====================================================================
   HTML клиента
===================================================================== */
add_action('wp_footer', function () { ?>
    <div id="cw-open-btn">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="white">
            <path d="M4 4C2.895 4 2 4.895 2 6V14C2 15.105 2.895 16 4 16H6V20L11.2 16H20C21.105 16 22 15.105 22 14V6C22 4.895 21.105 4 20 4H4Z"/>
        </svg>
        <span id="cw-badge"></span>
    </div>

    <div id="cw-chat-box" style="display:none;">
        <div id="cw-header">
            <span id="cw-title">Чат с оператором</span>
            <span id="cw-close">×</span>
        </div>

        <div id="cw-chat-window"></div>

        <div id="cw-input-box">
            <input id="cw-input" type="text" placeholder="Введите сообщение..." autocomplete="off">
            <button id="cw-send">➤</button>
        </div>

        <div id="cw-new-dialog-btn" class="disabled">Начать новый диалог</div>
    </div>
<?php });


/* =====================================================================
   ADMIN MENU — теперь callback существует!
===================================================================== */
add_action('admin_menu', function () {

    add_menu_page(
        'Чат',
        'Чат',
        'manage_options',
        'cw_operator',
        'cw_operator_panel_page', // ← функция уже подключена выше
        'dashicons-format-chat',
        25
    );

    add_submenu_page(
        'cw_operator',
        'Telegram',
        'Telegram',
        'manage_options',
        'cw_telegram',
        'cw_telegram_settings_page'
    );
});


/* =====================================================================
   ADMIN CSS/JS
===================================================================== */
add_action('admin_enqueue_scripts', function ($hook) {

    if ($hook !== 'toplevel_page_cw_operator') return;

    wp_enqueue_style(
        'cw-operator-css',
        plugin_dir_url(__FILE__) . 'admin/operator-panel.css',
        [],
        filemtime(__DIR__ . '/admin/operator-panel.css')
    );

    wp_enqueue_script(
        'cw-operator-js',
        plugin_dir_url(__FILE__) . 'admin/operator-panel.js',
        ['jquery'],
        filemtime(__DIR__ . '/admin/operator-panel.js'),
        true
    );

    wp_localize_script('cw-operator-js', 'CW_ADMIN', [
        'rest'  => esc_url_raw(rest_url('cw/v1/')),
        'nonce' => wp_create_nonce('wp_rest'),
    ]);
});


/* =====================================================================
   Подключение REST и Telegram (правильно)
===================================================================== */
require_once __DIR__ . '/inc/rest.php';
require_once __DIR__ . '/inc/telegram.php';
require_once __DIR__ . '/inc/telegram-settings.php';
