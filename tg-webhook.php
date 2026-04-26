<?php
// Ultra-light Telegram webhook endpoint.
// This file must answer Telegram before any heavy work. It writes the update to a
// small file queue and only then starts the worker after the HTTP response is closed.

function cw_tg_direct_json_encode($payload): string {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? $json : '{"status":"ok"}';
}

function cw_tg_direct_callback_payload(array $update, string $text = 'Команда принята', bool $show_alert = false): array {
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
    // tg-webhook.php is in wp-content/plugins/chat-widget/.
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

    $php = cw_tg_direct_php_binary();
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' > /dev/null 2>&1 &';

    if (function_exists('exec')) {
        @exec($cmd, $output, $code);
        cw_tg_direct_log('worker_exec_started code=' . (string) $code);
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

    cw_tg_direct_log('worker_spawn_unavailable');
    return false;
}

function cw_tg_direct_process_queue_inline_if_possible(): void {
    // Fallback for shared hosting where exec/popen are disabled. This runs only
    // after fastcgi_finish_request(), so Telegram should already have the answer.
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
$queued = cw_tg_direct_enqueue_update($update, $header_secret, $raw);

$response_payload = $queued
    ? cw_tg_direct_callback_payload($update, 'Команда принята')
    : cw_tg_direct_callback_payload($update, 'Очередь Telegram недоступна', true);

cw_tg_direct_send_response($response_payload, 200);
$client_finished = cw_tg_direct_finish_client();

if ($queued) {
    $spawned = cw_tg_direct_spawn_worker();
    if (!$spawned && $client_finished) {
        cw_tg_direct_process_queue_inline_if_possible();
    }
}

exit;
