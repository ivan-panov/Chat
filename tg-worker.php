<?php
// Background worker for Telegram file queue. It is normally started by tg-webhook.php
// through PHP CLI. No WP-Cron is used.

ignore_user_abort(true);
@set_time_limit(120);

$wp_load = __DIR__ . '/../../../wp-load.php';
if (!is_file($wp_load)) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo '{"error":"wp_load_not_found"}';
    }
    exit(1);
}

require_once $wp_load;

$result = ['status' => 'missing_processor'];
if (function_exists('cw_tg_process_file_queue')) {
    $result = cw_tg_process_file_queue(25);
}

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
    echo wp_json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

exit;
