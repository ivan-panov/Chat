<?php
// Ultra-light Telegram webhook endpoint.
// It writes incoming updates to a small file queue, answers Telegram immediately,
// and starts the worker only when no worker is already active.

function cw_tg_direct_json_encode($payload): string {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? $json : '{"status":"ok"}';
}

function cw_tg_direct_text(string $key): string {
    $texts = [
        'accepted' => '&#1050;&#1086;&#1084;&#1072;&#1085;&#1076;&#1072; &#1087;&#1088;&#1080;&#1085;&#1103;&#1090;&#1072;',
        'already' => '&#1050;&#1086;&#1084;&#1072;&#1085;&#1076;&#1072; &#1091;&#1078;&#1077; &#1087;&#1088;&#1080;&#1085;&#1103;&#1090;&#1072;',
        'queue_unavailable' => '&#1054;&#1095;&#1077;&#1088;&#1077;&#1076;&#1100; Telegram &#1085;&#1077;&#1076;&#1086;&#1089;&#1090;&#1091;&#1087;&#1085;&#1072;',
    ];

    return html_entity_decode((string) ($texts[$key] ?? $texts['accepted']), ENT_QUOTES, 'UTF-8');
}

function cw_tg_direct_callback_payload(array $update, string $text = '', bool $show_alert = false): array {
    if ($text === '') {
        $text = cw_tg_direct_text('accepted');
    }
    $callback_id = '';
    if (!empty($update['callback_query']) && is_array($update['callback_query'])) {
        $callback_id = (string) ($update['callback_query']['id'] ?? '');
    }

    if ($callback_id !== '') {
        return [
            'method'            => 'answerCallbackQuery',
            'callback_query_id' => $callback_id,
            'text'              => $text,
            'show_alert'        => $show_alert,
        ];
    }

    return ['status' => 'accepted'];
}

function cw_tg_direct_send_response(array $payload, int $status = 200): void {
    $json = cw_tg_direct_json_encode($payload);

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Connection: close');
    header('Content-Length: ' . strlen($json));

    echo $json;
    @flush();
}

function cw_tg_direct_finish_client(): bool {
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
        return true;
    }

    @flush();
    return false;
}

function cw_tg_direct_queue_dir(): string {
    $wp_content = dirname(__DIR__, 2);
    $uploads = $wp_content . '/uploads';

    if (is_dir($uploads) || @mkdir($uploads, 0755, true)) {
        $dir = $uploads . '/cw-tg-queue';
        if (is_dir($dir) || @mkdir($dir, 0755, true)) {
            @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
            @file_put_contents($dir . '/.htaccess', "Deny from all\n");
            return $dir;
        }
    }

    $fallback = rtrim(sys_get_temp_dir(), '/\\') . '/cw-tg-queue-' . md5(__DIR__);
    if (!is_dir($fallback)) {
        @mkdir($fallback, 0755, true);
    }

    return $fallback;
}

function cw_tg_direct_log(string $message): void {
    $dir = cw_tg_direct_queue_dir();
    if ($dir !== '' && is_dir($dir) && is_writable($dir)) {
        @file_put_contents($dir . '/webhook.log', '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n", FILE_APPEND | LOCK_EX);
    }
}

function cw_tg_direct_worker_marker_file(): string {
    return rtrim(cw_tg_direct_queue_dir(), '/\\') . '/worker-active.lock';
}

function cw_tg_direct_worker_marker_is_active(int $ttl = 45): bool {
    $file = cw_tg_direct_worker_marker_file();
    if (!is_file($file)) {
        return false;
    }

    $age = time() - (int) @filemtime($file);
    if ($age >= 0 && $age <= $ttl) {
        return true;
    }

    @unlink($file);
    return false;
}

function cw_tg_direct_mark_worker_active(): void {
    @file_put_contents(cw_tg_direct_worker_marker_file(), (string) time(), LOCK_EX);
}

function cw_tg_direct_clear_worker_marker(): void {
    @unlink(cw_tg_direct_worker_marker_file());
}

function cw_tg_direct_cleanup_button_locks(): void {
    if (mt_rand(1, 20) !== 1) {
        return;
    }

    $dir = cw_tg_direct_queue_dir();
    foreach ((array) glob(rtrim($dir, '/\\') . '/button-*.lock') as $file) {
        if (is_file($file) && time() - (int) @filemtime($file) > 60) {
            @unlink($file);
        }
    }
}

function cw_tg_direct_is_duplicate_button(array $update, int $ttl = 3): bool {
    if (empty($update['callback_query']) || !is_array($update['callback_query'])) {
        return false;
    }

    $callback = $update['callback_query'];
    $data = (string) ($callback['data'] ?? '');
    if ($data === '') {
        return false;
    }

    $from_id = (string) ($callback['from']['id'] ?? '');
    $key = md5($from_id . '|' . $data);
    $file = rtrim(cw_tg_direct_queue_dir(), '/\\') . '/button-' . $key . '.lock';

    cw_tg_direct_cleanup_button_locks();

    if (is_file($file)) {
        $age = time() - (int) @filemtime($file);
        if ($age >= 0 && $age <= $ttl) {
            return true;
        }
        @unlink($file);
    }

    @file_put_contents($file, (string) time(), LOCK_EX);
    return false;
}

function cw_tg_direct_enqueue_update(array $update, string $header_secret, string $raw): bool {
    $dir = cw_tg_direct_queue_dir();
    if ($dir === '' || !is_dir($dir) || !is_writable($dir)) {
        cw_tg_direct_log('queue_dir_not_writable');
        return false;
    }

    $job = [
        'received_at'   => time(),
        'header_secret' => $header_secret,
        'update'        => $update,
        'raw_len'       => strlen($raw),
    ];

    $line = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($line) || $line === '') {
        cw_tg_direct_log('json_encode_failed');
        return false;
    }

    $queue = $dir . '/queue.jsonl';
    $fp = @fopen($queue, 'ab');
    if (!$fp) {
        cw_tg_direct_log('queue_open_failed');
        return false;
    }

    $ok = false;
    if (@flock($fp, LOCK_EX)) {
        $ok = @fwrite($fp, $line . "\n") !== false;
        @fflush($fp);
        @flock($fp, LOCK_UN);
    }

    @fclose($fp);

    if (!$ok) {
        cw_tg_direct_log('queue_write_failed');
    }

    return $ok;
}

function cw_tg_direct_php_binary(): string {
    $candidates = [];

    if (defined('PHP_BINDIR')) {
        $candidates[] = rtrim(PHP_BINDIR, '/\\') . '/php';
    }

    if (defined('PHP_BINARY')) {
        $candidates[] = PHP_BINARY;
    }

    $candidates[] = 'php';

    foreach ($candidates as $candidate) {
        $base = strtolower(basename((string) $candidate));
        if ($candidate === 'php') {
            return 'php';
        }
        if ($base === 'php' || strpos($base, 'php-cli') !== false) {
            return (string) $candidate;
        }
    }

    return 'php';
}

function cw_tg_direct_spawn_worker(): bool {
    $worker = __DIR__ . '/tg-worker.php';
    if (!is_file($worker)) {
        cw_tg_direct_log('worker_missing');
        return false;
    }

    if (cw_tg_direct_worker_marker_is_active()) {
        cw_tg_direct_log('worker_already_active_skip_spawn');
        return true;
    }

    cw_tg_direct_mark_worker_active();

    $php = cw_tg_direct_php_binary();
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' > /dev/null 2>&1 &';

    if (function_exists('exec')) {
        @exec($cmd, $output, $code);
        cw_tg_direct_log('worker_exec_started code=' . (string) $code);
        if ((int) $code !== 0) {
            cw_tg_direct_clear_worker_marker();
            return false;
        }
        return true;
    }

    if (function_exists('popen')) {
        $p = @popen($cmd, 'r');
        if (is_resource($p)) {
            @pclose($p);
            cw_tg_direct_log('worker_popen_started');
            return true;
        }
    }

    cw_tg_direct_clear_worker_marker();
    cw_tg_direct_log('worker_spawn_unavailable');
    return false;
}

function cw_tg_direct_process_queue_inline_if_possible(): void {
    $wp_load = __DIR__ . '/../../../wp-load.php';
    if (!is_file($wp_load)) {
        cw_tg_direct_log('inline_wp_load_missing');
        return;
    }

    try {
        require_once $wp_load;
        if (function_exists('cw_tg_process_file_queue')) {
            $result = cw_tg_process_file_queue(25);
            cw_tg_direct_log('inline_processed ' . cw_tg_direct_json_encode($result));
        } else {
            cw_tg_direct_log('inline_processor_missing');
        }
    } catch (Throwable $e) {
        cw_tg_direct_log('inline_error ' . $e->getMessage());
    } finally {
        cw_tg_direct_clear_worker_marker();
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    cw_tg_direct_send_response(['status' => 'method_not_allowed']);
    exit;
}

$raw = (string) file_get_contents('php://input');
$update = json_decode($raw, true);

if (!is_array($update)) {
    cw_tg_direct_send_response(['status' => 'invalid']);
    exit;
}

$header_secret = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');

if (cw_tg_direct_is_duplicate_button($update, 3)) {
    cw_tg_direct_log('button_duplicate_skipped');
    cw_tg_direct_send_response(cw_tg_direct_callback_payload($update, cw_tg_direct_text('already')), 200);
    cw_tg_direct_finish_client();
    exit;
}

$queued = cw_tg_direct_enqueue_update($update, $header_secret, $raw);

$response_payload = $queued
    ? cw_tg_direct_callback_payload($update, cw_tg_direct_text('accepted'))
    : cw_tg_direct_callback_payload($update, cw_tg_direct_text('queue_unavailable'), true);

cw_tg_direct_send_response($response_payload, 200);
$client_finished = cw_tg_direct_finish_client();

if ($queued) {
    $spawned = cw_tg_direct_spawn_worker();
    if (!$spawned && $client_finished) {
        cw_tg_direct_process_queue_inline_if_possible();
    }
}

exit;
