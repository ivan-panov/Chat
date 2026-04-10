<?php
if (!defined('ABSPATH')) exit;

/* ============================================================
   BOT OPTIONS
============================================================ */

function cw_bot_enabled(): bool {
    return (int) get_option('cw_bot_enabled', 1) === 1;
}

function cw_bot_name(): string {
    $value = trim((string) get_option('cw_bot_name', 'Бот'));
    return $value !== '' ? $value : 'Бот';
}

function cw_bot_command_label(): string {
    $value = trim((string) get_option('cw_bot_command_label', '/бот'));
    return $value !== '' ? $value : '/бот';
}

function cw_bot_operator_label(): string {
    $value = trim((string) get_option('cw_bot_operator_label', '/оператор'));
    return $value !== '' ? $value : '/оператор';
}

function cw_bot_stop_label(): string {
    $value = trim((string) get_option('cw_bot_stop_label', '/стоп'));
    return $value !== '' ? $value : '/стоп';
}

function cw_bot_allowed_html(): array {
    return [
        'a'      => [
            'href'   => true,
            'target' => true,
            'rel'    => true,
        ],
        'br'     => [],
        'strong' => [],
        'b'      => [],
        'em'     => [],
        'i'      => [],
    ];
}

function cw_bot_default_rules_text(): string {
    return implode("\n", [
        '# Формат: ключ1|ключ2 => Ответ',
        '# Можно использовать плейсхолдеры: {bot_name}, {operator_command}, {bot_command}, {stop_command}',
        '',
        'доставка|статус доставки|когда доставка => По доставке напишите номер заказа и город. Если нужен человек, отправьте {operator_command}.',
        'оплата|счет|счёт|как оплатить => По оплате напишите номер заказа или что хотите оплатить. Если нужен оператор, отправьте {operator_command}.',
        'адрес|офис|самовывоз => Напишите, какой адрес вам нужен. Если нужен человек, отправьте {operator_command}.',
        'график|режим работы|время работы|часы работы => Напишите, по какому вопросу обращаетесь, и я помогу. Для оператора отправьте {operator_command}.',
        'контакты|телефон|почта|email => Напишите, какие контакты вам нужны. Если нужен оператор, отправьте {operator_command}.',
    ]);
}

function cw_bot_rules_text(): string {
    $value = (string) get_option('cw_bot_rules_text', '');
    $value = trim($value);

    if ($value !== '') {
        return $value;
    }

    return cw_bot_default_rules_text();
}

/* ============================================================
   BOT DIALOG STATE
============================================================ */

function cw_bot_get_dialog_state_map(): array {
    $map = get_option('cw_bot_dialog_state', []);
    return is_array($map) ? $map : [];
}

function cw_bot_save_dialog_state_map(array $map): void {
    update_option('cw_bot_dialog_state', $map, false);
}

function cw_bot_cleanup_dialog_state(): void {
    $map = cw_bot_get_dialog_state_map();
    if (!$map) return;

    $now = time();
    $changed = false;
    $ttl = 30 * DAY_IN_SECONDS;

    foreach ($map as $dialog_id => $item) {
        if (!is_array($item)) {
            unset($map[$dialog_id]);
            $changed = true;
            continue;
        }

        $updated_at = (int) ($item['updated_at'] ?? 0);
        if ($updated_at > 0 && ($updated_at + $ttl) < $now) {
            unset($map[$dialog_id]);
            $changed = true;
        }
    }

    if ($changed) {
        cw_bot_save_dialog_state_map($map);
    }
}

function cw_bot_dialog_is_paused(int $dialog_id): bool {
    if ($dialog_id <= 0) return false;

    cw_bot_cleanup_dialog_state();

    $map = cw_bot_get_dialog_state_map();
    $item = $map[(string) $dialog_id] ?? null;

    if (!is_array($item)) {
        return false;
    }

    return !empty($item['paused']);
}

function cw_bot_set_dialog_paused(int $dialog_id, bool $paused): void {
    if ($dialog_id <= 0) return;

    cw_bot_cleanup_dialog_state();

    $map = cw_bot_get_dialog_state_map();
    $key = (string) $dialog_id;

    if (!isset($map[$key]) || !is_array($map[$key])) {
        $map[$key] = [];
    }

    $map[$key]['paused'] = $paused ? 1 : 0;
    $map[$key]['updated_at'] = time();

    cw_bot_save_dialog_state_map($map);
}

/* ============================================================
   BOT HELPERS
============================================================ */

function cw_bot_normalize_text(string $text): string {
    $text = wp_strip_all_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = mb_strtolower($text, 'UTF-8');
    $text = str_replace('ё', 'е', $text);
    $text = preg_replace('/[^\p{L}\p{N}\s\/\-\._#]+/u', ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim((string) $text);
}

function cw_bot_render_template(string $text): string {
    return strtr($text, [
        '{bot_name}'         => cw_bot_name(),
        '{operator_command}' => cw_bot_operator_label(),
        '{bot_command}'      => cw_bot_command_label(),
        '{stop_command}'     => cw_bot_stop_label(),
    ]);
}

function cw_bot_prepare_reply_text(string $text): string {
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = str_replace(['\\n', '\\r'], ["\n", ''], $text);
    $text = cw_bot_render_template($text);
    $text = wp_kses($text, cw_bot_allowed_html());
    return trim($text);
}

function cw_bot_operator_handoff_text(): string {
    return cw_bot_prepare_reply_text(
        'Хорошо, подключаю оператора. Напишите вопрос одним сообщением — так мы быстрее поможем.'
    );
}

function cw_bot_resume_text(): string {
    return cw_bot_prepare_reply_text(
        '{bot_name} снова включён. Можете задать вопрос. Для подключения человека используйте {operator_command}.'
    );
}

function cw_bot_insert_reply(int $dialog_id, string $text, int $unread = 1): int {
    global $wpdb;

    if ($dialog_id <= 0) return 0;

    $text = cw_bot_prepare_reply_text($text);
    if ($text === '') return 0;

    $ok = $wpdb->insert($wpdb->prefix . 'cw_messages', [
        'dialog_id'   => $dialog_id,
        'message'     => $text,
        'is_operator' => 1,
        'is_bot'      => 1,
        'unread'      => $unread,
        'created_at'  => current_time('mysql'),
    ]);

    return $ok ? (int) $wpdb->insert_id : 0;
}

function cw_bot_insert_user_message(int $dialog_id, string $text, int $unread = 1): int {
    global $wpdb;

    if ($dialog_id <= 0) return 0;

    $text = sanitize_text_field(mb_substr(trim($text), 0, 2000));
    if ($text === '') return 0;

    $ok = $wpdb->insert($wpdb->prefix . 'cw_messages', [
        'dialog_id'   => $dialog_id,
        'message'     => $text,
        'is_operator' => 0,
        'unread'      => $unread,
        'created_at'  => current_time('mysql'),
    ]);

    return $ok ? (int) $wpdb->insert_id : 0;
}

function cw_bot_notify_operator_about_message_id(int $message_id): void {
    if ($message_id <= 0) return;

    if (function_exists('cw_tg_dispatch_message_notification_async')) {
        cw_tg_dispatch_message_notification_async($message_id);
    } elseif (function_exists('cw_tg_queue_message_notification')) {
        cw_tg_queue_message_notification($message_id);
    }

    if (function_exists('cw_max_dispatch_message_notification_async')) {
        cw_max_dispatch_message_notification_async($message_id);
    } elseif (function_exists('cw_max_queue_message_notification')) {
        cw_max_queue_message_notification($message_id);
    }
}

function cw_bot_parse_rules(string $rules_text): array {
    $rules_text = html_entity_decode($rules_text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $rules_text = str_replace(["\r\n", "\r"], "\n", $rules_text);

    $lines = explode("\n", $rules_text);
    $rules = [];

    foreach ($lines as $line) {
        $line = trim((string) $line);

        if ($line === '') continue;
        if (strpos($line, '#') === 0) continue;

        $line = str_replace('=&gt;', '=>', $line);

        if (strpos($line, '=>') === false) {
            continue;
        }

        [$left, $right] = array_pad(explode('=>', $line, 2), 2, '');

        $left  = trim((string) $left);
        $right = trim((string) $right);

        if ($left === '' || $right === '') {
            continue;
        }

        $keywords = preg_split('/\|/u', $left);
        $clean_keywords = [];

        foreach ($keywords as $keyword) {
            $normalized = cw_bot_normalize_text((string) $keyword);
            if ($normalized !== '') {
                $clean_keywords[$normalized] = $normalized;
            }
        }

        if (!$clean_keywords) {
            continue;
        }

        $rules[] = [
            'keywords' => array_values($clean_keywords),
            'reply'    => $right,
        ];
    }

    return $rules;
}

function cw_bot_find_best_rule(string $message, array $rules): ?array {
    $normalized_message = cw_bot_normalize_text($message);
    if ($normalized_message === '') {
        return null;
    }

    $best_rule = null;
    $best_score = 0;

    foreach ($rules as $rule) {
        if (empty($rule['keywords']) || !is_array($rule['keywords'])) {
            continue;
        }

        foreach ($rule['keywords'] as $keyword) {
            $keyword = cw_bot_normalize_text((string) $keyword);
            if ($keyword === '') continue;

            if (mb_strpos($normalized_message, $keyword, 0, 'UTF-8') === false) {
                continue;
            }

            $score = mb_strlen($keyword, 'UTF-8');

            if ($score > $best_score) {
                $best_score = $score;
                $best_rule = $rule;
                $best_rule['matched_keyword'] = $keyword;
            }
        }
    }

    return $best_rule;
}

/* ============================================================
   BOT COMMANDS
============================================================ */

function cw_bot_try_handle_dialog_command(
    int $dialog_id,
    string $message,
    bool $is_operator,
    string $dialog_status,
    int $has_user_messages = 0
) {
    if ($is_operator) return null;
    if (!cw_bot_enabled()) return null;

    $normalized = cw_bot_normalize_text($message);
    if ($normalized === '') return null;

    $bot_cmd      = cw_bot_normalize_text(cw_bot_command_label());
    $operator_cmd = cw_bot_normalize_text(cw_bot_operator_label());
    $stop_cmd     = cw_bot_normalize_text(cw_bot_stop_label());

    if ($normalized === $bot_cmd) {
        cw_bot_set_dialog_paused($dialog_id, false);
        cw_bot_insert_reply($dialog_id, cw_bot_resume_text());

        return [
            'status'      => 'ok',
            'bot_command' => 'resume',
        ];
    }

    if ($normalized === $operator_cmd || $normalized === $stop_cmd) {
        if ($dialog_status === 'closed') {
            return ['status' => 'ok'];
        }

        if ($has_user_messages === 0 && function_exists('cw_insert_system_message') && function_exists('cw_get_chat_consent_message')) {
            cw_insert_system_message($dialog_id, cw_get_chat_consent_message());
        }

        $user_note = ($normalized === $stop_cmd)
            ? 'Бот остановлен. Нужен оператор.'
            : 'Нужен оператор.';

        $new_user_message_id = cw_bot_insert_user_message($dialog_id, $user_note);

        cw_bot_set_dialog_paused($dialog_id, true);
        cw_bot_insert_reply($dialog_id, cw_bot_operator_handoff_text());

        if ($new_user_message_id > 0) {
            cw_bot_notify_operator_about_message_id($new_user_message_id);
        }

        return [
            'status'      => 'ok',
            'bot_command' => 'handoff',
        ];
    }

    return null;
}

/* ============================================================
   BOT AUTO REPLY
============================================================ */

function cw_bot_try_reply(int $dialog_id, string $message, int $source_message_id = 0): array {
    unset($source_message_id);

    $result = [
        'handled'         => false,
        'reply_sent'      => false,
        'handoff'         => false,
        'matched_keyword' => '',
    ];

    if (!cw_bot_enabled()) {
        return $result;
    }

    if ($dialog_id <= 0) {
        return $result;
    }

    if (cw_bot_dialog_is_paused($dialog_id)) {
        return $result;
    }

    $rules = cw_bot_parse_rules(cw_bot_rules_text());
    if (!$rules) {
        return $result;
    }

    $match = cw_bot_find_best_rule($message, $rules);
    if (!$match || empty($match['reply'])) {
        return $result;
    }

    $reply_id = cw_bot_insert_reply($dialog_id, (string) $match['reply']);
    if ($reply_id <= 0) {
        return $result;
    }

    $result['handled'] = true;
    $result['reply_sent'] = true;
    $result['matched_keyword'] = (string) ($match['matched_keyword'] ?? '');

    return $result;
}