<?php
if (!defined('ABSPATH')) exit;

/* ============================================================
   REGISTER ROUTES
============================================================ */

add_action('rest_api_init', function () {

    register_rest_route('cw/v1', '/dialogs', [
        'methods'  => ['POST', 'GET'],
        'callback' => 'cw_rest_dialogs',
        'permission_callback' => function ($r) {
            if ($r->get_method() === 'POST') return true;
            return current_user_can('manage_options');
        },
    ]);

    register_rest_route('cw/v1', '/dialogs/(?P<id>\d+)/messages', [
        'methods'  => ['GET', 'POST'],
        'callback' => 'cw_rest_messages',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('cw/v1', '/dialogs/(?P<id>\d+)/geo', [
        'methods'  => 'GET',
        'callback' => 'cw_rest_geo',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('cw/v1', '/dialogs/(?P<id>\d+)/contact', [
        'methods'  => 'GET',
        'callback' => 'cw_rest_contact',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
    ]);

    register_rest_route('cw/v1', '/dialogs/(?P<id>\d+)/close', [
        'methods'  => 'POST',
        'callback' => 'cw_rest_close',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
    ]);

    register_rest_route('cw/v1', '/dialogs/(?P<id>\d+)/delete', [
        'methods'  => 'POST',
        'callback' => 'cw_rest_delete',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
    ]);

    register_rest_route('cw/v1', '/dialogs/(?P<id>\d+)/message-statuses', [
        'methods'  => 'GET',
        'callback' => 'cw_rest_message_statuses',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
    ]);

    register_rest_route('cw/v1', '/dialogs/(?P<id>\d+)/read', [
        'methods'  => 'POST',
        'callback' => 'cw_rest_read',
        'permission_callback' => '__return_true',
    ]);
});

/* ============================================================
   HELPERS
============================================================ */

function cw_get_client_key_from_request(WP_REST_Request $r): string {
    $ck = $r->get_header('X-CW-Client-Key');
    if ($ck) return sanitize_text_field($ck);

    $ck = $r->get_param('client_key');
    if ($ck) return sanitize_text_field($ck);

    return '';
}

function cw_get_real_ip(): string {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return sanitize_text_field($_SERVER['HTTP_CF_CONNECTING_IP']);
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return sanitize_text_field(trim((string) ($parts[0] ?? '')));
    }

    return sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
}

function cw_get_employee_dialog_access_map(): array {
    $map = get_option('cw_employee_dialog_access', []);
    return is_array($map) ? $map : [];
}

function cw_save_employee_dialog_access_map(array $map): void {
    update_option('cw_employee_dialog_access', $map, false);
}

function cw_cleanup_employee_dialog_access(): void {
    $map = cw_get_employee_dialog_access_map();
    if (!$map) return;

    $now = time();
    $changed = false;

    foreach ($map as $client_key => $items) {
        if (!is_array($items)) {
            unset($map[$client_key]);
            $changed = true;
            continue;
        }

        foreach ($items as $dialog_id => $expires_at) {
            if ((int) $expires_at <= $now) {
                unset($map[$client_key][$dialog_id]);
                $changed = true;
            }
        }

        if (empty($map[$client_key])) {
            unset($map[$client_key]);
            $changed = true;
        }
    }

    if ($changed) {
        cw_save_employee_dialog_access_map($map);
    }
}

function cw_grant_employee_dialog_access(string $client_key, int $dialog_id, int $ttl_seconds = 3600): void {
    if ($client_key === '' || $dialog_id <= 0) return;

    cw_cleanup_employee_dialog_access();

    $map = cw_get_employee_dialog_access_map();

    if (!isset($map[$client_key]) || !is_array($map[$client_key])) {
        $map[$client_key] = [];
    }

    $map[$client_key][(string) $dialog_id] = time() + max(60, $ttl_seconds);

    cw_save_employee_dialog_access_map($map);
}

function cw_has_employee_dialog_access(string $client_key, int $dialog_id): bool {
    if ($client_key === '' || $dialog_id <= 0) return false;

    cw_cleanup_employee_dialog_access();

    $map = cw_get_employee_dialog_access_map();

    return !empty($map[$client_key][(string) $dialog_id]);
}

function cw_require_dialog_access_or_403(WP_REST_Request $r, int $dialog_id) {
    if (current_user_can('manage_options')) return true;

    global $wpdb;
    $D = $wpdb->prefix . 'cw_dialogs';

    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT client_key FROM {$D} WHERE id=%d", $dialog_id)
    );

    if (!$row) {
        return new WP_REST_Response(['error' => 'dialog_not_found'], 404);
    }

    $db_ck  = (string) $row->client_key;
    $req_ck = cw_get_client_key_from_request($r);

    if ($db_ck && $req_ck && hash_equals($db_ck, $req_ck)) {
        return true;
    }

    if ($req_ck && cw_has_employee_dialog_access($req_ck, $dialog_id)) {
        return true;
    }

    return new WP_REST_Response(['error' => 'forbidden'], 403);
}


function cw_ensure_contact_columns(): void {
    global $wpdb;

    static $done = false;
    if ($done) return;

    $D = $wpdb->prefix . 'cw_dialogs';

    $has_email = $wpdb->get_var("SHOW COLUMNS FROM {$D} LIKE 'contact_email'");
    if (!$has_email) {
        $wpdb->query("ALTER TABLE {$D} ADD COLUMN contact_email VARCHAR(190) NOT NULL DEFAULT '' AFTER geo_browser");
    }

    $has_phone = $wpdb->get_var("SHOW COLUMNS FROM {$D} LIKE 'contact_phone'");
    if (!$has_phone) {
        $wpdb->query("ALTER TABLE {$D} ADD COLUMN contact_phone VARCHAR(80) NOT NULL DEFAULT '' AFTER contact_email");
    }

    $done = true;
}

function cw_normalize_contact_phone_candidate(string $candidate): string {
    $raw = trim($candidate);
    if ($raw === '') return '';

    if (preg_match('/\d{4}\s*[-\/.]\s*\d{1,2}\s*[-\/.]\s*\d{1,2}/u', $raw)) {
        return '';
    }

    $starts_plus = preg_match('/^\s*\+/', $raw) === 1;
    $digits = preg_replace('/\D+/', '', $raw);

    if (!is_string($digits) || $digits === '') return '';

    if (strpos($digits, '00') === 0 && strlen($digits) > 10) {
        $digits = substr($digits, 2);
        $starts_plus = true;
    }

    $len = strlen($digits);
    if ($len < 10 || $len > 15) return '';

    if (!$starts_plus && !in_array($len, [10, 11], true)) {
        return '';
    }

    if (!$starts_plus && $len === 11 && $digits[0] !== '7' && $digits[0] !== '8') {
        return '';
    }

    if ($len === 10) {
        return '+7 (' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6, 2) . '-' . substr($digits, 8, 2);
    }

    if ($len === 11 && ($digits[0] === '7' || $digits[0] === '8')) {
        $local = substr($digits, 1);
        return '+7 (' . substr($local, 0, 3) . ') ' . substr($local, 3, 3) . '-' . substr($local, 6, 2) . '-' . substr($local, 8, 2);
    }

    return '+' . $digits;
}

function cw_extract_contact_from_text(string $text): array {
    $clean = wp_strip_all_tags(str_replace(["\r", "\n", "\t"], ' ', $text));
    $clean_norm = preg_replace('/\s+/u', ' ', $clean);
    if (is_string($clean_norm)) {
        $clean = $clean_norm;
    }

    $email = '';
    if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', $clean, $m)) {
        $candidate = trim((string) $m[0], " \t\n\r.,;:!?()[]<>\"'");
        if (is_email($candidate)) {
            $email = sanitize_email($candidate);
        }
    }

    $phone = '';
    if (preg_match_all('/(?<!\d)(?:\+?\d[\d\s().\-]{8,}\d)(?!\d)/u', $clean, $matches)) {
        foreach ((array) ($matches[0] ?? []) as $candidate) {
            if (strpos((string) $candidate, '@') !== false) continue;

            $normalized = cw_normalize_contact_phone_candidate((string) $candidate);
            if ($normalized !== '') {
                $phone = $normalized;
                break;
            }
        }
    }

    return [
        'email' => $email,
        'phone' => $phone,
    ];
}

function cw_get_dialog_contact(int $dialog_id, bool $scan_if_empty = false): array {
    global $wpdb;

    if ($dialog_id <= 0) {
        return ['email' => '', 'phone' => ''];
    }

    cw_ensure_contact_columns();

    $D = $wpdb->prefix . 'cw_dialogs';

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT contact_email, contact_phone FROM {$D} WHERE id=%d",
            $dialog_id
        ),
        ARRAY_A
    );

    if (!$row) {
        return ['email' => '', 'phone' => ''];
    }

    $contact = [
        'email' => sanitize_email((string) ($row['contact_email'] ?? '')),
        'phone' => sanitize_text_field((string) ($row['contact_phone'] ?? '')),
    ];

    if ($scan_if_empty && ($contact['email'] === '' || $contact['phone'] === '')) {
        return cw_refresh_dialog_contact_from_messages($dialog_id);
    }

    return $contact;
}

function cw_refresh_dialog_contact_from_messages(int $dialog_id): array {
    global $wpdb;

    if ($dialog_id <= 0) {
        return ['email' => '', 'phone' => ''];
    }

    cw_ensure_contact_columns();

    $D = $wpdb->prefix . 'cw_dialogs';
    $M = $wpdb->prefix . 'cw_messages';

    $current = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT contact_email, contact_phone FROM {$D} WHERE id=%d",
            $dialog_id
        ),
        ARRAY_A
    );

    if (!$current) {
        return ['email' => '', 'phone' => ''];
    }

    $email = sanitize_email((string) ($current['contact_email'] ?? ''));
    $phone = sanitize_text_field((string) ($current['contact_phone'] ?? ''));

    $messages = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT message FROM {$M} WHERE dialog_id=%d AND is_operator=0 ORDER BY id ASC",
            $dialog_id
        )
    );

    foreach ((array) $messages as $raw_message) {
        $found = cw_extract_contact_from_text((string) $raw_message);

        if (!empty($found['email'])) {
            $email = sanitize_email((string) $found['email']);
        }

        if (!empty($found['phone'])) {
            $phone = sanitize_text_field((string) $found['phone']);
        }
    }

    $data = [];

    if ($email !== sanitize_email((string) ($current['contact_email'] ?? ''))) {
        $data['contact_email'] = $email;
    }

    if ($phone !== sanitize_text_field((string) ($current['contact_phone'] ?? ''))) {
        $data['contact_phone'] = $phone;
    }

    if ($data) {
        $wpdb->update($D, $data, ['id' => $dialog_id]);
    }

    return [
        'email' => $email,
        'phone' => $phone,
    ];
}

function cw_update_dialog_contact_from_message(int $dialog_id, string $message): array {
    global $wpdb;

    if ($dialog_id <= 0 || trim($message) === '') {
        return ['email' => '', 'phone' => ''];
    }

    $found = cw_extract_contact_from_text($message);
    if (empty($found['email']) && empty($found['phone'])) {
        return cw_get_dialog_contact($dialog_id, false);
    }

    cw_ensure_contact_columns();

    $D = $wpdb->prefix . 'cw_dialogs';
    $current = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT contact_email, contact_phone FROM {$D} WHERE id=%d",
            $dialog_id
        ),
        ARRAY_A
    );

    if (!$current) {
        return ['email' => '', 'phone' => ''];
    }

    $data = [];

    if (!empty($found['email'])) {
        $email = sanitize_email((string) $found['email']);
        if ($email !== sanitize_email((string) ($current['contact_email'] ?? ''))) {
            $data['contact_email'] = $email;
        }
    }

    if (!empty($found['phone'])) {
        $phone = sanitize_text_field((string) $found['phone']);
        if ($phone !== sanitize_text_field((string) ($current['contact_phone'] ?? ''))) {
            $data['contact_phone'] = $phone;
        }
    }

    if ($data) {
        $wpdb->update($D, $data, ['id' => $dialog_id]);
    }

    return cw_get_dialog_contact($dialog_id, false);
}

function cw_client_info_from_request(WP_REST_Request $r): array {
    $client_info = $r->get_param('client_info');
    if (!is_array($client_info)) {
        $client_info = [];
    }

    return [
        'ua'        => sanitize_text_field($client_info['ua'] ?? ''),
        'platform'  => sanitize_text_field($client_info['platform'] ?? ''),
        'language'  => sanitize_text_field($client_info['language'] ?? ''),
        'languages' => sanitize_text_field($client_info['languages'] ?? ''),
        'screen'    => sanitize_text_field($client_info['screen'] ?? ''),
        'viewport'  => sanitize_text_field($client_info['viewport'] ?? ''),
        'timezone'  => sanitize_text_field($client_info['timezone'] ?? ''),
        'touch'     => !empty($client_info['touch']) ? 1 : 0,
    ];
}

function cw_detect_browser_name(string $ua): string {
    $ua_l = strtolower($ua);

    if ($ua_l === '') return 'Неизвестно';
    if (strpos($ua_l, 'edg/') !== false) return 'Microsoft Edge';
    if (strpos($ua_l, 'opr/') !== false || strpos($ua_l, 'opera') !== false) return 'Opera';
    if (strpos($ua_l, 'yabrowser/') !== false) return 'Yandex Browser';
    if (strpos($ua_l, 'vivaldi/') !== false) return 'Vivaldi';
    if (strpos($ua_l, 'brave') !== false) return 'Brave';
    if (strpos($ua_l, 'firefox/') !== false) return 'Firefox';
    if (strpos($ua_l, 'samsungbrowser/') !== false) return 'Samsung Internet';
    if (strpos($ua_l, 'ucbrowser/') !== false) return 'UC Browser';
    if (strpos($ua_l, 'chrome/') !== false && strpos($ua_l, 'chromium') === false) return 'Chrome';
    if (strpos($ua_l, 'safari/') !== false && strpos($ua_l, 'chrome/') === false) return 'Safari';
    if (strpos($ua_l, 'trident/') !== false || strpos($ua_l, 'msie ') !== false) return 'Internet Explorer';

    return 'Неизвестно';
}

function cw_detect_browser_version(string $ua, string $browser_name): string {
    $map = [
        'Microsoft Edge'    => 'edg',
        'Opera'             => 'opr|opera',
        'Yandex Browser'    => 'yabrowser',
        'Vivaldi'           => 'vivaldi',
        'Firefox'           => 'firefox',
        'Samsung Internet'  => 'samsungbrowser',
        'UC Browser'        => 'ucbrowser',
        'Chrome'            => 'chrome',
        'Safari'            => 'version',
        'Internet Explorer' => 'msie|rv',
    ];

    if (empty($map[$browser_name])) {
        return '';
    }

    if (preg_match('/(?:' . $map[$browser_name] . ')[\/:\s]+([0-9\.]+)/i', $ua, $m)) {
        return sanitize_text_field($m[1]);
    }

    return '';
}

function cw_detect_os_name(string $ua, string $platform = ''): string {
    $ua_l = strtolower($ua . ' ' . $platform);

    if (strpos($ua_l, 'windows nt 10.0') !== false) return 'Windows 10/11';
    if (strpos($ua_l, 'windows nt 6.3') !== false) return 'Windows 8.1';
    if (strpos($ua_l, 'windows nt 6.2') !== false) return 'Windows 8';
    if (strpos($ua_l, 'windows nt 6.1') !== false) return 'Windows 7';
    if (strpos($ua_l, 'iphone') !== false || strpos($ua_l, 'ipad') !== false || strpos($ua_l, 'ios') !== false) return 'iOS';
    if (strpos($ua_l, 'android') !== false) return 'Android';
    if (strpos($ua_l, 'mac os x') !== false || strpos($ua_l, 'macintosh') !== false) return 'macOS';
    if (strpos($ua_l, 'linux') !== false) return 'Linux';

    return 'Неизвестно';
}

function cw_detect_device_type(string $ua, array $client_info = []): string {
    $ua_l = strtolower($ua);
    $screen = strtolower((string) ($client_info['screen'] ?? ''));

    if (preg_match('/bot|crawl|spider|slurp|mediapartners|facebookexternalhit|preview/i', $ua_l)) {
        return 'Bot';
    }

    if (strpos($ua_l, 'ipad') !== false || strpos($ua_l, 'tablet') !== false) {
        return 'Tablet';
    }

    if (
        strpos($ua_l, 'mobi') !== false ||
        strpos($ua_l, 'iphone') !== false ||
        strpos($ua_l, 'android') !== false ||
        !empty($client_info['touch'])
    ) {
        return 'Mobile';
    }

    if ($screen && preg_match('/^(\d+)x(\d+)$/', $screen, $m)) {
        $w = (int) $m[1];
        if ($w > 0 && $w <= 900) {
            return 'Mobile';
        }
    }

    return 'Desktop';
}

function cw_format_browser_label(string $ua, array $client_info = []): string {
    $browser = cw_detect_browser_name($ua);
    $version = cw_detect_browser_version($ua, $browser);
    $os      = cw_detect_os_name($ua, (string) ($client_info['platform'] ?? ''));
    $device  = cw_detect_device_type($ua, $client_info);
    $lang    = trim((string) ($client_info['language'] ?? ''));
    $screen  = trim((string) ($client_info['screen'] ?? ''));
    $tz      = trim((string) ($client_info['timezone'] ?? ''));

    $parts = [];

    $parts[] = $browser . ($version ? ' ' . $version : '');
    $parts[] = $os;
    $parts[] = $device;

    if ($lang !== '') {
        $parts[] = 'Lang: ' . $lang;
    }

    if ($screen !== '') {
        $parts[] = 'Screen: ' . $screen;
    }

    if ($tz !== '') {
        $parts[] = 'TZ: ' . $tz;
    }

    return implode(' | ', array_filter($parts));
}

function cw_get_geo_by_ip(string $ip): array {
    if ($ip === '') {
        return [
            'country' => '',
            'city'    => '',
            'region'  => '',
            'org'     => '',
        ];
    }

    $response = wp_remote_get(
        add_query_arg(
            [
                'lang' => 'ru',
            ],
            'https://ipwho.is/' . rawurlencode($ip)
        ),
        [
            'timeout' => 4,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]
    );

    if (is_wp_error($response)) {
        return [
            'country' => '',
            'city'    => '',
            'region'  => '',
            'org'     => '',
        ];
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
        return [
            'country' => '',
            'city'    => '',
            'region'  => '',
            'org'     => '',
        ];
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (!is_array($body) || empty($body['success'])) {
        return [
            'country' => '',
            'city'    => '',
            'region'  => '',
            'org'     => '',
        ];
    }

    $connection = isset($body['connection']) && is_array($body['connection'])
        ? $body['connection']
        : [];

    return [
        'country' => sanitize_text_field($body['country'] ?? ''),
        'city'    => sanitize_text_field($body['city'] ?? ''),
        'region'  => sanitize_text_field($body['region'] ?? ''),
        'org'     => sanitize_text_field($connection['org'] ?? ''),
    ];
}

function cw_dialog_geo_cooldown_key(int $dialog_id): string {
    return 'cw_geo_retry_after_' . max(0, $dialog_id);
}

function cw_dialog_geo_can_retry(int $dialog_id): bool {
    if ($dialog_id <= 0) return false;

    $retry_after = (int) get_option(cw_dialog_geo_cooldown_key($dialog_id), 0);
    return $retry_after <= time();
}

function cw_dialog_geo_set_retry_cooldown(int $dialog_id, int $seconds = 900): void {
    if ($dialog_id <= 0) return;

    update_option(cw_dialog_geo_cooldown_key($dialog_id), time() + max(60, $seconds), false);
}

function cw_dialog_geo_clear_retry_cooldown(int $dialog_id): void {
    if ($dialog_id <= 0) return;

    delete_option(cw_dialog_geo_cooldown_key($dialog_id));
}

function cw_ensure_dialog_geo_loaded(int $dialog_id): void {
    if ($dialog_id <= 0) return;

    global $wpdb;
    $D = $wpdb->prefix . 'cw_dialogs';

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, geo_country, geo_city, geo_region, geo_org, geo_ip
             FROM {$D}
             WHERE id=%d",
            $dialog_id
        ),
        ARRAY_A
    );

    if (!$row) return;

    $country = trim((string) ($row['geo_country'] ?? ''));
    $city    = trim((string) ($row['geo_city'] ?? ''));
    $region  = trim((string) ($row['geo_region'] ?? ''));
    $org     = trim((string) ($row['geo_org'] ?? ''));
    $ip      = trim((string) ($row['geo_ip'] ?? ''));

    if ($country !== '' || $city !== '' || $region !== '' || $org !== '') {
        return;
    }

    if ($ip === '') {
        return;
    }

    if (!cw_dialog_geo_can_retry($dialog_id)) {
        return;
    }

    $geo = cw_get_geo_by_ip($ip);

    if (
        trim((string) ($geo['country'] ?? '')) === '' &&
        trim((string) ($geo['city'] ?? '')) === '' &&
        trim((string) ($geo['region'] ?? '')) === '' &&
        trim((string) ($geo['org'] ?? '')) === ''
    ) {
        cw_dialog_geo_set_retry_cooldown($dialog_id, 900);
        return;
    }

    $wpdb->update(
        $D,
        [
            'geo_country' => $geo['country'],
            'geo_city'    => $geo['city'],
            'geo_region'  => $geo['region'],
            'geo_org'     => $geo['org'],
        ],
        ['id' => $dialog_id],
        ['%s', '%s', '%s', '%s'],
        ['%d']
    );

    cw_dialog_geo_clear_retry_cooldown($dialog_id);
}

function cw_chat_widget_upload_dir($dirs) {
    $subdir = '/chat-widget';

    $dirs['subdir'] = $subdir;
    $dirs['path']   = $dirs['basedir'] . $subdir;
    $dirs['url']    = $dirs['baseurl'] . $subdir;

    if (!file_exists($dirs['path'])) {
        wp_mkdir_p($dirs['path']);
    }

    return $dirs;
}

function cw_transliterate_filename(string $filename): string {
    $filename = trim($filename);
    if ($filename === '') {
        return 'file-' . date('Ymd-His');
    }

    $ext  = pathinfo($filename, PATHINFO_EXTENSION);
    $name = pathinfo($filename, PATHINFO_FILENAME);

    $map = [
        'А'=>'A','Б'=>'B','В'=>'V','Г'=>'G','Д'=>'D','Е'=>'E','Ё'=>'E','Ж'=>'Zh','З'=>'Z','И'=>'I','Й'=>'Y',
        'К'=>'K','Л'=>'L','М'=>'M','Н'=>'N','О'=>'O','П'=>'P','Р'=>'R','С'=>'S','Т'=>'T','У'=>'U','Ф'=>'F',
        'Х'=>'Kh','Ц'=>'Ts','Ч'=>'Ch','Ш'=>'Sh','Щ'=>'Sch','Ъ'=>'','Ы'=>'Y','Ь'=>'','Э'=>'E','Ю'=>'Yu','Я'=>'Ya',
        'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y',
        'к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f',
        'х'=>'kh','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
    ];

    $name = strtr($name, $map);
    $name = preg_replace('/[^A-Za-z0-9\-_\.]+/u', '-', $name);
    $name = preg_replace('/-+/', '-', $name);
    $name = trim($name, '-_. ');

    if ($name === '') {
        $name = 'file-' . date('Ymd-His');
    }

    $ext = strtolower((string) $ext);
    $ext = preg_replace('/[^a-z0-9]+/', '', $ext);

    return $ext !== '' ? ($name . '.' . $ext) : $name;
}

function cw_prepare_display_filename(string $filename): string {
    $filename = trim(wp_basename($filename));

    if ($filename === '') {
        return 'Файл';
    }

    $ext  = pathinfo($filename, PATHINFO_EXTENSION);
    $name = pathinfo($filename, PATHINFO_FILENAME);

    $name = str_replace(["\\", "/", "|"], ' ', (string) $name);
    $ext  = str_replace(["\\", "/", "|"], '', (string) $ext);

    $name = preg_replace('/[\r\n\t]+/u', ' ', $name);
    $name = preg_replace('/\s+/u', ' ', $name);
    $name = trim($name);

    $ext = preg_replace('/[^A-Za-z0-9]+/', '', $ext);

    if ($name === '') {
        $name = 'Файл';
    }

    return $ext !== '' ? ($name . '.' . $ext) : $name;
}


function cw_upload_allowed_mimes(): array {
    $mimes = [
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

    $filtered = apply_filters('cw_upload_allowed_mimes', $mimes);
    return is_array($filtered) ? $filtered : $mimes;
}

function cw_detect_file_mime_type(string $path): string {
    $mime = '';

    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = @finfo_file($finfo, $path);
            @finfo_close($finfo);
            if (is_string($detected)) {
                $mime = trim(strtolower($detected));
            }
        }
    }

    if ($mime === '' && function_exists('mime_content_type')) {
        $detected = @mime_content_type($path);
        if (is_string($detected)) {
            $mime = trim(strtolower($detected));
        }
    }

    return $mime;
}

function cw_upload_openxml_package_matches(string $path, string $ext): bool {
    if (!class_exists('ZipArchive')) {
        return true;
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return false;
    }

    $has_content_types = $zip->locateName('[Content_Types].xml') !== false;
    $has_expected_part = false;

    if ($ext === 'docx') {
        $has_expected_part = $zip->locateName('word/document.xml') !== false;
    } elseif ($ext === 'xlsx') {
        $has_expected_part = $zip->locateName('xl/workbook.xml') !== false;
    }

    $zip->close();

    return $has_content_types && $has_expected_part;
}

function cw_upload_magic_bytes_match(string $path, string $ext): bool {
    $handle = @fopen($path, 'rb');
    if (!$handle) {
        return false;
    }

    $head = (string) @fread($handle, 12);
    @fclose($handle);

    if ($ext === 'pdf') {
        return strncmp($head, '%PDF-', 5) === 0;
    }

    if ($ext === 'zip') {
        return strncmp($head, "PK\x03\x04", 4) === 0
            || strncmp($head, "PK\x05\x06", 4) === 0
            || strncmp($head, "PK\x07\x08", 4) === 0;
    }

    if ($ext === 'rar') {
        return strncmp($head, "Rar!\x1A\x07", 6) === 0;
    }

    return true;
}

function cw_upload_detected_mime_matches_extension(string $ext, string $mime, string $path): bool {
    if ($mime === '') {
        return true;
    }

    $expected = [
        'jpg'  => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'jpe'  => ['image/jpeg', 'image/pjpeg'],
        'png'  => ['image/png', 'image/x-png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf'  => ['application/pdf', 'application/x-pdf'],
        'txt'  => ['text/plain'],
        'doc'  => ['application/msword', 'application/vnd.ms-office', 'application/x-ole-storage'],
        'xls'  => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/x-ole-storage'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/x-zip', 'application/x-zip-compressed'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/x-zip', 'application/x-zip-compressed'],
        'zip'  => ['application/zip', 'application/x-zip', 'application/x-zip-compressed'],
        'rar'  => ['application/vnd.rar', 'application/x-rar', 'application/x-rar-compressed'],
    ];

    if (!isset($expected[$ext]) || !in_array($mime, $expected[$ext], true)) {
        return false;
    }

    if (in_array($ext, ['jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp'], true)) {
        $info = @getimagesize($path);
        return is_array($info) && !empty($info['mime']) && in_array(strtolower((string) $info['mime']), $expected[$ext], true);
    }

    if (in_array($ext, ['pdf', 'zip', 'rar'], true)) {
        return cw_upload_magic_bytes_match($path, $ext);
    }

    if (in_array($ext, ['docx', 'xlsx'], true)) {
        return cw_upload_openxml_package_matches($path, $ext);
    }

    return true;
}

function cw_validate_uploaded_file_mime(array $file, array $allowed_mimes) {
    $tmp_name = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
    $filename = isset($file['name']) ? (string) $file['name'] : '';

    if ($tmp_name === '' || $filename === '' || !is_readable($tmp_name)) {
        return new WP_REST_Response([
            'error'   => 'invalid_upload_file',
            'details' => 'Не удалось проверить загруженный файл.',
        ], 400);
    }

    $wp_check = function_exists('wp_check_filetype_and_ext')
        ? wp_check_filetype_and_ext($tmp_name, $filename, $allowed_mimes)
        : wp_check_filetype($filename, $allowed_mimes);

    $ext  = isset($wp_check['ext']) ? strtolower((string) $wp_check['ext']) : '';
    $type = isset($wp_check['type']) ? strtolower((string) $wp_check['type']) : '';

    if ($ext === '' || $type === '') {
        return new WP_REST_Response([
            'error'   => 'invalid_file_type',
            'details' => 'Тип файла не разрешён или не соответствует расширению.',
        ], 400);
    }

    $detected_mime = cw_detect_file_mime_type($tmp_name);
    if (!cw_upload_detected_mime_matches_extension($ext, $detected_mime, $tmp_name)) {
        return new WP_REST_Response([
            'error'         => 'invalid_file_mime',
            'details'       => 'MIME-тип файла не соответствует разрешённому расширению.',
            'detected_mime' => $detected_mime,
        ], 400);
    }

    return true;
}


function cw_upload_quota_limit_bytes(): int {
    return max(1, (int) apply_filters('cw_upload_quota_limit_bytes', 20 * 1024 * 1024));
}

function cw_upload_quota_window_seconds(): int {
    return max(60, (int) apply_filters('cw_upload_quota_window_seconds', 60 * 60));
}

function cw_upload_quota_transient_key(WP_REST_Request $r, int $dialog_id): string {
    $client_key = cw_get_client_key_from_request($r);

    if ($client_key === '') {
        $client_key = 'ip:' . cw_get_real_ip() . ':dialog:' . $dialog_id;
    }

    return 'cw_uq_' . substr(hash('sha256', $client_key), 0, 32);
}

function cw_upload_quota_get_usage(WP_REST_Request $r, int $dialog_id): array {
    $key    = cw_upload_quota_transient_key($r, $dialog_id);
    $now    = time();
    $window = cw_upload_quota_window_seconds();
    $state  = get_transient($key);

    if (!is_array($state)) {
        $state = [];
    }

    $items = isset($state['items']) && is_array($state['items']) ? $state['items'] : [];
    $fresh = [];
    $used  = 0;

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $ts   = isset($item['ts']) ? (int) $item['ts'] : 0;
        $size = isset($item['size']) ? max(0, (int) $item['size']) : 0;

        if ($ts > 0 && $size > 0 && ($now - $ts) < $window) {
            $fresh[] = ['ts' => $ts, 'size' => $size];
            $used += $size;
        }
    }

    if (count($fresh) !== count($items)) {
        set_transient($key, ['items' => $fresh], $window + 300);
    }

    return [
        'key'    => $key,
        'items'  => $fresh,
        'used'   => $used,
        'limit'  => cw_upload_quota_limit_bytes(),
        'window' => $window,
    ];
}

function cw_upload_quota_check(WP_REST_Request $r, int $dialog_id, int $incoming_size) {
    $incoming_size = max(0, $incoming_size);
    $usage = cw_upload_quota_get_usage($r, $dialog_id);

    if (($usage['used'] + $incoming_size) <= $usage['limit']) {
        return true;
    }

    $oldest_ts = 0;
    foreach ($usage['items'] as $item) {
        $ts = isset($item['ts']) ? (int) $item['ts'] : 0;
        if ($ts > 0 && ($oldest_ts === 0 || $ts < $oldest_ts)) {
            $oldest_ts = $ts;
        }
    }

    $reset_after = $oldest_ts > 0
        ? max(1, ($oldest_ts + (int) $usage['window']) - time())
        : (int) $usage['window'];

    return new WP_REST_Response([
        'error'             => 'upload_quota_exceeded',
        'details'           => 'Превышен лимит загрузок: не более 20 МБ за 60 минут.',
        'limit_bytes'       => (int) $usage['limit'],
        'used_bytes'        => (int) $usage['used'],
        'incoming_bytes'    => $incoming_size,
        'window_seconds'    => (int) $usage['window'],
        'reset_after'       => $reset_after,
    ], 429);
}

function cw_upload_quota_record(WP_REST_Request $r, int $dialog_id, int $uploaded_size): void {
    $uploaded_size = max(0, $uploaded_size);
    if ($uploaded_size <= 0) {
        return;
    }

    $usage = cw_upload_quota_get_usage($r, $dialog_id);
    $items = $usage['items'];
    $items[] = ['ts' => time(), 'size' => $uploaded_size];

    set_transient($usage['key'], ['items' => $items], ((int) $usage['window']) + 300);
}

function cw_upload_rate_limit_max_attempts(): int {
    return max(1, (int) apply_filters('cw_upload_rate_limit_max_attempts', 10));
}

function cw_upload_rate_limit_window_seconds(): int {
    return max(60, (int) apply_filters('cw_upload_rate_limit_window_seconds', 10 * 60));
}

function cw_upload_rate_limit_transient_key(WP_REST_Request $r, int $dialog_id): string {
    $client_key = cw_get_client_key_from_request($r);

    if ($client_key === '') {
        $client_key = 'ip:' . cw_get_real_ip() . ':dialog:' . $dialog_id;
    }

    return 'cw_ur_' . substr(hash('sha256', $client_key), 0, 32);
}

function cw_upload_rate_limit_get_usage(WP_REST_Request $r, int $dialog_id): array {
    $key    = cw_upload_rate_limit_transient_key($r, $dialog_id);
    $now    = time();
    $window = cw_upload_rate_limit_window_seconds();
    $state  = get_transient($key);

    if (!is_array($state)) {
        $state = [];
    }

    $items = isset($state['items']) && is_array($state['items']) ? $state['items'] : [];
    $fresh = [];

    foreach ($items as $item) {
        $ts = is_array($item) && isset($item['ts']) ? (int) $item['ts'] : (int) $item;

        if ($ts > 0 && ($now - $ts) < $window) {
            $fresh[] = ['ts' => $ts];
        }
    }

    if (count($fresh) !== count($items)) {
        set_transient($key, ['items' => $fresh], $window + 300);
    }

    return [
        'key'     => $key,
        'items'   => $fresh,
        'count'   => count($fresh),
        'limit'   => cw_upload_rate_limit_max_attempts(),
        'window'  => $window,
    ];
}

function cw_upload_rate_limit_check(WP_REST_Request $r, int $dialog_id) {
    $usage = cw_upload_rate_limit_get_usage($r, $dialog_id);

    if ((int) $usage['count'] < (int) $usage['limit']) {
        return true;
    }

    $oldest_ts = 0;
    foreach ($usage['items'] as $item) {
        $ts = isset($item['ts']) ? (int) $item['ts'] : 0;
        if ($ts > 0 && ($oldest_ts === 0 || $ts < $oldest_ts)) {
            $oldest_ts = $ts;
        }
    }

    $reset_after = $oldest_ts > 0
        ? max(1, ($oldest_ts + (int) $usage['window']) - time())
        : (int) $usage['window'];

    return new WP_REST_Response([
        'error'          => 'upload_rate_limited',
        'details'        => 'Слишком много попыток загрузки: не более 10 файлов за 10 минут.',
        'limit'          => (int) $usage['limit'],
        'used'           => (int) $usage['count'],
        'window_seconds' => (int) $usage['window'],
        'reset_after'    => $reset_after,
    ], 429);
}

function cw_upload_rate_limit_record(WP_REST_Request $r, int $dialog_id): void {
    $usage = cw_upload_rate_limit_get_usage($r, $dialog_id);
    $items = $usage['items'];
    $items[] = ['ts' => time()];

    set_transient($usage['key'], ['items' => $items], ((int) $usage['window']) + 300);
}

/* ============================================================
   COMMANDS HELPERS
============================================================ */

function cw_commands_enabled_office(): bool {
    return (int) get_option('cw_cmd_office_enabled', 1) === 1;
}

function cw_commands_enabled_login(): bool {
    return (int) get_option('cw_cmd_login_enabled', 1) === 1;
}

function cw_command_label_office(): string {
    $v = trim((string) get_option('cw_cmd_office_label', '/офис'));
    return $v !== '' ? $v : '/офис';
}

function cw_command_label_login(): string {
    $v = trim((string) get_option('cw_cmd_login_label', '/вход'));
    return $v !== '' ? $v : '/вход';
}

function cw_get_transfer_codes(): array {
    $codes = get_option('cw_transfer_codes', []);
    return is_array($codes) ? $codes : [];
}

function cw_save_transfer_codes(array $codes): void {
    update_option('cw_transfer_codes', $codes, false);
}

function cw_cleanup_transfer_codes(): void {
    $codes = cw_get_transfer_codes();
    if (!$codes) return;

    $now = time();
    $changed = false;

    foreach ($codes as $code => $data) {
        $expires = isset($data['expires_at']) ? (int) $data['expires_at'] : 0;
        if ($expires > 0 && $expires < $now) {
            unset($codes[$code]);
            $changed = true;
        }
    }

    if ($changed) {
        cw_save_transfer_codes($codes);
    }
}

function cw_generate_transfer_code(): string {
    cw_cleanup_transfer_codes();

    $codes = cw_get_transfer_codes();

    for ($i = 0; $i < 20; $i++) {
        $code = (string) random_int(100000, 999999);
        if (!isset($codes[$code])) {
            return $code;
        }
    }

    return (string) time();
}

function cw_create_transfer_code_for_dialog(int $dialog_id): array {
    cw_cleanup_transfer_codes();

    $code  = cw_generate_transfer_code();
    $codes = cw_get_transfer_codes();

    $codes[$code] = [
        'dialog_id'  => $dialog_id,
        'created_at' => time(),
        'expires_at' => time() + (30 * 60),
    ];

    cw_save_transfer_codes($codes);

    return [
        'code'       => $code,
        'expires_at' => $codes[$code]['expires_at'],
    ];
}

function cw_find_transfer_code(string $code): ?array {
    cw_cleanup_transfer_codes();

    $codes = cw_get_transfer_codes();
    if (!isset($codes[$code]) || !is_array($codes[$code])) {
        return null;
    }

    return $codes[$code];
}

function cw_delete_transfer_code(string $code): void {
    $codes = cw_get_transfer_codes();
    if (isset($codes[$code])) {
        unset($codes[$code]);
        cw_save_transfer_codes($codes);
    }
}

function cw_mark_user_messages_read_by_operator(int $dialog_id): void {
    global $wpdb;

    if ($dialog_id <= 0) return;

    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->prefix}cw_messages
             SET unread = 0
             WHERE dialog_id = %d
               AND is_operator = 0
               AND unread = 1",
            $dialog_id
        )
    );
}

function cw_insert_system_message(int $dialog_id, string $text, int $unread = 1): void {
    global $wpdb;

    $wpdb->insert($wpdb->prefix . 'cw_messages', [
        'dialog_id'   => $dialog_id,
        'message'     => '[system]' . $text,
        'is_operator' => 1,
        'unread'      => $unread,
        'created_at'  => current_time('mysql')
    ]);
}

function cw_get_chat_consent_message(): string {
    return 'Продолжая общение в чате, я принимаю условия <a href="/politika-opd/" target="_blank" rel="noopener noreferrer">политики конфиденциальности</a> и даю <a href="/soglasie-na-obrabotku-personalnyh-dannyh/" target="_blank" rel="noopener noreferrer">согласие</a> на обработку моих персональных данных';
}

function cw_get_first_reply_waiting_message(): string {
    return 'Ваше сообщение отправлено! Ожидается подключение оператора. ~ 1-3 мин.';
}

function cw_try_handle_chat_command(
    int $current_dialog_id,
    string $client_key,
    string $message,
    bool $is_operator,
    string $dialog_status
) {
    if ($is_operator) return null;

    $message = trim($message);
    if ($message === '') return null;

    $office_cmd = cw_command_label_office();
    $login_cmd  = cw_command_label_login();

    if (cw_commands_enabled_office() && mb_strtolower($message) === mb_strtolower($office_cmd)) {
        if ($dialog_status === 'closed') {
            return ['status' => 'ok'];
        }

        $created = cw_create_transfer_code_for_dialog($current_dialog_id);
        $code    = $created['code'];

        cw_insert_system_message(
            $current_dialog_id,
            'Код доступа для сотрудника: ' . $code . '. Срок действия: 30 минут.'
        );

        return ['status' => 'ok', 'command' => 'office', 'code' => $code];
    }

    if (cw_commands_enabled_login()) {
        $pattern = '/^' . preg_quote($login_cmd, '/') . '\s+(\d{4,10})$/ui';
        if (preg_match($pattern, $message, $m)) {
            $code = trim((string) ($m[1] ?? ''));
            $data = cw_find_transfer_code($code);

            if (!$data) {
                cw_insert_system_message($current_dialog_id, 'Код не найден или срок его действия истёк.');
                return ['status' => 'ok', 'command' => 'login_invalid'];
            }

            $target_dialog_id = (int) ($data['dialog_id'] ?? 0);
            if ($target_dialog_id <= 0) {
                cw_delete_transfer_code($code);
                cw_insert_system_message($current_dialog_id, 'Целевой диалог не найден.');
                return ['status' => 'ok', 'command' => 'login_invalid_target'];
            }

            cw_delete_transfer_code($code);

            if ($client_key !== '') {
                cw_grant_employee_dialog_access($client_key, $target_dialog_id, 60 * 60);
            }

            cw_insert_system_message(
                $target_dialog_id,
                'Сотрудник вошёл в диалог.'
            );

            return [
                'status'        => 'ok',
                'command'       => 'login_success',
                'switch_dialog' => $target_dialog_id,
            ];
        }
    }

    return null;
}

/* ============================================================
   DIALOGS
============================================================ */

function cw_rest_dialogs(WP_REST_Request $r) {
    global $wpdb;

    $D = $wpdb->prefix . 'cw_dialogs';
    $M = $wpdb->prefix . 'cw_messages';

    if ($r->get_method() === 'POST') {
        $client_key  = cw_get_client_key_from_request($r);
        $ip          = cw_get_real_ip();
        $client_info = cw_client_info_from_request($r);
        $ua          = $client_info['ua'] ?: ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $ua          = sanitize_text_field($ua);

        $browser_label = cw_format_browser_label($ua, $client_info);

        $wpdb->insert($D, [
            'status'      => 'open',
            'client_key'  => $client_key,
            'geo_country' => '',
            'geo_city'    => '',
            'geo_region'  => '',
            'geo_org'     => '',
            'geo_ip'      => $ip,
            'geo_browser' => $browser_label,
            'created_at'  => current_time('mysql')
        ]);

        $dialog_id = (int) $wpdb->insert_id;

        if ($dialog_id > 0 && function_exists('cw_api_dispatch_event')) {
            cw_api_dispatch_event('dialog.created', $dialog_id);
        }

        return ['id' => $dialog_id];
    }

    nocache_headers();

    $dialogs = $wpdb->get_results("
        SELECT d.*,
        (SELECT COUNT(*) FROM {$M} m
            WHERE m.dialog_id=d.id
              AND m.unread=1
              AND m.is_operator=0
        ) as unread
        FROM {$D} d
        ORDER BY d.id DESC
    ");

    $res = new WP_REST_Response($dialogs);
    $res->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    $res->header('Pragma', 'no-cache');
    $res->header('Expires', '0');
    return $res;
}



/* ============================================================
   CONTACT CARD
============================================================ */

function cw_rest_contact(WP_REST_Request $r) {
    global $wpdb;

    if (!current_user_can('manage_options')) {
        return new WP_REST_Response(['error' => 'permission_denied'], 403);
    }

    $id = intval($r['id']);
    if ($id <= 0) {
        return new WP_REST_Response(['error' => 'invalid_dialog_id'], 400);
    }

    cw_ensure_contact_columns();

    $D = $wpdb->prefix . 'cw_dialogs';
    $exists = (int) $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM {$D} WHERE id=%d", $id)
    );

    if (!$exists) {
        return new WP_REST_Response(['error' => 'dialog_not_found'], 404);
    }

    $contact = cw_get_dialog_contact($id, true);

    nocache_headers();

    $res = new WP_REST_Response($contact);
    $res->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    $res->header('Pragma', 'no-cache');
    $res->header('Expires', '0');
    return $res;
}

/* ============================================================
   GEO
============================================================ */

function cw_rest_geo(WP_REST_Request $r) {
    global $wpdb;

    $id = intval($r['id']);
    $D  = $wpdb->prefix . 'cw_dialogs';

    $guard = cw_require_dialog_access_or_403($r, $id);
    if ($guard !== true) return $guard;

    cw_ensure_dialog_geo_loaded($id);

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT geo_country, geo_city, geo_region, geo_org, geo_ip, geo_browser
             FROM {$D} WHERE id=%d",
            $id
        ),
        ARRAY_A
    );

    if (!$row) {
        return new WP_REST_Response(['error' => 'dialog_not_found'], 404);
    }

    return [
        'geo_country' => $row['geo_country'] ?? '',
        'geo_city'    => $row['geo_city'] ?? '',
        'geo_region'  => $row['geo_region'] ?? '',
        'geo_org'     => $row['geo_org'] ?? '',
        'geo_ip'      => $row['geo_ip'] ?? '',
        'geo_browser' => $row['geo_browser'] ?? '',
    ];
}

/* ============================================================
   MESSAGES
============================================================ */

function cw_rest_messages(WP_REST_Request $r) {
    global $wpdb;

    $id = intval($r['id']);
    $D  = $wpdb->prefix . 'cw_dialogs';
    $M  = $wpdb->prefix . 'cw_messages';

    $guard = cw_require_dialog_access_or_403($r, $id);
    if ($guard !== true) return $guard;

    $status = (string) $wpdb->get_var(
        $wpdb->prepare("SELECT status FROM {$D} WHERE id=%d", $id)
    );

    if ($r->get_method() === 'GET') {
        $after_id = max(0, intval($r->get_param('after_id')));

        if ($after_id > 0) {
            $msgs = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$M} WHERE dialog_id=%d AND id>%d ORDER BY id ASC",
                    $id,
                    $after_id
                )
            );
        } else {
            $msgs = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$M} WHERE dialog_id=%d ORDER BY id ASC", $id)
            );
        }

        $res = new WP_REST_Response($msgs);
        $res->header('X-Dialog-Status', $status);
        $res->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $res->header('Pragma', 'no-cache');
        $res->header('Expires', '0');
        return $res;
    }


    $isOp = intval($r->get_param('operator'));

    if ($isOp && !current_user_can('manage_options')) {
        return new WP_REST_Response(['error' => 'permission_denied'], 403);
    }

    if ($status === '') {
        return new WP_REST_Response(['error' => 'dialog_not_found'], 404);
    }

    if ($status === 'closed') {
        return new WP_REST_Response(['error' => 'dialog_closed'], 403);
    }

    if (!empty($_FILES['file']) && isset($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $file = $_FILES['file'];
        $maxSize = 20 * 1024 * 1024;
        $fileSize = isset($file['size']) ? max(0, intval($file['size'])) : 0;

        if ($fileSize > $maxSize) {
            return new WP_REST_Response(['error' => 'file_too_large'], 400);
        }

        if (!$isOp) {
            $rate_guard = cw_upload_rate_limit_check($r, $id);
            if ($rate_guard !== true) {
                return $rate_guard;
            }

            cw_upload_rate_limit_record($r, $id);

            $quota_guard = cw_upload_quota_check($r, $id, $fileSize);
            if ($quota_guard !== true) {
                return $quota_guard;
            }
        }

        $display_name   = cw_prepare_display_filename((string) ($file['name'] ?? ''));
        $converted_name = cw_transliterate_filename((string) ($file['name'] ?? ''));
        $file['name']   = $converted_name;

        $allowed_mimes = cw_upload_allowed_mimes();
        $mime_guard = cw_validate_uploaded_file_mime($file, $allowed_mimes);
        if ($mime_guard !== true) {
            return $mime_guard;
        }

        $overrides = [
            'test_form' => false,
            'mimes' => $allowed_mimes,
        ];

        add_filter('upload_dir', 'cw_chat_widget_upload_dir');
        $uploaded = wp_handle_upload($file, $overrides);
        remove_filter('upload_dir', 'cw_chat_widget_upload_dir');

        if (!is_array($uploaded) || !empty($uploaded['error'])) {
            $err = is_array($uploaded) ? ($uploaded['error'] ?? 'upload_error') : 'upload_error';
            return new WP_REST_Response(['error' => 'upload_failed', 'details' => $err], 400);
        }

        $url  = esc_url_raw($uploaded['url'] ?? '');
        $type = sanitize_text_field($uploaded['type'] ?? '');
        $name = $display_name ?: cw_prepare_display_filename(basename($uploaded['file'] ?? ''));

        if (!$url) {
            return new WP_REST_Response(['error' => 'upload_failed_no_url'], 400);
        }

        $msg = (strpos($type, 'image/') === 0)
            ? '[image]' . $url
            : '[file]' . $url . '|' . $name;

        $has_user_messages = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$M} WHERE dialog_id=%d AND is_operator=0",
                $id
            )
        );

        if ($isOp) {
            cw_mark_user_messages_read_by_operator($id);
        }

        if (!$isOp && $has_user_messages === 0) {
            cw_insert_system_message($id, cw_get_chat_consent_message());
        }

        $wpdb->insert($M, [
            'dialog_id'   => $id,
            'message'     => $msg,
            'is_operator' => $isOp,
            'unread'      => 1,
            'created_at'  => current_time('mysql')
        ]);

        $new_message_id = (int) $wpdb->insert_id;

        if (!$isOp && $has_user_messages === 0) {
            cw_insert_system_message($id, cw_get_first_reply_waiting_message());
        }

        if (!$isOp && $new_message_id > 0) {
            cw_upload_quota_record($r, $id, $fileSize);
        }

        if ($new_message_id > 0 && function_exists('cw_api_dispatch_event')) {
            cw_api_dispatch_event('message.created', $id, $new_message_id);
        }

        if (!$isOp && $new_message_id > 0) {
            if (function_exists('cw_tg_dispatch_message_notification_async')) {
                cw_tg_dispatch_message_notification_async($new_message_id);
            } elseif (function_exists('cw_tg_queue_message_notification')) {
                cw_tg_queue_message_notification($new_message_id);
            }

            if (function_exists('cw_max_dispatch_message_notification_async')) {
                cw_max_dispatch_message_notification_async($new_message_id);
            } elseif (function_exists('cw_max_queue_message_notification')) {
                cw_max_queue_message_notification($new_message_id);
            }
        }

        return ['status' => 'ok'];
    }

    $raw_msg = $r->get_param('message');
    if (!is_string($raw_msg)) $raw_msg = '';

    $msg = sanitize_text_field(mb_substr(trim($raw_msg), 0, 2000));
    if ($msg === '') {
        return new WP_REST_Response(['error' => 'empty_message'], 400);
    }

    $has_user_messages = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$M} WHERE dialog_id=%d AND is_operator=0",
            $id
        )
    );

    $client_key = cw_get_client_key_from_request($r);

    $command_result = cw_try_handle_chat_command(
        $id,
        $client_key,
        $msg,
        (bool) $isOp,
        $status
    );

    if (is_array($command_result)) {
        return $command_result;
    }

    $bot_command_result = function_exists('cw_bot_try_handle_dialog_command')
        ? cw_bot_try_handle_dialog_command($id, $msg, (bool) $isOp, $status, $has_user_messages)
        : null;

    if (is_array($bot_command_result)) {
        return $bot_command_result;
    }

    if ($isOp) {
        cw_mark_user_messages_read_by_operator($id);
    }

    if (!$isOp && $has_user_messages === 0) {
        cw_insert_system_message($id, cw_get_chat_consent_message());
    }

    $wpdb->insert($M, [
        'dialog_id'   => $id,
        'message'     => $msg,
        'is_operator' => $isOp,
        'unread'      => 1,
        'created_at'  => current_time('mysql')
    ]);

    $new_message_id = (int) $wpdb->insert_id;

    if (!$isOp && $new_message_id > 0) {
        cw_update_dialog_contact_from_message($id, $msg);
    }

    $bot_result = ['handled' => false];

    if (!$isOp && $new_message_id > 0 && function_exists('cw_bot_try_reply')) {
        $bot_result = (array) cw_bot_try_reply($id, $msg, $new_message_id);
    }

    if (!$isOp && $has_user_messages === 0 && empty($bot_result['handled'])) {
        cw_insert_system_message($id, cw_get_first_reply_waiting_message());
    }

    if ($new_message_id > 0 && function_exists('cw_api_dispatch_event')) {
        cw_api_dispatch_event('message.created', $id, $new_message_id);
    }

    if (!$isOp && $new_message_id > 0 && empty($bot_result['handled'])) {
        if (function_exists('cw_tg_dispatch_message_notification_async')) {
            cw_tg_dispatch_message_notification_async($new_message_id);
        } elseif (function_exists('cw_tg_queue_message_notification')) {
            cw_tg_queue_message_notification($new_message_id);
        }

        if (function_exists('cw_max_dispatch_message_notification_async')) {
            cw_max_dispatch_message_notification_async($new_message_id);
        } elseif (function_exists('cw_max_queue_message_notification')) {
            cw_max_queue_message_notification($new_message_id);
        }
    }

    return ['status' => 'ok'];
}

/* ============================================================
   CLOSE
============================================================ */

function cw_rest_close(WP_REST_Request $r) {
    global $wpdb;

    $id = intval($r['id']);
    $D  = $wpdb->prefix . 'cw_dialogs';
    $M  = $wpdb->prefix . 'cw_messages';

    if ($id <= 0) {
        return new WP_REST_Response(['error' => 'dialog_not_found'], 404);
    }

    $status = (string) $wpdb->get_var(
        $wpdb->prepare("SELECT status FROM {$D} WHERE id=%d", $id)
    );

    if ($status === '') {
        return new WP_REST_Response(['error' => 'dialog_not_found'], 404);
    }

    if ($status === 'closed') {
        return ['status' => 'already_closed'];
    }

    cw_mark_user_messages_read_by_operator($id);

    $wpdb->update(
        $D,
        ['status' => 'closed'],
        ['id' => $id]
    );

    $wpdb->insert($M, [
        'dialog_id'   => $id,
        'message'     => '[system]Диалог закрыт оператором.',
        'is_operator' => 1,
        'unread'      => 1,
        'created_at'  => current_time('mysql')
    ]);

    return ['status' => 'closed'];
}

/* ============================================================
   DELETE
============================================================ */

function cw_rest_delete(WP_REST_Request $r) {
    global $wpdb;

    $id = intval($r['id']);
    if ($id <= 0) {
        return new WP_REST_Response(['error' => 'invalid_dialog_id'], 400);
    }

    $D = $wpdb->prefix . 'cw_dialogs';
    $M = $wpdb->prefix . 'cw_messages';

    $dialog_exists = (int) $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM {$D} WHERE id = %d", $id)
    );

    if ($dialog_exists <= 0) {
        return new WP_REST_Response(['error' => 'dialog_not_found'], 404);
    }

    $messages = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT message FROM {$M} WHERE dialog_id = %d",
            $id
        )
    );

    $uploads = wp_upload_dir();
    $baseurl = isset($uploads['baseurl']) ? rtrim((string) $uploads['baseurl'], '/') : '';
    $basedir = isset($uploads['basedir']) ? rtrim((string) $uploads['basedir'], DIRECTORY_SEPARATOR) : '';

    $chat_widget_baseurl = $baseurl !== '' ? $baseurl . '/chat-widget/' : '';
    $chat_widget_basedir = $basedir !== '' ? wp_normalize_path($basedir . '/chat-widget/') : '';

    $deleted_files = [];
    $seen_paths = [];

    if ($chat_widget_baseurl !== '' && $chat_widget_basedir !== '') {
        foreach ((array) $messages as $raw_message) {
            $message = trim((string) $raw_message);
            if ($message === '') {
                continue;
            }

            $file_url = '';

            if (stripos($message, '[image]') === 0) {
                $file_url = trim((string) mb_substr($message, 7));
            } elseif (stripos($message, '[file]') === 0) {
                $payload = trim((string) mb_substr($message, 6));
                $pos = strpos($payload, '|');
                $file_url = $pos !== false
                    ? trim((string) substr($payload, 0, $pos))
                    : trim($payload);
            }

            if ($file_url === '') {
                continue;
            }

            $file_url = html_entity_decode($file_url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $file_url = trim($file_url);

            if ($file_url === '' || strpos($file_url, $chat_widget_baseurl) !== 0) {
                continue;
            }

            $relative = ltrim((string) substr($file_url, strlen($chat_widget_baseurl)), '/');
            if ($relative === '') {
                continue;
            }

            $relative = explode('?', $relative, 2)[0];
            $relative = explode('#', $relative, 2)[0];
            $relative = wp_normalize_path(rawurldecode($relative));

            if ($relative === '' || strpos($relative, '../') !== false) {
                continue;
            }

            $full_path = wp_normalize_path($chat_widget_basedir . $relative);

            if (strpos($full_path, $chat_widget_basedir) !== 0) {
                continue;
            }

            if (isset($seen_paths[$full_path])) {
                continue;
            }
            $seen_paths[$full_path] = true;

            if (file_exists($full_path) && is_file($full_path) && is_writable($full_path)) {
                if (@unlink($full_path)) {
                    $deleted_files[] = $full_path;
                }
            }
        }
    }

    $wpdb->delete($M, ['dialog_id' => $id]);
    $wpdb->delete($D, ['id' => $id]);

    return [
        'status'         => 'deleted',
        'deleted_files'  => count($deleted_files),
        'deleted_dialog' => $id,
    ];
}

/* ============================================================
   MESSAGE STATUSES
============================================================ */

function cw_rest_message_statuses(WP_REST_Request $r) {
    global $wpdb;

    $id = intval($r['id']);
    if ($id <= 0) {
        return new WP_REST_Response(['error' => 'dialog_not_found'], 404);
    }

    $D = $wpdb->prefix . 'cw_dialogs';
    $M = $wpdb->prefix . 'cw_messages';

    $dialog_exists = (int) $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM {$D} WHERE id = %d", $id)
    );

    if (!$dialog_exists) {
        return new WP_REST_Response(['error' => 'dialog_not_found'], 404);
    }

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, unread
             FROM {$M}
             WHERE dialog_id = %d
               AND is_operator = 1
             ORDER BY id ASC",
            $id
        ),
        ARRAY_A
    );

    $res = new WP_REST_Response($rows ?: []);
    $res->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    $res->header('Pragma', 'no-cache');
    $res->header('Expires', '0');
    return $res;
}

/* ============================================================
   READ
============================================================ */

function cw_rest_read(WP_REST_Request $r) {
    global $wpdb;

    $id = intval($r['id']);

    $guard = cw_require_dialog_access_or_403($r, $id);
    if ($guard !== true) {
        return $guard;
    }

    $last_id = intval($r->get_param('last_read_message_id'));
    if ($last_id <= 0) {
        return ['status' => 'ok'];
    }

    $D = $wpdb->prefix . 'cw_dialogs';
    $M = $wpdb->prefix . 'cw_messages';

    $dialog = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, client_key FROM {$D} WHERE id = %d",
            $id
        ),
        ARRAY_A
    );

    if (!$dialog) {
        return new WP_REST_Response(['error' => 'dialog_not_found'], 404);
    }

    $db_client_key  = (string) ($dialog['client_key'] ?? '');
    $req_client_key = cw_get_client_key_from_request($r);

    $isClientReader = (
        $db_client_key !== '' &&
        $req_client_key !== '' &&
        hash_equals($db_client_key, $req_client_key)
    );

    if ($isClientReader) {
        $target_is_operator = 1;
    } elseif (current_user_can('manage_options')) {
        $target_is_operator = 0;
    } else {
        $target_is_operator = 1;
    }

    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$M}
             SET unread = 0
             WHERE dialog_id = %d
               AND id <= %d
               AND is_operator = %d
               AND unread = 1",
            $id,
            $last_id,
            $target_is_operator
        )
    );

    if ($target_is_operator === 1 && function_exists('cw_max_mark_reply_receipts_read_up_to')) {
        cw_max_mark_reply_receipts_read_up_to($id, $last_id);
    }

    return ['status' => 'ok'];
}