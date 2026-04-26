<?php
// Ultra-light Telegram webhook endpoint.
// This file must answer Telegram without loading WordPress. Heavy work is written
// to a small file queue and processed by tg-worker.php in a background PHP process.

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

function cw_tg_direct_enqueue_update(array $update, string $header_secret, string $raw): bool {
    $dir = cw_tg_direct_queue_dir();
    if ($dir === '' || !is_dir($dir) || !is_writable($dir)) {
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
        return false;
    }

    $queue = $dir . '/queue.jsonl';
    $fp = @fopen($queue, 'ab');
    if (!$fp) {
        return false;
    }

    $ok = false;
    if (@flock($fp, LOCK_EX)) {
        $ok = @fwrite($fp, $line . "\n") !== false;
        @fflush($fp);
        @flock($fp, LOCK_UN);
    }

    @fclose($fp);
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

function cw_tg_direct_spawn_worker(): void {
    $worker = __DIR__ . '/tg-worker.php';
    if (!is_file($worker)) {
        return;
    }

    $php = cw_tg_direct_php_binary();
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' > /dev/null 2>&1 &';

    if (function_exists('exec')) {
        @exec($cmd);
        return;
    }

    if (function_exists('popen')) {
        $p = @popen($cmd, 'r');
        if (is_resource($p)) {
            @pclose($p);
        }
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

if ($queued) {
    cw_tg_direct_spawn_worker();
    cw_tg_direct_send_response(cw_tg_direct_callback_payload($update, 'Команда принята'));
    exit;
}

cw_tg_direct_send_response(cw_tg_direct_callback_payload($update, 'Очередь Telegram недоступна', true));
exit;
