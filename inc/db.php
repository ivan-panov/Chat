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
}

