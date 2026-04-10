<?php
if (!defined('ABSPATH')) exit;

function cw_max_enabled(): bool {
    return (int) get_option('cw_max_enabled', 1) === 1;
}

function cw_max_get_token(): string {
    return trim((string) get_option('cw_max_token', ''));
}

function cw_max_get_admin_user_id(): int {
    return (int) get_option('cw_max_admin_user_id', 0);
}

function cw_max_get_webhook_secret(): string {
    return trim((string) get_option('cw_max_webhook_secret', ''));
}

function cw_max_log_error(string $message): void {
    error_log('CW MAX: ' . $message);
}

function cw_max_api_request(string $path, array $params = [], string $method = 'POST') {
    $token = cw_max_get_token();
    if ($token === '') {
        return new WP_Error('cw_max_no_token', 'MAX Token не заполнен.');
    }

    $method = strtoupper($method);
    $url = 'https://platform-api.max.ru' . $path;

    $headers = [
        'Authorization' => $token,
    ];

    $args = [
        'method'  => $method,
        'timeout' => 25,
        'headers' => $headers,
    ];

    if ($method === 'GET') {
        if (!empty($params)) {
            $url = add_query_arg($params, $url);
        }
    } else {
        $args['headers']['Content-Type'] = 'application/json; charset=utf-8';
        $args['body'] = wp_json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $response = wp_remote_request($url, $args);
    if (is_wp_error($response)) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    $json = json_decode($body, true);

    if ($code < 200 || $code >= 300) {
        return new WP_Error(
            'cw_max_http_error',
            'MAX API вернул ошибку.',
            [
                'http_code' => $code,
                'body'      => $body,
            ]
        );
    }

    if ($body === '') {
        return ['success' => true];
    }

    if (!is_array($json)) {
        return new WP_Error(
            'cw_max_bad_json',
            'MAX API вернул некорректный JSON.',
            [
                'http_code' => $code,
                'body'      => $body,
            ]
        );
    }

    return $json;
}

function cw_max_extract_api_message_id(array $response): string {
    $candidates = [
        $response['message']['message_id'] ?? '',
        $response['message']['id'] ?? '',
        $response['message']['mid'] ?? '',
        $response['message']['uuid'] ?? '',
        $response['message']['body']['message_id'] ?? '',
        $response['message']['body']['id'] ?? '',
        $response['message']['body']['mid'] ?? '',
        $response['message']['body']['uuid'] ?? '',
        $response['body']['message_id'] ?? '',
        $response['body']['id'] ?? '',
        $response['body']['mid'] ?? '',
        $response['body']['uuid'] ?? '',
        $response['data']['message']['message_id'] ?? '',
        $response['data']['message']['id'] ?? '',
        $response['data']['message']['mid'] ?? '',
        $response['data']['message']['uuid'] ?? '',
        $response['data']['message']['body']['message_id'] ?? '',
        $response['data']['message']['body']['id'] ?? '',
        $response['data']['message']['body']['mid'] ?? '',
        $response['data']['message']['body']['uuid'] ?? '',
        $response['result']['message']['message_id'] ?? '',
        $response['result']['message']['id'] ?? '',
        $response['result']['message']['mid'] ?? '',
        $response['result']['message']['uuid'] ?? '',
        $response['result']['message']['body']['message_id'] ?? '',
        $response['result']['message']['body']['id'] ?? '',
        $response['result']['message']['body']['mid'] ?? '',
        $response['result']['message']['body']['uuid'] ?? '',
        $response['message_id'] ?? '',
        $response['id'] ?? '',
        $response['mid'] ?? '',
        $response['uuid'] ?? '',
    ];

    foreach ($candidates as $value) {
        if (is_scalar($value)) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }
    }

    return '';
}


function cw_max_extract_update_message_id(array $update): string {
    $candidates = [
        $update['message']['body']['mid'] ?? '',
        $update['message']['body']['message_id'] ?? '',
        $update['message']['mid'] ?? '',
        $update['message']['message_id'] ?? '',
        $update['message']['id'] ?? '',
        $update['mid'] ?? '',
        $update['message_id'] ?? '',
        $update['id'] ?? '',
    ];

    foreach ($candidates as $value) {
        if (is_scalar($value)) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }
    }

    return '';
}

function cw_max_extract_message_object_from_response(array $response): array {
    $candidates = [
        $response['message'] ?? null,
        $response['messages'][0] ?? null,
        $response['data']['message'] ?? null,
        $response['data']['messages'][0] ?? null,
        $response['result']['message'] ?? null,
        $response['result']['messages'][0] ?? null,
        $response,
    ];

    foreach ($candidates as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }

        if (!empty($candidate['body']) || !empty($candidate['recipient']) || !empty($candidate['sender'])) {
            return $candidate;
        }
    }

    return [];
}

function cw_max_fetch_message_by_id(string $message_id) {
    $message_id = trim($message_id);
    if ($message_id === '') {
        return new WP_Error('cw_max_message_id_missing', 'Пустой ID сообщения MAX.');
    }

    $response = cw_max_api_request('/messages', [
        'message_ids' => $message_id,
    ], 'GET');

    if (is_wp_error($response)) {
        return $response;
    }

    $message = cw_max_extract_message_object_from_response($response);
    if (!$message) {
        return new WP_Error(
            'cw_max_message_fetch_invalid',
            'MAX вернул пустое сообщение при повторном запросе вложений.',
            ['body' => wp_json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
        );
    }

    return $message;
}

function cw_max_attachment_has_downloadable_url(array $attachment): bool {
    return cw_max_attachment_first_scalar(cw_max_attachment_url_candidates($attachment)) !== '';
}

function cw_max_update_with_full_message_data(array $update): array {
    $message_id = cw_max_extract_update_message_id($update);
    if ($message_id === '') {
        return $update;
    }

    $fetched = cw_max_fetch_message_by_id($message_id);
    if (is_wp_error($fetched)) {
        $extra = $fetched->get_error_data();
        $body_text = is_array($extra) && !empty($extra['body']) ? ' | body: ' . $extra['body'] : '';
        cw_max_log_error('Fetch full MAX message failed for attachments: ' . $fetched->get_error_message() . $body_text);
        return $update;
    }

    $update['message'] = $fetched;
    return $update;
}

function cw_max_send_to_user_ex(int $user_id, string $text, array $options = []) {
    if ($user_id <= 0) {
        return new WP_Error('cw_max_bad_user_id', 'Некорректный user_id для отправки в MAX.');
    }

    $text = trim($text);
    if ($text === '' && empty($options['attachments'])) {
        return new WP_Error('cw_max_empty_message', 'Пустое сообщение MAX не может быть отправлено.');
    }

    if (mb_strlen($text) > 3900) {
        $text = mb_substr($text, 0, 3900) . '...';
    }

    $payload = [
        'text'   => $text,
        'notify' => array_key_exists('notify', $options) ? !empty($options['notify']) : true,
    ];

    if (!empty($options['attachments']) && is_array($options['attachments'])) {
        $payload['attachments'] = array_values($options['attachments']);
    }

    if (!empty($options['format'])) {
        $payload['format'] = (string) $options['format'];
    }

    if (!empty($options['link']) && is_array($options['link'])) {
        $payload['link'] = $options['link'];
    }

    $path = '/messages?user_id=' . rawurlencode((string) $user_id);

    if (array_key_exists('disable_link_preview', $options)) {
        $path .= '&disable_link_preview=' . (!empty($options['disable_link_preview']) ? 'true' : 'false');
    }

    return cw_max_api_request($path, $payload, 'POST');
}

function cw_max_send_to_user(int $user_id, string $text, array $options = []): bool {
    $response = cw_max_send_to_user_ex($user_id, $text, $options);

    if (is_wp_error($response)) {
        $extra = $response->get_error_data();
        $body_text = is_array($extra) && !empty($extra['body']) ? ' | body: ' . $extra['body'] : '';
        cw_max_log_error($response->get_error_message() . $body_text);
        return false;
    }

    return true;
}

function cw_max_is_attachment_processing_error($error): bool {
    if (!is_wp_error($error)) {
        return false;
    }

    $data = $error->get_error_data();
    $body = is_array($data) ? (string) ($data['body'] ?? '') : '';

    return stripos($body, 'attachment.not.ready') !== false
        || stripos($body, 'not.processed') !== false
        || stripos($body, 'file.not.processed') !== false;
}

function cw_max_send_to_user_ex_retry(int $user_id, string $text, array $options = []) {
    $attempts = max(1, (int) ($options['retry_attempts'] ?? 1));
    $delay = (float) ($options['retry_delay'] ?? 0.8);
    unset($options['retry_attempts'], $options['retry_delay']);

    $last_response = null;

    for ($i = 1; $i <= $attempts; $i++) {
        $last_response = cw_max_send_to_user_ex($user_id, $text, $options);

        if (!is_wp_error($last_response)) {
            return $last_response;
        }

        if ($i >= $attempts || !cw_max_is_attachment_processing_error($last_response)) {
            return $last_response;
        }

        usleep((int) (max(0.2, $delay) * 1000000));
        $delay *= 1.8;
    }

    return $last_response;
}

function cw_max_has_media_attachments(array $attachments): bool {
    foreach ($attachments as $attachment) {
        $type = strtolower((string) ($attachment['type'] ?? ''));
        if (in_array($type, ['image', 'file', 'video', 'audio'], true)) {
            return true;
        }
    }

    return false;
}

function cw_max_decode_filename_value(string $value): string {
    $value = trim((string) $value, " \t\n\r\0\x0B\"'");
    if ($value === '') {
        return '';
    }

    if (strpos($value, '%') !== false) {
        $decoded = rawurldecode($value);
        if (is_string($decoded) && $decoded !== '') {
            $value = $decoded;
        }
    }

    if (stripos($value, '=?') !== false && substr($value, -2) === '?=') {
        if (function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if (is_string($decoded) && trim($decoded) !== '') {
                $value = $decoded;
            }
        } elseif (function_exists('mb_decode_mimeheader')) {
            $decoded = @mb_decode_mimeheader($value);
            if (is_string($decoded) && trim($decoded) !== '') {
                $value = $decoded;
            }
        }
    }

    if (function_exists('mb_check_encoding') && !mb_check_encoding($value, 'UTF-8')) {
        foreach (['Windows-1251', 'CP1251', 'KOI8-R', 'ISO-8859-1'] as $charset) {
            $converted = @mb_convert_encoding($value, 'UTF-8', $charset);
            if (is_string($converted) && $converted !== '' && mb_check_encoding($converted, 'UTF-8')) {
                $value = $converted;
                break;
            }
        }
    }

    $value = str_replace(["\\", "/"], ' ', $value);
    $value = preg_replace('/[\r\n\t]+/u', ' ', $value);
    $value = preg_replace('/\s+/u', ' ', (string) $value);
    $value = trim((string) $value);

    return $value !== '' ? trim(wp_basename($value)) : '';
}

function cw_max_normalize_filename(string $filename, string $fallback = 'file.bin'): string {
    $filename = cw_max_decode_filename_value($filename);
    if ($filename === '') {
        $filename = $fallback;
    }

    if (function_exists('cw_transliterate_filename')) {
        $converted = cw_transliterate_filename($filename);
        if (is_string($converted) && trim($converted) !== '') {
            return $converted;
        }
    }

    $sanitized = sanitize_file_name($filename);
    return $sanitized !== '' ? $sanitized : sanitize_file_name($fallback);
}

function cw_max_prepare_display_name(string $filename, string $fallback = 'Файл'): string {
    $filename = cw_max_decode_filename_value($filename);
    if ($filename === '') {
        $filename = $fallback;
    }

    if (function_exists('cw_prepare_display_filename')) {
        $display = cw_prepare_display_filename($filename);
        if (is_string($display) && trim($display) !== '') {
            return $display;
        }
    }

    return trim(wp_basename($filename)) !== '' ? trim(wp_basename($filename)) : $fallback;
}

function cw_max_parse_site_media_message(string $message): array {
    $message = trim($message);

    if (stripos($message, '[image]') === 0) {
        $url = trim((string) mb_substr($message, 7));
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $name = $path !== '' ? urldecode(wp_basename($path)) : 'image';

        return [
            'kind' => 'image',
            'url'  => $url,
            'name' => cw_max_prepare_display_name($name, 'image'),
        ];
    }

    if (stripos($message, '[file]') === 0) {
        $payload = trim((string) mb_substr($message, 6));
        $url = $payload;
        $name = 'Файл';

        $pos = strpos($payload, '|');
        if ($pos !== false) {
            $url = trim((string) substr($payload, 0, $pos));
            $name = trim((string) substr($payload, $pos + 1)) ?: 'Файл';
        }

        return [
            'kind' => 'file',
            'url'  => $url,
            'name' => cw_max_prepare_display_name($name, 'Файл'),
        ];
    }

    return [
        'kind' => '',
        'url'  => '',
        'name' => '',
    ];
}

function cw_max_local_path_from_url(string $url): string {
    $url = trim($url);
    if ($url === '') return '';

    $uploads = wp_upload_dir();
    $baseurl = rtrim((string) ($uploads['baseurl'] ?? ''), '/');
    $basedir = rtrim((string) ($uploads['basedir'] ?? ''), '/');

    if ($baseurl === '' || $basedir === '') {
        return '';
    }

    if (strpos($url, $baseurl . '/') !== 0 && $url !== $baseurl) {
        return '';
    }

    $relative = ltrim((string) substr($url, strlen($baseurl)), '/');
    if ($relative === '') {
        return '';
    }

    $path = wp_normalize_path($basedir . '/' . $relative);
    return file_exists($path) ? $path : '';
}

function cw_max_download_url_to_temp(string $url, string $preferred_name = '') {
    $url = trim($url);
    if ($url === '') {
        return new WP_Error('cw_max_empty_download_url', 'Пустой URL для временной загрузки файла.');
    }

    $response = wp_remote_get($url, [
        'timeout'     => 45,
        'redirection' => 3,
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);

    if ($code < 200 || $code >= 300 || $body === '') {
        return new WP_Error('cw_max_temp_download_failed', 'Не удалось скачать файл по URL для MAX.');
    }

    $path_part = (string) wp_parse_url($url, PHP_URL_PATH);
    $name_from_url = $path_part !== '' ? urldecode(wp_basename($path_part)) : '';
    $filename = cw_max_normalize_filename($preferred_name !== '' ? $preferred_name : $name_from_url, 'cw-max-file.bin');
    $tmp = wp_tempnam($filename);

    if (!$tmp) {
        return new WP_Error('cw_max_temp_file_failed', 'Не удалось создать временный файл для MAX.');
    }

    if (file_put_contents($tmp, $body) === false) {
        @unlink($tmp);
        return new WP_Error('cw_max_temp_write_failed', 'Не удалось записать временный файл для MAX.');
    }

    $mime = (string) wp_remote_retrieve_header($response, 'content-type');
    if (($pos = strpos($mime, ';')) !== false) {
        $mime = trim((string) substr($mime, 0, $pos));
    }

    return [
        'path'    => $tmp,
        'name'    => $filename,
        'mime'    => $mime,
        'cleanup' => true,
    ];
}

function cw_max_resolve_site_media_source(array $parsed) {
    $url  = trim((string) ($parsed['url'] ?? ''));
    $name = trim((string) ($parsed['name'] ?? ''));

    if ($url === '') {
        return new WP_Error('cw_max_media_url_missing', 'У сообщения нет URL файла для отправки в MAX.');
    }

    $local_path = cw_max_local_path_from_url($url);
    if ($local_path !== '') {
        $mime = (string) @mime_content_type($local_path);

        return [
            'path'    => $local_path,
            'name'    => cw_max_normalize_filename($name !== '' ? $name : wp_basename($local_path), 'cw-max-file.bin'),
            'mime'    => $mime,
            'cleanup' => false,
        ];
    }

    return cw_max_download_url_to_temp($url, $name);
}

function cw_max_request_upload_slot(string $type) {
    $token = cw_max_get_token();
    if ($token === '') {
        return new WP_Error('cw_max_no_token', 'MAX Token не заполнен.');
    }

    $url = 'https://platform-api.max.ru/uploads?type=' . rawurlencode($type);
    $response = wp_remote_request($url, [
        'method'  => 'POST',
        'timeout' => 25,
        'headers' => [
            'Authorization' => $token,
        ],
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    $json = json_decode($body, true);

    if ($code < 200 || $code >= 300 || !is_array($json)) {
        return new WP_Error('cw_max_upload_slot_error', 'MAX не вернул ссылку на загрузку файла.', [
            'http_code' => $code,
            'body'      => $body,
        ]);
    }

    return $json;
}

function cw_max_upload_binary_to_url(string $upload_url, string $local_path, string $filename, string $mime = '') {
    $token = cw_max_get_token();
    if ($token === '') {
        return new WP_Error('cw_max_no_token', 'MAX Token не заполнен.');
    }

    if ($upload_url === '' || !file_exists($local_path)) {
        return new WP_Error('cw_max_upload_source_invalid', 'Некорректный источник файла для загрузки в MAX.');
    }

    $binary = file_get_contents($local_path);
    if ($binary === false) {
        return new WP_Error('cw_max_upload_read_failed', 'Не удалось прочитать файл перед отправкой в MAX.');
    }

    $mime = trim($mime);
    if ($mime === '') {
        $mime = (string) @mime_content_type($local_path);
    }
    if ($mime === '') {
        $mime = 'application/octet-stream';
    }

    $filename = cw_max_normalize_filename($filename !== '' ? $filename : wp_basename($local_path), 'cw-max-file.bin');
    $boundary = '----cwmax' . wp_generate_password(18, false, false);

    $eol = "\r\n";
    $body = '';
    $body .= '--' . $boundary . $eol;
    $body .= 'Content-Disposition: form-data; name="data"; filename="' . str_replace('"', '', $filename) . '"' . $eol;
    $body .= 'Content-Type: ' . $mime . $eol . $eol;
    $body .= $binary . $eol;
    $body .= '--' . $boundary . '--' . $eol;

    $response = wp_remote_post($upload_url, [
        'timeout'     => 60,
        'redirection' => 3,
        'headers'     => [
            'Authorization' => $token,
            'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
        ],
        'body'        => $body,
        'data_format' => 'body',
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $resp_body = (string) wp_remote_retrieve_body($response);
    $json = json_decode($resp_body, true);

    if ($code < 200 || $code >= 300 || !is_array($json)) {
        return new WP_Error('cw_max_upload_binary_error', 'MAX не принял бинарный файл.', [
            'http_code' => $code,
            'body'      => $resp_body,
        ]);
    }

    return $json;
}

function cw_max_build_uploaded_attachment_from_site_message(string $message) {
    $parsed = cw_max_parse_site_media_message($message);
    if (($parsed['kind'] ?? '') === '') {
        return new WP_Error('cw_max_not_media_message', 'Сообщение не содержит файла для MAX.');
    }

    $source = cw_max_resolve_site_media_source($parsed);
    if (is_wp_error($source)) {
        return $source;
    }

    $upload_type = ($parsed['kind'] === 'image') ? 'image' : 'file';
    $slot = cw_max_request_upload_slot($upload_type);

    if (is_wp_error($slot)) {
        if (!empty($source['cleanup'])) {
            @unlink((string) $source['path']);
        }
        return $slot;
    }

    $upload_url = trim((string) ($slot['url'] ?? ''));
    if ($upload_url === '') {
        if (!empty($source['cleanup'])) {
            @unlink((string) $source['path']);
        }
        return new WP_Error('cw_max_upload_url_missing', 'MAX не вернул URL для загрузки файла.');
    }

    $upload_result = cw_max_upload_binary_to_url($upload_url, (string) $source['path'], (string) $source['name'], (string) ($source['mime'] ?? ''));

    if (!empty($source['cleanup'])) {
        @unlink((string) $source['path']);
    }

    if (is_wp_error($upload_result)) {
        return $upload_result;
    }

    if (!empty($slot['token']) && empty($upload_result['token'])) {
        $upload_result['token'] = $slot['token'];
    }

    return [
        'type'    => $upload_type,
        'payload' => $upload_result,
    ];
}

function cw_max_attachment_url_candidates(array $attachment): array {
    $payload = isset($attachment['payload']) && is_array($attachment['payload']) ? $attachment['payload'] : [];

    $candidates = [
        $payload['url'] ?? '',
        $payload['download_url'] ?? '',
        $payload['file_url'] ?? '',
        $payload['src'] ?? '',
        $payload['href'] ?? '',
        $payload['link'] ?? '',
        $payload['image']['url'] ?? '',
        $payload['image']['download_url'] ?? '',
        $payload['image']['src'] ?? '',
        $payload['file']['url'] ?? '',
        $payload['file']['download_url'] ?? '',
        $payload['file']['src'] ?? '',
        $payload['media']['url'] ?? '',
        $payload['media']['download_url'] ?? '',
        $attachment['url'] ?? '',
    ];

    if (!empty($payload['images']) && is_array($payload['images'])) {
        foreach ($payload['images'] as $item) {
            if (is_array($item)) {
                $candidates[] = $item['url'] ?? '';
                $candidates[] = $item['download_url'] ?? '';
            }
        }
    }

    return $candidates;
}

function cw_max_attachment_name_candidates(array $attachment): array {
    $payload = isset($attachment['payload']) && is_array($attachment['payload']) ? $attachment['payload'] : [];

    return [
        $payload['file_name'] ?? '',
        $payload['filename'] ?? '',
        $payload['name'] ?? '',
        $payload['title'] ?? '',
        $payload['file']['name'] ?? '',
        $payload['file']['file_name'] ?? '',
        $payload['image']['name'] ?? '',
        $attachment['name'] ?? '',
        $attachment['title'] ?? '',
    ];
}

function cw_max_attachment_first_scalar(array $candidates): string {
    foreach ($candidates as $value) {
        if (is_scalar($value)) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }
    }

    return '';
}

function cw_max_attachment_is_image(array $attachment, string $filename = '', string $mime = ''): bool {
    $type = strtolower((string) ($attachment['type'] ?? ''));
    if ($type === 'image') {
        return true;
    }

    $mime = strtolower($mime);
    if ($mime !== '' && strpos($mime, 'image/') === 0) {
        return true;
    }

    $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'heic'], true);
}


function cw_chat_widget_allowed_mimes(): array {
    return [
        'jpg|jpeg|jpe' => 'image/jpeg',
        'png'          => 'image/png',
        'gif'          => 'image/gif',
        'webp'         => 'image/webp',
        'pdf'          => 'application/pdf',
        'doc'          => 'application/msword',
        'docx'         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'          => 'application/vnd.ms-excel',
        'xlsx'         => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'txt'          => 'text/plain',
        'zip'          => 'application/zip',
        'rar'          => 'application/x-rar-compressed',
    ];
}

function cw_chat_widget_ext_from_mime(string $mime, array $attachment = []): string {
    $mime = strtolower(trim((string) $mime));
    if (($pos = strpos($mime, ';')) !== false) {
        $mime = trim((string) substr($mime, 0, $pos));
    }

    $map = [
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'text/plain' => 'txt',
        'application/zip' => 'zip',
        'application/x-rar-compressed' => 'rar',
        'application/vnd.rar' => 'rar',
    ];

    if (isset($map[$mime])) {
        return $map[$mime];
    }

    $type = strtolower((string) ($attachment['type'] ?? ''));
    if ($type === 'image') {
        return 'jpg';
    }

    return '';
}

function cw_max_extract_filename_from_content_disposition(string $header): string {
    $header = trim($header);
    if ($header === '') {
        return '';
    }

    if (preg_match("/filename\\*=UTF-8''([^;]+)/i", $header, $m)) {
        return cw_max_decode_filename_value((string) $m[1]);
    }

    if (preg_match('/filename\\*=([^;]+)/i', $header, $m)) {
        $raw = trim((string) $m[1], " \t\n\r\0\x0B\"'");
        $parts = explode("''", $raw, 2);
        $value = count($parts) === 2 ? $parts[1] : $raw;
        return cw_max_decode_filename_value($value);
    }

    if (preg_match('/filename="([^"]+)"/i', $header, $m)) {
        return cw_max_decode_filename_value((string) $m[1]);
    }

    if (preg_match('/filename=([^;]+)/i', $header, $m)) {
        return cw_max_decode_filename_value((string) $m[1]);
    }

    return '';
}

function cw_max_detect_file_signature(string $body): array {
    if ($body === '') {
        return ['ext' => '', 'mime' => ''];
    }

    if (strncmp($body, "%PDF-", 5) === 0) {
        return ['ext' => 'pdf', 'mime' => 'application/pdf'];
    }

    if (substr($body, 0, 3) === "ÿØÿ") {
        return ['ext' => 'jpg', 'mime' => 'image/jpeg'];
    }

    if (substr($body, 0, 8) === "PNG

") {
        return ['ext' => 'png', 'mime' => 'image/png'];
    }

    if (substr($body, 0, 6) === 'GIF87a' || substr($body, 0, 6) === 'GIF89a') {
        return ['ext' => 'gif', 'mime' => 'image/gif'];
    }

    if (substr($body, 0, 4) === 'RIFF' && substr($body, 8, 4) === 'WEBP') {
        return ['ext' => 'webp', 'mime' => 'image/webp'];
    }

    if (substr($body, 0, 4) === "PK" || substr($body, 0, 4) === "PK" || substr($body, 0, 4) === "PK") {
        return ['ext' => 'zip', 'mime' => 'application/zip'];
    }

    if (substr($body, 0, 7) === "Rar!") {
        return ['ext' => 'rar', 'mime' => 'application/x-rar-compressed'];
    }

    return ['ext' => '', 'mime' => ''];
}

function cw_max_download_attachment_to_chat_widget(array $attachment) {
    $url = cw_max_attachment_first_scalar(cw_max_attachment_url_candidates($attachment));
    if ($url === '') {
        return new WP_Error('cw_max_attachment_url_missing', 'MAX не прислал URL вложения.');
    }

    $preferred_name = cw_max_decode_filename_value(
        cw_max_attachment_first_scalar(cw_max_attachment_name_candidates($attachment))
    );

    $response = wp_remote_get($url, [
        'timeout'     => 60,
        'redirection' => 3,
        'headers'     => [
            'Authorization' => cw_max_get_token(),
        ],
    ]);

    if (is_wp_error($response)) {
        $response = wp_remote_get($url, [
            'timeout'     => 60,
            'redirection' => 3,
        ]);
    }

    if (is_wp_error($response)) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);

    if ($code < 200 || $code >= 300 || $body === '') {
        return new WP_Error('cw_max_attachment_download_failed', 'Не удалось скачать вложение из MAX.');
    }

    $mime = (string) wp_remote_retrieve_header($response, 'content-type');
    if (($pos = strpos($mime, ';')) !== false) {
        $mime = trim((string) substr($mime, 0, $pos));
    }

    $content_disposition = (string) wp_remote_retrieve_header($response, 'content-disposition');
    $name_from_disposition = cw_max_extract_filename_from_content_disposition($content_disposition);

    $path_part = (string) wp_parse_url($url, PHP_URL_PATH);
    $fallback_name = $path_part !== '' ? cw_max_decode_filename_value(urldecode(wp_basename($path_part))) : '';
    if (strtolower($fallback_name) === 'getfile') {
        $fallback_name = '';
    }

    $signature = cw_max_detect_file_signature($body);
    $signature_ext = (string) ($signature['ext'] ?? '');
    $signature_mime = (string) ($signature['mime'] ?? '');

    if ($mime === '' || strtolower($mime) === 'application/octet-stream') {
        if ($signature_mime !== '') {
            $mime = $signature_mime;
        }
    }

    $display_name = cw_max_prepare_display_name(
        $preferred_name !== ''
            ? $preferred_name
            : ($name_from_disposition !== '' ? $name_from_disposition : $fallback_name),
        'Файл'
    );

    $ext = strtolower((string) pathinfo($display_name, PATHINFO_EXTENSION));
    if ($ext === '') {
        $guessed_ext = cw_chat_widget_ext_from_mime($mime, $attachment);
        if ($guessed_ext === '' && $signature_ext !== '') {
            $guessed_ext = $signature_ext;
        }
        if ($guessed_ext !== '') {
            $display_name .= '.' . $guessed_ext;
            $ext = $guessed_ext;
        }
    }

    $fallback_ext = cw_chat_widget_ext_from_mime($mime, $attachment);
    if ($fallback_ext === '' && $signature_ext !== '') {
        $fallback_ext = $signature_ext;
    }
    if ($fallback_ext === '') {
        $fallback_ext = 'bin';
    }

    $save_name = cw_max_normalize_filename(
        $display_name,
        'cw-max-file.' . $fallback_ext
    );

    $upload_mimes_filter = static function ($mimes) {
        return array_merge($mimes, cw_chat_widget_allowed_mimes());
    };

    add_filter('upload_dir', 'cw_chat_widget_upload_dir');
    add_filter('upload_mimes', $upload_mimes_filter);

    $uploaded = wp_upload_bits($save_name, null, $body);

    remove_filter('upload_mimes', $upload_mimes_filter);
    remove_filter('upload_dir', 'cw_chat_widget_upload_dir');

    if (!is_array($uploaded) || !empty($uploaded['error'])) {
        cw_max_log_error(
            'Attachment save failed'
            . ' | url=' . $url
            . ' | preferred_name=' . $preferred_name
            . ' | display_name=' . $display_name
            . ' | save_name=' . $save_name
            . ' | mime=' . $mime
            . ' | content_disposition=' . $content_disposition
            . ' | signature_ext=' . $signature_ext
            . ' | error=' . (is_array($uploaded) ? (string) ($uploaded['error'] ?? 'upload_error') : 'upload_error')
        );

        return new WP_Error(
            'cw_max_attachment_save_failed',
            is_array($uploaded) ? (string) ($uploaded['error'] ?? 'upload_error') : 'upload_error'
        );
    }

    $saved_url = esc_url_raw((string) ($uploaded['url'] ?? ''));
    if ($saved_url === '') {
        return new WP_Error('cw_max_attachment_save_no_url', 'Не удалось получить URL сохранённого вложения MAX.');
    }

    $kind = cw_max_attachment_is_image($attachment, $display_name, $mime) ? 'image' : 'file';

    return [
        'kind' => $kind,
        'url'  => $saved_url,
        'name' => $display_name,
        'mime' => $mime,
    ];
}

function cw_max_extract_message_text_from_update(array $update): string {
    return trim((string) (
        $update['message']['body']['text']
        ?? $update['message']['text']
        ?? $update['text']
        ?? ''
    ));
}

function cw_max_extract_message_attachments_from_update(array $update): array {
    $candidates = [
        $update['message']['body']['attachments'] ?? null,
        $update['message']['body']['attachment'] ?? null,
        $update['message']['body']['media'] ?? null,
        $update['message']['body']['images'] ?? null,
        $update['message']['attachments'] ?? null,
        $update['message']['attachment'] ?? null,
        $update['message']['media'] ?? null,
        $update['attachments'] ?? null,
    ];

    foreach ($candidates as $value) {
        if (!is_array($value)) {
            continue;
        }

        if (isset($value['type']) || isset($value['payload']) || isset($value['url']) || isset($value['download_url'])) {
            return [$value];
        }

        return array_values($value);
    }

    return [];
}

function cw_max_insert_operator_raw_message(int $dialog_id, string $message): int {
    global $wpdb;

    if ($dialog_id <= 0) return 0;
    $message = trim($message);
    if ($message === '') return 0;

    $D = $wpdb->prefix . 'cw_dialogs';
    $M = $wpdb->prefix . 'cw_messages';

    $status = (string) $wpdb->get_var(
        $wpdb->prepare("SELECT status FROM {$D} WHERE id=%d", $dialog_id)
    );

    if ($status === '' || $status === 'closed') {
        return 0;
    }

    if (function_exists('cw_mark_user_messages_read_by_operator')) {
        cw_mark_user_messages_read_by_operator($dialog_id);
    }

    $ok = $wpdb->insert($M, [
        'dialog_id'   => $dialog_id,
        'message'     => $message,
        'is_operator' => 1,
        'unread'      => 1,
        'created_at'  => current_time('mysql'),
    ]);

    return $ok ? (int) $wpdb->insert_id : 0;
}

function cw_max_insert_operator_attachment_message(int $dialog_id, array $attachment): int {
    $downloaded = cw_max_download_attachment_to_chat_widget($attachment);
    if (is_wp_error($downloaded)) {
        $extra = $downloaded->get_error_data();
        $body_text = is_array($extra) && !empty($extra['body']) ? ' | body: ' . $extra['body'] : '';
        cw_max_log_error('Attachment import error: ' . $downloaded->get_error_message() . $body_text);
        return 0;
    }

    $message = ($downloaded['kind'] ?? '') === 'image'
        ? '[image]' . (string) ($downloaded['url'] ?? '')
        : '[file]' . (string) ($downloaded['url'] ?? '') . '|' . (string) ($downloaded['name'] ?? 'Файл');

    return cw_max_insert_operator_raw_message($dialog_id, $message);
}

function cw_max_create_operator_messages_from_update(int $dialog_id, array $update): array {
    $attachments = cw_max_extract_message_attachments_from_update($update);
    $needs_refresh = false;

    foreach ($attachments as $attachment) {
        if (is_array($attachment) && !cw_max_attachment_has_downloadable_url($attachment)) {
            $needs_refresh = true;
            break;
        }
    }

    if ($needs_refresh) {
        $update = cw_max_update_with_full_message_data($update);
        $attachments = cw_max_extract_message_attachments_from_update($update);
    }

    $text = cw_max_extract_message_text_from_update($update);

    $inserted_ids = [];
    $failed_attachments = 0;

    if ($text !== '') {
        $text_id = cw_max_insert_operator_message($dialog_id, $text);
        if ($text_id > 0) {
            $inserted_ids[] = $text_id;
        }
    }

    foreach ($attachments as $attachment) {
        if (!is_array($attachment)) {
            continue;
        }

        $attachment_id = cw_max_insert_operator_attachment_message($dialog_id, $attachment);
        if ($attachment_id > 0) {
            $inserted_ids[] = $attachment_id;
        } else {
            $failed_attachments++;
        }
    }

    return [
        'ids'                => $inserted_ids,
        'text'               => $text,
        'attachments_total'  => count($attachments),
        'attachments_failed' => $failed_attachments,
    ];
}

function cw_max_edit_message(string $message_id, string $text, array $options = []): bool {
    $message_id = trim($message_id);
    if ($message_id === '') return false;

    $text = trim($text);
    if ($text === '') return false;

    if (mb_strlen($text) > 3900) {
        $text = mb_substr($text, 0, 3900) . '...';
    }

    $payload = [
        'text'   => $text,
        'notify' => array_key_exists('notify', $options) ? !empty($options['notify']) : false,
    ];

    if (array_key_exists('attachments', $options) && is_array($options['attachments'])) {
        $payload['attachments'] = array_values($options['attachments']);
    }

    if (!empty($options['format'])) {
        $payload['format'] = (string) $options['format'];
    }

    if (!empty($options['link']) && is_array($options['link'])) {
        $payload['link'] = $options['link'];
    }

    $response = cw_max_api_request('/messages?message_id=' . rawurlencode($message_id), $payload, 'PUT');

    if (is_wp_error($response)) {
        $extra = $response->get_error_data();
        $body_text = is_array($extra) && !empty($extra['body']) ? ' | body: ' . $extra['body'] : '';
        cw_max_log_error('Edit message error: ' . $response->get_error_message() . $body_text);
        return false;
    }

    return !empty($response['success']) || !array_key_exists('success', $response);
}

function cw_max_answer_callback(string $callback_id, array $body = []): bool {
    $callback_id = trim($callback_id);
    if ($callback_id === '') return false;

    $response = cw_max_api_request('/answers?callback_id=' . rawurlencode($callback_id), $body, 'POST');

    if (is_wp_error($response)) {
        $extra = $response->get_error_data();
        $body_text = is_array($extra) && !empty($extra['body']) ? ' | body: ' . $extra['body'] : '';
        cw_max_log_error('Callback answer error: ' . $response->get_error_message() . $body_text);
        return false;
    }

    return true;
}

function cw_max_answer_callback_notification(string $callback_id, string $notification, ?array $message = null): bool {
    $body = [];

    $notification = trim($notification);
    if ($notification !== '') {
        $body['notification'] = $notification;
    }

    if (!empty($message)) {
        $body['message'] = $message;
    }

    return cw_max_answer_callback($callback_id, $body);
}

function cw_max_get_reply_state_map(): array {
    $map = get_option('cw_max_reply_state', []);
    return is_array($map) ? $map : [];
}

function cw_max_save_reply_state_map(array $map): void {
    update_option('cw_max_reply_state', $map, false);
}

function cw_max_set_reply_dialog(int $user_id, int $dialog_id): void {
    if ($user_id <= 0 || $dialog_id <= 0) return;

    $map = cw_max_get_reply_state_map();
    $map[(string) $user_id] = [
        'dialog_id'   => $dialog_id,
        'updated_at'  => time(),
        'expires_at'  => time() + DAY_IN_SECONDS,
    ];

    cw_max_save_reply_state_map($map);
}

function cw_max_get_reply_dialog(int $user_id): int {
    if ($user_id <= 0) return 0;

    $map = cw_max_get_reply_state_map();
    $item = $map[(string) $user_id] ?? null;

    if (!is_array($item)) {
        return 0;
    }

    $expires_at = (int) ($item['expires_at'] ?? 0);
    if ($expires_at > 0 && $expires_at < time()) {
        unset($map[(string) $user_id]);
        cw_max_save_reply_state_map($map);
        return 0;
    }

    return (int) ($item['dialog_id'] ?? 0);
}

function cw_max_clear_reply_dialog(int $user_id): void {
    if ($user_id <= 0) return;

    $map = cw_max_get_reply_state_map();
    if (!isset($map[(string) $user_id])) return;

    unset($map[(string) $user_id]);
    cw_max_save_reply_state_map($map);
}

function cw_max_get_sbp_payment_url(): string {
    $url = (string) apply_filters(
        'cw_max_sbp_payment_url',
        'https://qr.nspk.ru/AS1A005O0HP24U9I8P0AN5VA03QFOL1F?type=01&bank=100000000111&crc=30da'
    );

    return esc_url_raw(trim($url));
}

function cw_max_build_operator_keyboard(int $dialog_id): array {
    if ($dialog_id <= 0) return [];

    $buttons = [
        [
            [
                'type'    => 'callback',
                'text'    => '✉ Ответить',
                'payload' => 'cw_reply_' . $dialog_id,
            ],
            [
                'type'    => 'callback',
                'text'    => '📜 История диалога',
                'payload' => 'cw_history_' . $dialog_id,
            ],
        ],
        [
            [
                'type'    => 'callback',
                'text'    => '❌ Закрыть',
                'payload' => 'cw_close_' . $dialog_id,
                'intent'  => 'negative',
            ],
            [
                'type'    => 'callback',
                'text'    => '📊 Статистика',
                'payload' => 'cw_stats_' . $dialog_id,
            ],
        ],
    ];

    $payment_url = cw_max_get_sbp_payment_url();
    if ($payment_url !== '') {
        $buttons[] = [
            [
                'type'    => 'callback',
                'text'    => '💳 СБП QR',
                'payload' => 'cw_sbp_' . $dialog_id,
            ],
        ];
    }

    return [[
        'type'    => 'inline_keyboard',
        'payload' => [
            'buttons' => $buttons,
        ],
    ]];
}

function cw_max_send_help(int $user_id): void {
    $text = implode("\n", [
        'Оператор MAX работает через кнопки в уведомлениях.',
        'Нажмите «Ответить», чтобы отправить сообщение, файл или изображение в нужный диалог.',
        'Нажмите «История диалога», чтобы получить последние сообщения.',
        'Нажмите «Закрыть», чтобы закрыть диалог.',
        'Нажмите «Статистика», чтобы получить список открытых и закрытых диалогов.',
        'Нажмите «СБП QR», чтобы отправить ссылку на оплату в диалог.',
        '/start — привязать этого пользователя как оператора, если он ещё не сохранён',
    ]);

    cw_max_send_to_user($user_id, $text);
}

function cw_max_message_html_to_text(string $html): string {
    $html = trim($html);
    if ($html === '') return '';

    $html = preg_replace_callback(
        '/<a\b[^>]*href\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))[^>]*>(.*?)<\/a>/isu',
        function ($m) {
            $href = html_entity_decode($m[1] ?: $m[2] ?: $m[3] ?: '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $label = wp_strip_all_tags((string) ($m[4] ?? ''));
            $label = html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $label = trim($label);
            $href  = trim($href);

            if ($label !== '' && $href !== '') {
                return $label . ': ' . $href;
            }

            return $label !== '' ? $label : $href;
        },
        $html
    );

    $text = wp_strip_all_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim((string) $text);
}

function cw_max_get_reply_receipts_map(): array {
    $map = get_option('cw_max_reply_receipts', []);
    return is_array($map) ? $map : [];
}

function cw_max_save_reply_receipts_map(array $map): void {
    update_option('cw_max_reply_receipts', $map, false);
}

function cw_max_cleanup_reply_receipts(): void {
    $map = cw_max_get_reply_receipts_map();
    if (!$map) return;

    $now = time();
    $changed = false;

    foreach ($map as $site_message_id => $item) {
        if (!is_array($item)) {
            unset($map[$site_message_id]);
            $changed = true;
            continue;
        }

        $updated_at = (int) ($item['updated_at'] ?? 0);
        $status     = (string) ($item['status'] ?? 'sent');
        $ttl        = $status === 'read' ? (7 * DAY_IN_SECONDS) : (2 * DAY_IN_SECONDS);

        if ($updated_at > 0 && ($updated_at + $ttl) < $now) {
            unset($map[$site_message_id]);
            $changed = true;
        }
    }

    if ($changed) {
        cw_max_save_reply_receipts_map($map);
    }
}

function cw_max_build_reply_receipt_text(int $dialog_id, string $message, bool $is_read = false): string {
    unset($message);

    return implode("
", [
        '✉ Ответ в диалог #' . $dialog_id,
        $is_read ? '✓✓' : '✓',
    ]);
}

function cw_max_send_reply_receipt(int $site_message_id, int $dialog_id, int $user_id, string $message): bool {
    if ($site_message_id <= 0 || $dialog_id <= 0 || $user_id <= 0) return false;

    cw_max_cleanup_reply_receipts();

    $receipt_text = cw_max_build_reply_receipt_text($dialog_id, $message, false);
    $response = cw_max_send_to_user_ex($user_id, $receipt_text, [
        'notify' => false,
    ]);

    if (is_wp_error($response)) {
        $extra = $response->get_error_data();
        $body_text = is_array($extra) && !empty($extra['body']) ? ' | body: ' . $extra['body'] : '';
        cw_max_log_error('Reply receipt send error: ' . $response->get_error_message() . $body_text);
        return false;
    }

    $max_message_id = cw_max_extract_api_message_id($response);

    $map = cw_max_get_reply_receipts_map();
    $map[(string) $site_message_id] = [
        'dialog_id'        => $dialog_id,
        'operator_user_id' => $user_id,
        'site_message_id'  => $site_message_id,
        'max_message_id'   => $max_message_id,
        'source_text'      => $message,
        'status'           => 'sent',
        'updated_at'       => time(),
    ];
    cw_max_save_reply_receipts_map($map);

    if ($max_message_id === '') {
        cw_max_log_error(
            'Reply receipt sent, but MAX message_id was not found in API response for site message #' . $site_message_id .
            ' | response: ' . wp_json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    return true;
}

function cw_max_mark_reply_receipts_read_up_to(int $dialog_id, int $last_site_message_id): void {
    if ($dialog_id <= 0 || $last_site_message_id <= 0) return;

    cw_max_cleanup_reply_receipts();

    $map = cw_max_get_reply_receipts_map();
    if (!$map) return;

    $changed = false;

    foreach ($map as $site_message_id => $item) {
        if (!is_array($item)) continue;

        $site_message_id = (int) $site_message_id;
        if ($site_message_id <= 0 || $site_message_id > $last_site_message_id) continue;
        if ((int) ($item['dialog_id'] ?? 0) !== $dialog_id) continue;
        if ((string) ($item['status'] ?? '') === 'read') continue;

        $max_message_id = trim((string) ($item['max_message_id'] ?? ''));
        $source_text    = (string) ($item['source_text'] ?? '');
        $new_text       = cw_max_build_reply_receipt_text($dialog_id, $source_text, true);

        if ($max_message_id === '') {
            cw_max_log_error('Reply receipt cannot be marked as read because MAX message_id is empty for site message #' . $site_message_id);
            continue;
        }

        $edited = cw_max_edit_message($max_message_id, $new_text, [
            'notify' => false,
        ]);

        if (!$edited) {
            continue;
        }

        $item['status'] = 'read';
        $item['updated_at'] = time();
        $map[(string) $site_message_id] = $item;
        $changed = true;
    }

    if ($changed) {
        cw_max_save_reply_receipts_map($map);
    }
}

function cw_max_build_notification_message(int $dialog_id, string $message): array {
    $message = trim($message);

    if ($message === '' || stripos($message, '[system]') === 0) {
        return [
            'text'        => '',
            'attachments' => [],
        ];
    }

    if (stripos($message, '[image]') === 0) {
        $uploaded_attachment = cw_max_build_uploaded_attachment_from_site_message($message);
        $attachments = [];

        if (!is_wp_error($uploaded_attachment) && is_array($uploaded_attachment)) {
            $attachments[] = $uploaded_attachment;
        }

        $attachments = array_merge($attachments, cw_max_build_operator_keyboard($dialog_id));
        $parsed = cw_max_parse_site_media_message($message);

        return [
            'text' => implode("\n", array_filter([
                '🖼 Новое изображение',
                'Диалог: #' . $dialog_id,
                !empty($parsed['name']) ? 'Название: ' . $parsed['name'] : '',
                is_wp_error($uploaded_attachment) && !empty($parsed['url']) ? 'Ссылка: ' . $parsed['url'] : '',
            ])),
            'attachments' => $attachments,
        ];
    }

    if (stripos($message, '[file]') === 0) {
        $uploaded_attachment = cw_max_build_uploaded_attachment_from_site_message($message);
        $attachments = [];

        if (!is_wp_error($uploaded_attachment) && is_array($uploaded_attachment)) {
            $attachments[] = $uploaded_attachment;
        }

        $attachments = array_merge($attachments, cw_max_build_operator_keyboard($dialog_id));
        $parsed = cw_max_parse_site_media_message($message);

        return [
            'text' => implode("\n", array_filter([
                '📎 Новый файл',
                'Диалог: #' . $dialog_id,
                !empty($parsed['name']) ? 'Название: ' . $parsed['name'] : '',
                is_wp_error($uploaded_attachment) && !empty($parsed['url']) ? 'Ссылка: ' . $parsed['url'] : '',
            ])),
            'attachments' => $attachments,
        ];
    }

    $plain = cw_max_message_html_to_text($message);

    return [
        'text' => implode("\n", [
            '📩 Новое сообщение',
            'Диалог: #' . $dialog_id,
            '',
            $plain,
        ]),
        'attachments' => cw_max_build_operator_keyboard($dialog_id),
    ];
}

function cw_max_notify_operator(int $dialog_id, string $message): void {
    if (!cw_max_enabled()) return;

    $admin_user_id = cw_max_get_admin_user_id();
    if ($admin_user_id <= 0) return;

    $payload = cw_max_build_notification_message($dialog_id, $message);
    $text = trim((string) ($payload['text'] ?? ''));
    $attachments = !empty($payload['attachments']) && is_array($payload['attachments']) ? $payload['attachments'] : [];

    if ($text === '' && !$attachments) return;

    $response = cw_max_send_to_user_ex_retry($admin_user_id, $text, [
        'attachments'    => $attachments,
        'notify'         => true,
        'retry_attempts' => cw_max_has_media_attachments($attachments) ? 4 : 1,
        'retry_delay'    => 0.8,
    ]);

    if (is_wp_error($response)) {
        $extra = $response->get_error_data();
        $body_text = is_array($extra) && !empty($extra['body']) ? ' | body: ' . $extra['body'] : '';
        cw_max_log_error('Notify operator error: ' . $response->get_error_message() . $body_text);
    }
}

function cw_max_get_async_secret(): string {
    return hash_hmac('sha256', 'cw_max_async_notify|' . home_url('/'), wp_salt('auth'));
}

function cw_max_build_async_signature(int $message_id, int $timestamp): string {
    return hash_hmac('sha256', $message_id . '|' . $timestamp, cw_max_get_async_secret());
}

function cw_max_verify_async_signature(int $message_id, int $timestamp, string $signature): bool {
    $signature = trim($signature);
    if ($message_id <= 0 || $timestamp <= 0 || $signature === '') {
        return false;
    }

    if (abs(time() - $timestamp) > 300) {
        return false;
    }

    $expected = cw_max_build_async_signature($message_id, $timestamp);

    return hash_equals($expected, $signature);
}

function cw_max_process_message_notification(int $message_id): void {
    if (!cw_max_enabled()) return;
    if ($message_id <= 0) return;

    global $wpdb;

    $table = $wpdb->prefix . 'cw_messages';
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT dialog_id, message, is_operator FROM {$table} WHERE id=%d",
            $message_id
        ),
        ARRAY_A
    );

    if (!$row) return;
    if ((int) ($row['is_operator'] ?? 0) === 1) return;

    $dialog_id = (int) ($row['dialog_id'] ?? 0);
    $message   = (string) ($row['message'] ?? '');

    if ($dialog_id <= 0 || $message === '') return;
    if (stripos($message, '[system]') === 0) return;

    cw_max_notify_operator($dialog_id, $message);
}

function cw_max_schedule_post_response_notification(int $message_id): void {
    if ($message_id <= 0) return;

    global $cw_max_post_response_queue;
    if (!isset($cw_max_post_response_queue) || !is_array($cw_max_post_response_queue)) {
        $cw_max_post_response_queue = [];
    }

    if (!in_array($message_id, $cw_max_post_response_queue, true)) {
        $cw_max_post_response_queue[] = $message_id;
    }

    static $registered = false;
    if ($registered) {
        return;
    }

    $registered = true;

    register_shutdown_function(function () {
        global $cw_max_post_response_queue;

        ignore_user_abort(true);

        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        $queue = is_array($cw_max_post_response_queue) ? array_values(array_unique(array_map('intval', $cw_max_post_response_queue))) : [];
        $cw_max_post_response_queue = [];

        foreach ($queue as $queued_message_id) {
            if ($queued_message_id > 0) {
                cw_max_process_message_notification($queued_message_id);
            }
        }
    });
}

function cw_max_dispatch_message_notification_async(int $message_id): bool {
    if (!cw_max_enabled()) return false;
    if ($message_id <= 0) return false;
    if (cw_max_get_token() === '') return false;
    if (cw_max_get_admin_user_id() <= 0) return false;

    $timestamp = time();
    $signature = cw_max_build_async_signature($message_id, $timestamp);
    $url = rest_url('cw/v1/max-notify');

    $args = [
        'timeout'     => 0.01,
        'blocking'    => false,
        'sslverify'   => apply_filters('https_local_ssl_verify', false),
        'redirection' => 0,
        'body'        => [
            'message_id' => $message_id,
            'ts'         => $timestamp,
            'sig'        => $signature,
        ],
    ];

    $response = wp_remote_post($url, $args);

    if (is_wp_error($response)) {
        cw_max_log_error('Async notify dispatch failed, switched to post-response fallback: ' . $response->get_error_message());
        cw_max_schedule_post_response_notification($message_id);
        return false;
    }

    return true;
}

function cw_max_queue_message_notification(int $message_id, ?int $delay_seconds = null): void {
    unset($delay_seconds);
    cw_max_dispatch_message_notification_async($message_id);
}

function cw_max_send_dialogs_list(int $user_id): void {
    global $wpdb;

    $D = $wpdb->prefix . 'cw_dialogs';
    $M = $wpdb->prefix . 'cw_messages';

    $rows = $wpdb->get_results(
        "
        SELECT d.id, d.status,
               (
                   SELECT COUNT(*)
                   FROM {$M} m
                   WHERE m.dialog_id = d.id
                     AND m.unread = 1
                     AND m.is_operator = 0
               ) AS unread
        FROM {$D} d
        ORDER BY d.id DESC
        LIMIT 10
        ",
        ARRAY_A
    );

    if (!$rows) {
        cw_max_send_to_user($user_id, 'Диалогов пока нет.');
        return;
    }

    $lines = ['Последние диалоги:'];

    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        $status = (string) ($row['status'] ?? 'open');
        $unread = (int) ($row['unread'] ?? 0);

        $status_label = $status === 'closed' ? 'закрыт' : 'открыт';

        $line = '#' . $id . ' | ' . $status_label;

        if ($unread > 0) {
            $line .= ' | непрочитано: ' . $unread;
        }

        $lines[] = $line;
    }

    $lines[] = '';
    $lines[] = 'Для ответа используйте кнопку «Ответить» в уведомлении по нужному диалогу.';
    $lines[] = 'Для закрытия используйте кнопку «Закрыть».';

    cw_max_send_to_user($user_id, implode("\n", $lines));
}

function cw_max_insert_operator_message(int $dialog_id, string $text): int {
    if ($dialog_id <= 0) return 0;

    $text = sanitize_text_field(mb_substr(trim($text), 0, 2000));
    if ($text === '') return 0;

    return cw_max_insert_operator_raw_message($dialog_id, $text);
}

function cw_max_prepare_history_preview(string $message): array {
    $message = trim((string) $message);

    if ($message === '') {
        return [
            'role' => 'other',
            'text' => '',
        ];
    }

    if (stripos($message, '[system]') === 0) {
        $text = trim((string) mb_substr($message, 8));
        $text = wp_strip_all_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);

        return [
            'role' => 'system',
            'text' => trim((string) $text),
        ];
    }

    if (stripos($message, '[image]') === 0) {
        return [
            'role' => 'media',
            'text' => '[Изображение]',
        ];
    }

    if (stripos($message, '[file]') === 0) {
        $payload = trim((string) mb_substr($message, 6));
        $name = 'Файл';
        $pos = strpos($payload, '|');

        if ($pos !== false) {
            $name = trim((string) substr($payload, $pos + 1));
        }

        if ($name === '') {
            $name = 'Файл';
        }

        return [
            'role' => 'media',
            'text' => '[Файл] ' . $name,
        ];
    }

    $message = wp_strip_all_tags($message);
    $message = html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $message = preg_replace('/\s+/u', ' ', $message);
    $message = trim((string) $message);

    if (mb_strlen($message) > 280) {
        $message = mb_substr($message, 0, 280) . '...';
    }

    return [
        'role' => 'text',
        'text' => $message,
    ];
}

function cw_max_history_time_label(string $datetime): string {
    $datetime = trim($datetime);
    if ($datetime === '') {
        return '';
    }

    $ts = strtotime($datetime);
    if (!$ts) {
        return $datetime;
    }

    return date_i18n('H:i', $ts);
}

function cw_max_extract_timezone_from_browser_label(string $browser_label): string {
    $browser_label = trim($browser_label);
    if ($browser_label === '') {
        return '';
    }

    if (preg_match('/(?:^|\|)\s*TZ:\s*([^|]+)/u', $browser_label, $m)) {
        return trim((string) ($m[1] ?? ''));
    }

    return '';
}

function cw_max_format_dialog_ids_for_stats(array $ids): string {
    $ids = array_values(array_filter(array_map('intval', $ids), static function ($id) {
        return $id > 0;
    }));

    if (!$ids) {
        return '-';
    }

    $parts = array_map(static function ($id) {
        return '#' . $id;
    }, $ids);

    $result = implode(', ', $parts);

    if (mb_strlen($result) > 3000) {
        $result = mb_substr($result, 0, 3000) . '...';
    }

    return $result;
}

function cw_max_get_dialog_history_text(int $dialog_id, int $limit = 12): string {
    global $wpdb;

    if ($dialog_id <= 0) {
        return 'Диалог не найден.';
    }

    if (function_exists('cw_ensure_dialog_geo_loaded')) {
        cw_ensure_dialog_geo_loaded($dialog_id);
    }

    $limit = max(1, min(30, (int) $limit));
    $tableD = $wpdb->prefix . 'cw_dialogs';
    $tableM = $wpdb->prefix . 'cw_messages';

    $dialog = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT geo_org, geo_ip, geo_browser
             FROM {$tableD}
             WHERE id = %d",
            $dialog_id
        ),
        ARRAY_A
    );

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, message, is_operator, is_bot, created_at
             FROM {$tableM}
             WHERE dialog_id = %d
             ORDER BY id DESC
             LIMIT %d",
            $dialog_id,
            $limit
        ),
        ARRAY_A
    );

    if (!$rows) {
        return 'История диалога #' . intval($dialog_id) . ' пуста.';
    }

    $rows = array_reverse($rows);

    $provider = trim((string) ($dialog['geo_org'] ?? ''));
    $ip = trim((string) ($dialog['geo_ip'] ?? ''));
    $tz = cw_max_extract_timezone_from_browser_label((string) ($dialog['geo_browser'] ?? ''));

    $lines = [];
    $lines[] = '📜 История диалога #' . intval($dialog_id);

    if ($ip !== '' || $provider !== '' || $tz !== '') {
        $lines[] = '';

        if ($ip !== '') {
            $lines[] = 'IP: ' . $ip;
        }

        if ($provider !== '') {
            $lines[] = 'Провайдер: ' . $provider;
        }

        if ($tz !== '') {
            $lines[] = 'TZ: ' . $tz;
        }
    }

    $lines[] = '';

    foreach ($rows as $row) {
        $preview = cw_max_prepare_history_preview((string) ($row['message'] ?? ''));
        $message_text = trim((string) ($preview['text'] ?? ''));

        if ($message_text === '') {
            continue;
        }

        $time = cw_max_history_time_label((string) ($row['created_at'] ?? ''));
        $is_operator = (int) ($row['is_operator'] ?? 0) === 1;
        $is_bot = (int) ($row['is_bot'] ?? 0) === 1;

        if (($preview['role'] ?? '') === 'system') {
            $title = '🔔 Система';
        } elseif ($is_bot) {
            $title = '🤖 Бот';
        } elseif ($is_operator) {
            $title = '🧑‍💼 Оператор';
        } else {
            $title = '👤 Пользователь';
        }

        if ($time !== '') {
            $title .= ' · ' . $time;
        }

        $lines[] = $title;
        $lines[] = $message_text;
        $lines[] = '';
    }

    while (!empty($lines) && end($lines) === '') {
        array_pop($lines);
    }

    $result = implode("\n", $lines);

    if (mb_strlen($result) > 3900) {
        $result = mb_substr($result, 0, 3900) . "\n...";
    }

    return $result;
}

function cw_max_dialog_stats_text(int $dialog_id): string {
    global $wpdb;

    $D = $wpdb->prefix . 'cw_dialogs';

    $open_ids = $wpdb->get_col(
        "SELECT id
         FROM {$D}
         WHERE status = 'open'
         ORDER BY id DESC"
    );

    $closed_ids = $wpdb->get_col(
        "SELECT id
         FROM {$D}
         WHERE status = 'closed'
         ORDER BY id DESC"
    );

    $open_ids = is_array($open_ids) ? $open_ids : [];
    $closed_ids = is_array($closed_ids) ? $closed_ids : [];

    $lines = [];
    $lines[] = '📊 Статистика диалогов';
    $lines[] = '';
    $lines[] = 'Открытые: ' . count($open_ids);
    $lines[] = cw_max_format_dialog_ids_for_stats($open_ids);
    $lines[] = '';
    $lines[] = 'Закрытые: ' . count($closed_ids);
    $lines[] = cw_max_format_dialog_ids_for_stats($closed_ids);

    $result = implode("\n", $lines);

    if (mb_strlen($result) > 3900) {
        $result = mb_substr($result, 0, 3900) . "\n...";
    }

    return $result;
}

function cw_max_close_dialog(int $dialog_id): string {
    global $wpdb;

    if ($dialog_id <= 0) {
        return 'not_found';
    }

    $D = $wpdb->prefix . 'cw_dialogs';
    $M = $wpdb->prefix . 'cw_messages';

    $status = (string) $wpdb->get_var(
        $wpdb->prepare("SELECT status FROM {$D} WHERE id=%d", $dialog_id)
    );

    if ($status === '') {
        return 'not_found';
    }

    if ($status === 'closed') {
        return 'already_closed';
    }

    if (function_exists('cw_mark_user_messages_read_by_operator')) {
        cw_mark_user_messages_read_by_operator($dialog_id);
    }

    $wpdb->update($D, ['status' => 'closed'], ['id' => $dialog_id]);

    $wpdb->insert($M, [
        'dialog_id'   => $dialog_id,
        'message'     => '[system]Диалог закрыт через MAX.',
        'is_operator' => 1,
        'unread'      => 1,
        'created_at'  => current_time('mysql'),
    ]);

    return 'closed';
}

function cw_max_extract_sender_user_id(array $update): int {
    $candidates = [
        $update['callback']['user']['user_id'] ?? 0,
        $update['callback']['sender']['user_id'] ?? 0,
        $update['callback']['sender']['id'] ?? 0,
        $update['message']['sender']['user_id'] ?? 0,
        $update['message']['sender']['id'] ?? 0,
        $update['user']['user_id'] ?? 0,
        $update['user_id'] ?? 0,
    ];

    foreach ($candidates as $value) {
        $user_id = (int) $value;
        if ($user_id > 0) {
            return $user_id;
        }
    }

    return 0;
}

function cw_max_extract_callback_payload(array $update): string {
    $candidates = [
        $update['callback']['payload'] ?? '',
        $update['callback']['data'] ?? '',
        $update['payload'] ?? '',
        $update['data'] ?? '',
    ];

    foreach ($candidates as $value) {
        $value = trim((string) $value);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function cw_max_extract_callback_id(array $update): string {
    $candidates = [
        $update['callback']['callback_id'] ?? '',
        $update['callback_id'] ?? '',
    ];

    foreach ($candidates as $value) {
        $value = trim((string) $value);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function cw_max_process_callback_update(array $update) {
    $sender_user_id = cw_max_extract_sender_user_id($update);
    $callback_id    = cw_max_extract_callback_id($update);
    $payload        = cw_max_extract_callback_payload($update);

    if ($sender_user_id <= 0 || $payload === '') {
        return ['status' => 'ignored'];
    }

    $admin_user_id = cw_max_get_admin_user_id();
    if ($admin_user_id > 0 && $sender_user_id !== $admin_user_id) {
        return ['status' => 'ignored'];
    }

    if (!preg_match('/^cw_(\w+)_(\d+)$/u', $payload, $matches)) {
        if ($callback_id !== '') {
            cw_max_answer_callback_notification($callback_id, 'Неизвестная кнопка.');
        }
        return ['status' => 'bad_callback'];
    }

    $action = sanitize_key($matches[1]);
    $dialog_id = (int) $matches[2];

    if ($action === 'reply') {
        cw_max_set_reply_dialog($sender_user_id, $dialog_id);
        if ($callback_id !== '') {
            cw_max_answer_callback_notification($callback_id, 'Введите сообщение для диалога #' . $dialog_id);
        }
        cw_max_send_to_user($sender_user_id, 'Отправьте сообщение для диалога #' . $dialog_id . "\nДля отмены: /cancel", [
            'notify' => false,
        ]);
        return ['status' => 'reply_selected'];
    }

    if ($action === 'history') {
        $history_text = cw_max_get_dialog_history_text($dialog_id, 12);

        if ($callback_id !== '') {
            cw_max_answer_callback_notification($callback_id, 'История диалога #' . $dialog_id . ' отправлена.');
        }

        cw_max_send_to_user($sender_user_id, $history_text, [
            'notify' => false,
        ]);

        return ['status' => 'history_processed'];
    }

    if ($action === 'close') {
        $close_result = cw_max_close_dialog($dialog_id);

        if ($close_result === 'closed') {
            if ($callback_id !== '') {
                cw_max_answer_callback_notification($callback_id, 'Диалог #' . $dialog_id . ' закрыт.');
            }

            cw_max_clear_reply_dialog($sender_user_id);
            cw_max_send_to_user($sender_user_id, 'Диалог #' . $dialog_id . ' закрыт.', ['notify' => false]);

            return ['status' => 'close_processed'];
        }

        if ($close_result === 'already_closed') {
            if ($callback_id !== '') {
                cw_max_answer_callback_notification($callback_id, 'Диалог #' . $dialog_id . ' ранее закрыт.');
            }

            cw_max_send_to_user($sender_user_id, 'Диалог #' . $dialog_id . ' ранее закрыт.', ['notify' => false]);

            return ['status' => 'already_closed'];
        }

        if ($callback_id !== '') {
            cw_max_answer_callback_notification($callback_id, 'Диалог #' . $dialog_id . ' не найден.');
        }

        cw_max_send_to_user($sender_user_id, 'Диалог #' . $dialog_id . ' не найден.', ['notify' => false]);

        return ['status' => 'dialog_not_found'];
    }
    if ($action === 'stats') {
        $stats_text = cw_max_dialog_stats_text($dialog_id);

        if ($callback_id !== '') {
            cw_max_answer_callback_notification($callback_id, 'Статистика по диалогу #' . $dialog_id . ' отправлена.');
        }

        cw_max_send_to_user($sender_user_id, $stats_text, [
            'notify' => false,
        ]);

        return ['status' => 'stats_processed'];
    }

    if ($action === 'sbp') {
        $payment_url = cw_max_get_sbp_payment_url();
        $ok = false;

        if ($payment_url !== '') {
            $payment_text = '<a href="' . esc_url($payment_url) . '" target="_blank">СБП QR</a>';

            if (function_exists('cw_mark_user_messages_read_by_operator')) {
                cw_mark_user_messages_read_by_operator($dialog_id);
            }

            global $wpdb;
            $M = $wpdb->prefix . 'cw_messages';
            $ok = (bool) $wpdb->insert($M, [
                'dialog_id'   => $dialog_id,
                'message'     => $payment_text,
                'is_operator' => 1,
                'unread'      => 1,
                'created_at'  => current_time('mysql'),
            ]);
        }

        if ($callback_id !== '') {
            cw_max_answer_callback_notification($callback_id, $ok ? 'СБП QR отправлено в диалог #' . $dialog_id : 'Не удалось отправить СБП QR.');
        }

        cw_max_send_to_user(
            $sender_user_id,
            $ok ? 'СБП QR отправлено в диалог #' . $dialog_id : 'Не удалось отправить СБП QR.',
            ['notify' => false]
        );

        return ['status' => 'sbp_processed'];
    }

    if ($callback_id !== '') {
        cw_max_answer_callback_notification($callback_id, 'Действие не поддерживается.');
    }

    return ['status' => 'unknown_callback_action'];
}

add_action('rest_api_init', function () {
    register_rest_route('cw/v1', '/max-webhook', [
        'methods'             => ['POST'],
        'callback'            => 'cw_max_webhook_handler',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('cw/v1', '/max-notify', [
        'methods'             => ['POST'],
        'callback'            => 'cw_max_async_notify_handler',
        'permission_callback' => '__return_true',
    ]);
});

function cw_max_async_notify_handler(WP_REST_Request $r) {
    if (!cw_max_enabled()) {
        return new WP_REST_Response(['status' => 'integration_disabled'], 200);
    }

    $message_id = (int) $r->get_param('message_id');
    $timestamp  = (int) $r->get_param('ts');
    $signature  = (string) $r->get_param('sig');

    if (!cw_max_verify_async_signature($message_id, $timestamp, $signature)) {
        return new WP_REST_Response(['error' => 'forbidden'], 403);
    }

    cw_max_process_message_notification($message_id);

    return ['status' => 'processed'];
}

function cw_max_webhook_handler(WP_REST_Request $r) {
    $secret = cw_max_get_webhook_secret();

    if ($secret !== '') {
        $header_secret = (string) $r->get_header('X-Max-Bot-Api-Secret');
        if ($header_secret === '' || !hash_equals($secret, $header_secret)) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }
    }

    if (!cw_max_enabled()) {
        return new WP_REST_Response(['status' => 'integration_disabled'], 200);
    }

    $update = $r->get_json_params();
    if (!is_array($update)) {
        return ['status' => 'invalid'];
    }

    $update_type = (string) ($update['update_type'] ?? '');

    if ($update_type === 'message_callback') {
        return cw_max_process_callback_update($update);
    }

    if ($update_type !== 'message_created' && $update_type !== 'bot_started') {
        return ['status' => 'ignored'];
    }

    $sender_user_id = cw_max_extract_sender_user_id($update);
    $text = cw_max_extract_message_text_from_update($update);
    $attachments = cw_max_extract_message_attachments_from_update($update);

    if ($sender_user_id <= 0) {
        return ['status' => 'ignored'];
    }

    $admin_user_id = cw_max_get_admin_user_id();

    if ($admin_user_id <= 0 && in_array(mb_strtolower($text), ['/start', '/bind'], true)) {
        update_option('cw_max_admin_user_id', $sender_user_id);

        cw_max_send_to_user(
            $sender_user_id,
            "Оператор MAX привязан.\n\nВаш User ID: {$sender_user_id}\n\nТеперь вы будете получать уведомления из сайта.\nИспользуйте кнопки в уведомлениях для работы с диалогами"
        );

        return ['status' => 'bound'];
    }

    if ($admin_user_id > 0 && $sender_user_id !== $admin_user_id) {
        return ['status' => 'ignored'];
    }

    if ($text === '' && empty($attachments) && $update_type === 'bot_started') {
        cw_max_send_help($sender_user_id);
        return ['status' => 'help'];
    }

    if ($text === '/start') {
        cw_max_send_help($sender_user_id);
        return ['status' => 'help'];
    }

    if ($text === '/help') {
        cw_max_send_help($sender_user_id);
        return ['status' => 'help'];
    }

    $reply_dialog = cw_max_get_reply_dialog($sender_user_id);
    if ($reply_dialog > 0) {
        $result = cw_max_create_operator_messages_from_update($reply_dialog, $update);
        $inserted_ids = isset($result['ids']) && is_array($result['ids']) ? $result['ids'] : [];
        $last_site_message_id = !empty($inserted_ids) ? (int) end($inserted_ids) : 0;

        if ($last_site_message_id > 0) {
            $receipt_text = trim((string) ($result['text'] ?? ''));
            if ($receipt_text === '') {
                $receipt_text = 'Вложение';
            }

            $receipt_ok = cw_max_send_reply_receipt($last_site_message_id, $reply_dialog, $sender_user_id, $receipt_text);
            cw_max_clear_reply_dialog($sender_user_id);

            if (!$receipt_ok) {
                cw_max_send_to_user($sender_user_id, 'Ответ отправлен в диалог #' . $reply_dialog . '.', ['notify' => false]);
            }

            return [
                'status'            => 'reply_state_processed',
                'inserted_messages' => count($inserted_ids),
            ];
        }

        $attachments_failed = (int) ($result['attachments_failed'] ?? 0);
        $attachments_total  = (int) ($result['attachments_total'] ?? 0);

        $reason = $attachments_total > 0 || $attachments_failed > 0
            ? 'Не удалось обработать вложение из MAX или сохранить его в чат.'
            : 'Не удалось отправить ответ в диалог #' . $reply_dialog . '. Возможно, диалог закрыт или не найден.';

        cw_max_send_to_user($sender_user_id, $reason, ['notify' => false]);
        return ['status' => 'reply_state_failed'];
    }

    if (empty($attachments) && $text === '') {
        cw_max_send_help($sender_user_id);
        return ['status' => 'help'];
    }

    if (!empty($attachments)) {
        cw_max_send_to_user(
            $sender_user_id,
            "Сначала нажмите кнопку «✉ Ответить» под уведомлением, затем отправьте файл или изображение.",
            ['notify' => false]
        );
        return ['status' => 'attachment_without_reply_mode'];
    }

    cw_max_send_to_user(
        $sender_user_id,
        "Не понял команду.\n\nИспользуйте:\n/reply 123 ваш текст\n/close 123\n/dialogs\n/help\nИли нажмите кнопку «✉ Ответить» под уведомлением."
    );

    return ['status' => 'unknown_command'];
}