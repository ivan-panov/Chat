<?php

function cw_install_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $dialogs = $wpdb->prefix . 'cw_dialogs';
    $messages = $wpdb->prefix . 'cw_messages';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta("
        CREATE TABLE $dialogs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_name VARCHAR(255),
            phone VARCHAR(50),
            user_token VARCHAR(64),
            status VARCHAR(20) DEFAULT 'open',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $charset;
    ");

    dbDelta("
        CREATE TABLE $messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            dialog_id BIGINT UNSIGNED,
            sender ENUM('user','admin','telegram'),
            message TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $charset;
    ");

    // Обновление существующих установок: добавляем user_token если его нет
    $column = $wpdb->get_results($wpdb->prepare(
        "SHOW COLUMNS FROM {$dialogs} LIKE %s",
        'user_token'
    ));

    if (empty($column)) {
        $wpdb->query("ALTER TABLE {$dialogs} ADD COLUMN user_token VARCHAR(64) AFTER phone");
    }
}

/**
 * Лёгкая проверка структуры таблиц при загрузке плагина
 */
function cw_maybe_upgrade_tables() {
    global $wpdb;
    $dialogs = $wpdb->prefix . 'cw_dialogs';

    $column = $wpdb->get_results($wpdb->prepare(
        "SHOW COLUMNS FROM {$dialogs} LIKE %s",
        'user_token'
    ));

    if (empty($column)) {
        $wpdb->query("ALTER TABLE {$dialogs} ADD COLUMN user_token VARCHAR(64) AFTER phone");
    }
}

add_action('plugins_loaded', 'cw_maybe_upgrade_tables');

