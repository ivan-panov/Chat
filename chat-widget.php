<?php
/*
Plugin Name: Chat Widget
Description: Онлайн-чат с оператором + Telegram и MAX интеграция.
Version: 5.4
Author: Fakel
*/

if (!defined('ABSPATH')) exit;

/* ============================================================
   INCLUDES
============================================================ */

require_once __DIR__ . '/inc/rest.php';
require_once __DIR__ . '/inc/telegram.php';
require_once __DIR__ . '/inc/telegram-settings.php';
require_once __DIR__ . '/inc/max.php';
require_once __DIR__ . '/inc/max-settings.php';
require_once __DIR__ . '/inc/commands-settings.php';
require_once __DIR__ . '/inc/bot.php';
require_once __DIR__ . '/inc/bot-settings.php';
require_once __DIR__ . '/admin/operator-panel.php';

/* ============================================================
   DB SCHEMA
============================================================ */

function cw_create_or_update_tables(): void {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset = $wpdb->get_charset_collate();

    $table_dialogs  = $wpdb->prefix . 'cw_dialogs';
    $table_messages = $wpdb->prefix . 'cw_messages';

    $sql_dialogs = "CREATE TABLE {$table_dialogs} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        client_key VARCHAR(190) NOT NULL DEFAULT '',
        geo_country VARCHAR(190) NOT NULL DEFAULT '',
        geo_city VARCHAR(190) NOT NULL DEFAULT '',
        geo_region VARCHAR(190) NOT NULL DEFAULT '',
        geo_org VARCHAR(190) NOT NULL DEFAULT '',
        geo_ip VARCHAR(100) NOT NULL DEFAULT '',
        geo_browser TEXT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY status (status),
        KEY client_key (client_key(190))
    ) {$charset};";

    $sql_messages = "CREATE TABLE {$table_messages} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        dialog_id BIGINT UNSIGNED NOT NULL,
        message LONGTEXT NOT NULL,
        is_operator TINYINT(1) NOT NULL DEFAULT 0,
        is_bot TINYINT(1) NOT NULL DEFAULT 0,
        unread TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY dialog_id (dialog_id),
        KEY unread (unread),
        KEY is_operator (is_operator),
        KEY is_bot (is_bot)
    ) {$charset};";

    dbDelta($sql_dialogs);
    dbDelta($sql_messages);

    $has_geo_org = $wpdb->get_var("SHOW COLUMNS FROM {$table_dialogs} LIKE 'geo_org'");
    if (!$has_geo_org) {
        $wpdb->query("ALTER TABLE {$table_dialogs} ADD COLUMN geo_org VARCHAR(190) NOT NULL DEFAULT '' AFTER geo_region");
    }

    $has_geo_isp = $wpdb->get_var("SHOW COLUMNS FROM {$table_dialogs} LIKE 'geo_isp'");
    if ($has_geo_isp && !$has_geo_org) {
        $wpdb->query("UPDATE {$table_dialogs} SET geo_org = geo_isp WHERE geo_org = ''");
    }

    $has_is_bot = $wpdb->get_var("SHOW COLUMNS FROM {$table_messages} LIKE 'is_bot'");
    if (!$has_is_bot) {
        $wpdb->query("ALTER TABLE {$table_messages} ADD COLUMN is_bot TINYINT(1) NOT NULL DEFAULT 0 AFTER is_operator");
        $wpdb->query("ALTER TABLE {$table_messages} ADD KEY is_bot (is_bot)");
    }
}

/* ============================================================
   ACTIVATION: TABLES
============================================================ */

register_activation_hook(__FILE__, function () {
    cw_create_or_update_tables();
});


/* ============================================================
   FRONTEND VISIBILITY
============================================================ */

function cw_should_render_frontend_widget(): bool {
    if (is_admin()) {
        return false;
    }

    if (function_exists('is_404') && is_404()) {
        return false;
    }

    return true;
}

/* ============================================================
   FRONTEND ASSETS
============================================================ */

add_action('wp_enqueue_scripts', function () {
    if (!cw_should_render_frontend_widget()) {
        return;
    }


    wp_enqueue_style(
        'cw-style',
        plugin_dir_url(__FILE__) . 'chat-widget.css',
        [],
        file_exists(__DIR__ . '/chat-widget.css')
            ? filemtime(__DIR__ . '/chat-widget.css')
            : '1.0'
    );

    wp_enqueue_script(
        'cw-shared',
        plugin_dir_url(__FILE__) . 'cw-shared.js',
        [],
        file_exists(__DIR__ . '/cw-shared.js')
            ? filemtime(__DIR__ . '/cw-shared.js')
            : '1.0',
        true
    );

    wp_enqueue_script(
        'cw-script',
        plugin_dir_url(__FILE__) . 'chat-widget.js',
        ['jquery', 'cw-shared'],
        file_exists(__DIR__ . '/chat-widget.js')
            ? filemtime(__DIR__ . '/chat-widget.js')
            : '1.0',
        true
    );

    wp_localize_script('cw-script', 'CW_API', [
        'root'  => esc_url_raw(rest_url('cw/v1/')),
        'nonce' => wp_create_nonce('wp_rest')
    ]);

    wp_enqueue_script(
        'cw-teaser-js',
        plugin_dir_url(__FILE__) . 'chat-teaser.js',
        ['jquery', 'cw-script'],
        file_exists(__DIR__ . '/chat-teaser.js')
            ? filemtime(__DIR__ . '/chat-teaser.js')
            : '1.0',
        true
    );
});

/* ============================================================
   FRONTEND HTML
============================================================ */

add_action('wp_footer', function () {
    if (!cw_should_render_frontend_widget()) {
        return;
    }
?>

    <div id="cw-open-btn" role="button" aria-label="Открыть чат">
        <svg viewBox="0 0 24 24" width="24" height="24" fill="white" aria-hidden="true">
            <path d="M4 4C2.9 4 2 4.9 2 6v8c0 1.1.9 2 2 2h2v4l5.2-4H20c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2H4z"/>
        </svg>
        <span id="cw-badge"></span>
    </div>

    <div id="cw-chat-box" style="display:none;">

        <div id="cw-header">
            <span>Чат с оператором</span>
            <span id="cw-close" role="button" aria-label="Закрыть чат">×</span>
        </div>

        <div id="cw-chat-window"></div>

        <div id="cw-input-box">
            <div class="cw-input-wrapper">
                <input
                    id="cw-input"
                    type="text"
                    placeholder="Введите сообщение..."
                    autocomplete="off"
                >

                <button
                    type="button"
                    id="cw-file-btn"
                    aria-label="Прикрепить файл"
                >📎</button>

                <input
                    type="file"
                    id="cw-file"
                    accept="image/jpeg,image/png,image/webp,application/pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar"
                    style="display:none;"
                >
            </div>

            <button
                id="cw-send"
                type="button"
                title="Отправить"
                aria-label="Отправить сообщение"
            >➤</button>
        </div>

        <div id="cw-new-dialog-btn">
            Начать новый диалог
        </div>

    </div>

    <audio id="cw-sound" preload="auto">
        <source
            src="<?php echo esc_url(plugin_dir_url(__FILE__) . 'assets/notify.mp3'); ?>"
            type="audio/mpeg"
        >
    </audio>

<?php });

/* ============================================================
   ADMIN MENU HELPERS
============================================================ */

function cw_menu_status_label(string $title, bool $enabled): string {
    return $title . ' ' . ($enabled ? '🟢' : '🔴');
}

/* ============================================================
   ADMIN MENU
============================================================ */

add_action('admin_menu', function () {

    $tg_enabled  = function_exists('cw_tg_enabled') ? cw_tg_enabled() : true;
    $max_enabled = function_exists('cw_max_enabled') ? cw_max_enabled() : true;
    $bot_enabled = function_exists('cw_bot_enabled') ? cw_bot_enabled() : false;

    $tg_label  = cw_menu_status_label('Telegram', $tg_enabled);
    $max_label = cw_menu_status_label('MAX', $max_enabled);
    $bot_label = cw_menu_status_label('Бот', $bot_enabled);

    add_menu_page(
        'Чат',
        'Чат',
        'manage_options',
        'cw_operator',
        'cw_operator_panel_page',
        'dashicons-format-chat',
        25
    );

    add_submenu_page(
        'cw_operator',
        $tg_label,
        $tg_label,
        'manage_options',
        'cw_telegram',
        'cw_telegram_settings_page'
    );

    add_submenu_page(
        'cw_operator',
        $max_label,
        $max_label,
        'manage_options',
        'cw_max',
        'cw_max_settings_page'
    );

    add_submenu_page(
        'cw_operator',
        $bot_label,
        $bot_label,
        'manage_options',
        'cw_bot',
        'cw_bot_settings_page'
    );

    add_submenu_page(
        'cw_operator',
        'Команды',
        'Команды',
        'manage_options',
        'cw_commands',
        'cw_commands_settings_page'
    );
});

/* ============================================================
   ADMIN ASSETS
============================================================ */

add_action('admin_enqueue_scripts', function ($hook) {

    $admin_pages = [
        'toplevel_page_cw_operator',
        'chat_page_cw_telegram',
        'chat_page_cw_max',
        'chat_page_cw_bot',
        'chat_page_cw_commands',
    ];

    if (!in_array($hook, $admin_pages, true)) {
        return;
    }

    wp_enqueue_style(
        'cw-operator-css',
        plugin_dir_url(__FILE__) . 'admin/operator-panel.css',
        [],
        file_exists(__DIR__ . '/admin/operator-panel.css')
            ? filemtime(__DIR__ . '/admin/operator-panel.css')
            : '1.0'
    );

    wp_enqueue_script(
        'cw-shared',
        plugin_dir_url(__FILE__) . 'cw-shared.js',
        [],
        file_exists(__DIR__ . '/cw-shared.js')
            ? filemtime(__DIR__ . '/cw-shared.js')
            : '1.0',
        true
    );

    if ($hook !== 'toplevel_page_cw_operator') {
        return;
    }

    wp_enqueue_script(
        'cw-operator-js',
        plugin_dir_url(__FILE__) . 'admin/operator-panel.js',
        ['jquery', 'cw-shared'],
        file_exists(__DIR__ . '/admin/operator-panel.js')
            ? filemtime(__DIR__ . '/admin/operator-panel.js')
            : '1.0',
        true
    );

    wp_localize_script('cw-operator-js', 'CW_ADMIN_API', [
        'root'  => esc_url_raw(rest_url('cw/v1/')),
        'nonce' => wp_create_nonce('wp_rest')
    ]);
});