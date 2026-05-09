<?php
// Background worker for Telegram file queue. No WP-Cron is used.
// A process-level lock prevents several workers from processing the same queue at once.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

ignore_user_abort(true);
@set_time_limit(120);

function cw_tg_worker_queue_dir(): string {
    $wp_content = dirname(__DIR__, 2);
    $uploads = $wp_content . '/uploads';
    $dir = $uploads . '/cw-tg-queue';

    if (is_dir($dir) || @mkdir($dir, 0755, true)) {
        return $dir;
    }

    $fallback = rtrim(sys_get_temp_dir(), '/\\') . '/cw-tg-queue-' . md5(__DIR__);
    if (!is_dir($fallback)) {
        @mkdir($fallback, 0755, true);
    }

    return $fallback;
}

function cw_tg_worker_log(string $message): void {
    $dir = cw_tg_worker_queue_dir();
    if ($dir !== '' && is_dir($dir) && is_writable($dir)) {
        @file_put_contents($dir . '/webhook.log', '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n", FILE_APPEND | LOCK_EX);
    }
}

function cw_tg_worker_marker_file(): string {
    return rtrim(cw_tg_worker_queue_dir(), '/\\') . '/worker-active.lock';
}

function cw_tg_worker_clear_marker(): void {
    @unlink(cw_tg_worker_marker_file());
}

$dir = cw_tg_worker_queue_dir();
$lock_path = rtrim($dir, '/\\') . '/worker-process.lock';
$lock = @fopen($lock_path, 'c');

if (!$lock) {
    cw_tg_worker_log('worker_lock_open_failed');
    exit(1);
}

if (!@flock($lock, LOCK_EX | LOCK_NB)) {
    cw_tg_worker_log('worker_already_running_exit');
    @fclose($lock);
    exit(0);
}

@file_put_contents(cw_tg_worker_marker_file(), (string) time(), LOCK_EX);

register_shutdown_function(function () use ($lock) {
    cw_tg_worker_clear_marker();
    if (is_resource($lock)) {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
});

$wp_load = __DIR__ . '/../../../wp-load.php';
if (!is_file($wp_load)) {
    cw_tg_worker_log('worker_wp_load_not_found');
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo '{"error":"wp_load_not_found"}';
    }
    exit(1);
}

require_once $wp_load;

$result = ['status' => 'missing_processor'];
$summary = ['status' => 'missing_processor', 'processed' => 0, 'errors' => 0, 'remaining' => 0, 'batches' => 0];

if (function_exists('cw_tg_process_file_queue')) {
    $summary['status'] = 'processed';
    $empty_seen = 0;

    for ($i = 0; $i < 20; $i++) {
        $result = cw_tg_process_file_queue(25);
        $summary['batches']++;
        $summary['processed'] += intval($result['processed'] ?? 0);
        $summary['errors'] += intval($result['errors'] ?? 0);
        $summary['remaining'] = intval($result['remaining'] ?? 0);

        if (($result['status'] ?? '') === 'locked') {
            $summary['status'] = 'locked';
            break;
        }

        if (intval($result['processed'] ?? 0) <= 0 && intval($result['remaining'] ?? 0) <= 0) {
            $empty_seen++;
            if ($empty_seen >= 2) {
                if ($summary['processed'] <= 0 && $summary['errors'] <= 0) {
                    $summary['status'] = 'empty';
                }
                break;
            }
            usleep(250000);
            continue;
        }

        $empty_seen = 0;
        if (intval($result['remaining'] ?? 0) <= 0) {
            usleep(250000);
        }
    }

    $result = $summary;
}

cw_tg_worker_log('worker_finished ' . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
    echo wp_json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

exit;
