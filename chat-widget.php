<?php
/*
Plugin Name: Chat Widget
Description: Онлайн-чат с оператором + Telegram и MAX интеграция.
Version: 8.3
Author: Fakel
*/

if (!defined('ABSPATH')) exit;

/* ============================================================
   INCLUDES
============================================================ */

require_once __DIR__ . '/inc/rest.php';
require_once __DIR__ . '/inc/telegram.php';
require_once __DIR__ . '/inc/telegram-settings.php';
require_once __DIR__ . '/inc/max.php';
require_once __DIR__ . '/inc/max-settings.php';
require_once __DIR__ . '/inc/commands-settings.php';
require_once __DIR__ . '/inc/bot.php';
require_once __DIR__ . '/inc/bot-settings.php';
require_once __DIR__ . '/admin/operator-panel.php';

/* ============================================================
   DB SCHEMA
============================================================ */

function cw_create_or_update_tables(): void {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset = $wpdb->get_charset_collate();

    $table_dialogs  = $wpdb->prefix . 'cw_dialogs';
    $table_messages = $wpdb->prefix . 'cw_messages';

    $sql_dialogs = "CREATE TABLE {$table_dialogs} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        client_key VARCHAR(190) NOT NULL DEFAULT '',
        geo_country VARCHAR(190) NOT NULL DEFAULT '',
        geo_city VARCHAR(190) NOT NULL DEFAULT '',
        geo_region VARCHAR(190) NOT NULL DEFAULT '',
        geo_org VARCHAR(190) NOT NULL DEFAULT '',
        geo_ip VARCHAR(100) NOT NULL DEFAULT '',
        geo_browser TEXT NULL,
        contact_email VARCHAR(190) NOT NULL DEFAULT '',
        contact_phone VARCHAR(80) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY status (status),
        KEY client_key (client_key(190))
    ) {$charset};";

    $sql_messages = "CREATE TABLE {$table_messages} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        dialog_id BIGINT UNSIGNED NOT NULL,
        message LONGTEXT NOT NULL,
        is_operator TINYINT(1) NOT NULL DEFAULT 0,
        is_bot TINYINT(1) NOT NULL DEFAULT 0,
        unread TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY dialog_id (dialog_id),
        KEY unread (unread),
        KEY is_operator (is_operator),
        KEY is_bot (is_bot)
    ) {$charset};";

    dbDelta($sql_dialogs);
    dbDelta($sql_messages);

    $has_geo_org = $wpdb->get_var("SHOW COLUMNS FROM {$table_dialogs} LIKE 'geo_org'");
    if (!$has_geo_org) {
        $wpdb->query("ALTER TABLE {$table_dialogs} ADD COLUMN geo_org VARCHAR(190) NOT NULL DEFAULT '' AFTER geo_region");
    }

    $has_geo_isp = $wpdb->get_var("SHOW COLUMNS FROM {$table_dialogs} LIKE 'geo_isp'");
    if ($has_geo_isp && !$has_geo_org) {
        $wpdb->query("UPDATE {$table_dialogs} SET geo_org = geo_isp WHERE geo_org = ''");
    }

    $has_contact_email = $wpdb->get_var("SHOW COLUMNS FROM {$table_dialogs} LIKE 'contact_email'");
    if (!$has_contact_email) {
        $wpdb->query("ALTER TABLE {$table_dialogs} ADD COLUMN contact_email VARCHAR(190) NOT NULL DEFAULT '' AFTER geo_browser");
    }

    $has_contact_phone = $wpdb->get_var("SHOW COLUMNS FROM {$table_dialogs} LIKE 'contact_phone'");
    if (!$has_contact_phone) {
        $wpdb->query("ALTER TABLE {$table_dialogs} ADD COLUMN contact_phone VARCHAR(80) NOT NULL DEFAULT '' AFTER contact_email");
    }

    $has_is_bot = $wpdb->get_var("SHOW COLUMNS FROM {$table_messages} LIKE 'is_bot'");
    if (!$has_is_bot) {
        $wpdb->query("ALTER TABLE {$table_messages} ADD COLUMN is_bot TINYINT(1) NOT NULL DEFAULT 0 AFTER is_operator");
        $wpdb->query("ALTER TABLE {$table_messages} ADD KEY is_bot (is_bot)");
    }
}

/* ============================================================
   ACTIVATION: TABLES
============================================================ */

register_activation_hook(__FILE__, function () {
    cw_create_or_update_tables();
});


/* ============================================================
   FRONTEND VISIBILITY
============================================================ */

function cw_should_render_frontend_widget(): bool {
    if (is_admin()) {
        return false;
    }

    if (function_exists('is_404') && is_404()) {
        return false;
    }

    return true;
}

/* ============================================================
   FRONTEND ASSETS
============================================================ */

add_action('wp_enqueue_scripts', function () {
    if (!cw_should_render_frontend_widget()) {
        return;
    }

    wp_enqueue_style(
        'cw-style',
        plugin_dir_url(__FILE__) . 'chat-widget.css',
        [],
        file_exists(__DIR__ . '/chat-widget.css')
            ? filemtime(__DIR__ . '/chat-widget.css')
            : '1.0'
    );

    wp_enqueue_script('jquery');
});

/* ============================================================
   FRONTEND HTML + LAZY LOADER
============================================================ */

add_action('wp_footer', function () {
    if (!cw_should_render_frontend_widget()) {
        return;
    }

    $shared_url = plugin_dir_url(__FILE__) . 'cw-shared.js';
    $widget_url = plugin_dir_url(__FILE__) . 'chat-widget.js';
    $teaser_url = plugin_dir_url(__FILE__) . 'chat-teaser.js';

    $shared_ver = file_exists(__DIR__ . '/cw-shared.js')
        ? filemtime(__DIR__ . '/cw-shared.js')
        : '1.0';

    $widget_ver = file_exists(__DIR__ . '/chat-widget.js')
        ? filemtime(__DIR__ . '/chat-widget.js')
        : '1.0';

    $teaser_ver = file_exists(__DIR__ . '/chat-teaser.js')
        ? filemtime(__DIR__ . '/chat-teaser.js')
        : '1.0';

    $shared_src = esc_url($shared_url . '?ver=' . rawurlencode((string) $shared_ver));
    $widget_src = esc_url($widget_url . '?ver=' . rawurlencode((string) $widget_ver));
    $teaser_src = esc_url($teaser_url . '?ver=' . rawurlencode((string) $teaser_ver));
    $notify_src = esc_url(plugin_dir_url(__FILE__) . 'assets/notify.mp3');
    $rest_root  = esc_url_raw(rest_url('cw/v1/'));
    $nonce      = wp_create_nonce('wp_rest');
    $consent_message = function_exists('cw_get_chat_consent_message')
        ? cw_get_chat_consent_message()
        : '';
?>
    <div id="cw-open-btn" role="button" aria-label="Открыть чат" data-cw-ready="0">
        <svg viewBox="0 0 24 24" width="24" height="24" fill="white" aria-hidden="true">
            <path d="M4 4C2.9 4 2 4.9 2 6v8c0 1.1.9 2 2 2h2v4l5.2-4H20c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2H4z"/>
        </svg>
        <span id="cw-badge" style="display:none;"></span>
    </div>

    <template id="cw-chat-template">
        <div id="cw-chat-box" style="display:none;">
            <div id="cw-header">
                <span>Чат с оператором</span>
                <span id="cw-close" role="button" aria-label="Закрыть чат">×</span>
            </div>

            <div id="cw-chat-window"></div>

            <div id="cw-input-box">
                <div class="cw-input-wrapper">
                    <input
                        id="cw-input"
                        type="text"
                        placeholder="Введите сообщение..."
                        autocomplete="off"
                    >

                    <button
                        type="button"
                        id="cw-file-btn"
                        aria-label="Прикрепить файл"
                    >📎</button>

                    <input
                        type="file"
                        id="cw-file"
                        accept="image/jpeg,image/png,image/webp,application/pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar"
                        style="display:none;"
                    >
                </div>

                <button
                    id="cw-send"
                    type="button"
                    title="Отправить"
                    aria-label="Отправить сообщение"
                >➤</button>
            </div>

            <div id="cw-new-dialog-btn">
                Начать новый диалог
            </div>
        </div>
    </template>

    <template id="cw-audio-template">
        <audio id="cw-sound" preload="none">
            <source src="<?php echo $notify_src; ?>" type="audio/mpeg">
        </audio>
    </template>

    <script>
    (function () {
        'use strict';

        var btn = document.getElementById('cw-open-btn');
        if (!btn) return;

        window.CW_API = {
            root: <?php echo wp_json_encode($rest_root); ?>,
            nonce: <?php echo wp_json_encode($nonce); ?>,
            consent_message: <?php echo wp_json_encode($consent_message); ?>
        };

        var sharedSrc = <?php echo wp_json_encode($shared_src); ?>;
        var widgetSrc = <?php echo wp_json_encode($widget_src); ?>;
        var teaserSrc = <?php echo wp_json_encode($teaser_src); ?>;

        var loadingPromise = null;
        var scriptsReady = false;
        var openAfterLoad = false;
        var preloadStarted = false;

        function injectTemplate(id) {
            var tpl = document.getElementById(id);
            if (!tpl || !('content' in tpl)) return;

            var firstEl = tpl.content.firstElementChild;
            if (!firstEl) return;

            if (document.getElementById(firstEl.id)) {
                return;
            }

            document.body.appendChild(tpl.content.cloneNode(true));
        }

        function ensureMarkup() {
            injectTemplate('cw-chat-template');
            injectTemplate('cw-audio-template');
        }

        function loadScript(src) {
            return new Promise(function (resolve, reject) {
                var existing = document.querySelector('script[data-cw-src="' + src + '"]');
                if (existing) {
                    if (existing.getAttribute('data-loaded') === '1') {
                        resolve();
                        return;
                    }

                    existing.addEventListener('load', function () {
                        resolve();
                    }, { once: true });

                    existing.addEventListener('error', function () {
                        reject(new Error('load_failed'));
                    }, { once: true });

                    return;
                }

                var s = document.createElement('script');
                s.src = src;
                s.async = false;
                s.defer = true;
                s.setAttribute('data-cw-src', src);

                s.onload = function () {
                    s.setAttribute('data-loaded', '1');
                    resolve();
                };

                s.onerror = function () {
                    reject(new Error('load_failed'));
                };

                document.body.appendChild(s);
            });
        }

        function finishOpenIfNeeded() {
            if (!openAfterLoad) return;

            openAfterLoad = false;

            setTimeout(function () {
                var clickEvent = new MouseEvent('click', {
                    bubbles: true,
                    cancelable: true,
                    view: window
                });

                btn.dispatchEvent(clickEvent);
            }, 0);
        }

        function startLazyLoad() {
            if (scriptsReady) {
                return Promise.resolve();
            }

            if (loadingPromise) {
                return loadingPromise;
            }

            preloadStarted = true;
            btn.classList.add('cw-loading');

            ensureMarkup();

            loadingPromise = loadScript(sharedSrc)
                .then(function () {
                    return loadScript(widgetSrc);
                })
                .then(function () {
                    return loadScript(teaserSrc);
                })
                .then(function () {
                    scriptsReady = true;
                    btn.setAttribute('data-cw-ready', '1');
                    btn.classList.remove('cw-loading');
                    finishOpenIfNeeded();
                })
                .catch(function (err) {
                    btn.classList.remove('cw-loading');
                    loadingPromise = null;
                    console.error('CW lazy load failed:', err);
                });

            return loadingPromise;
        }

        btn.addEventListener('click', function (e) {
            if (scriptsReady) {
                return;
            }

            e.preventDefault();
            e.stopPropagation();

            openAfterLoad = true;
            startLazyLoad();
        });

        ['mouseenter', 'focus', 'touchstart'].forEach(function (eventName) {
            btn.addEventListener(eventName, function () {
                if (!preloadStarted) {
                    startLazyLoad();
                }
            }, { passive: true });
        });

        if ('IntersectionObserver' in window) {
            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        startLazyLoad();
                        io.disconnect();
                    }
                });
            }, {
                rootMargin: '300px 0px 300px 0px'
            });

            io.observe(btn);
        }

        if ('requestIdleCallback' in window) {
            requestIdleCallback(function () {
                startLazyLoad();
            }, { timeout: 8000 });
        } else {
            setTimeout(function () {
                startLazyLoad();
            }, 8000);
        }
    })();
    </script>
<?php });

/* ============================================================
   ADMIN MENU HELPERS
============================================================ */

function cw_menu_status_label(string $title, bool $enabled): string {
    return $title . ' ' . ($enabled ? '🟢' : '🔴');
}

/* ============================================================
   ADMIN MENU
============================================================ */

add_action('admin_menu', function () {

    $tg_enabled  = function_exists('cw_tg_enabled') ? cw_tg_enabled() : true;
    $max_enabled = function_exists('cw_max_enabled') ? cw_max_enabled() : true;
    $bot_enabled = function_exists('cw_bot_enabled') ? cw_bot_enabled() : false;

    $tg_label  = cw_menu_status_label('Telegram', $tg_enabled);
    $max_label = cw_menu_status_label('MAX', $max_enabled);
    $bot_label = cw_menu_status_label('Бот', $bot_enabled);

    add_menu_page(
        'Чат',
        'Чат',
        'manage_options',
        'cw_operator',
        'cw_operator_panel_page',
        'dashicons-format-chat',
        25
    );

    add_submenu_page(
        'cw_operator',
        $tg_label,
        $tg_label,
        'manage_options',
        'cw_telegram',
        'cw_telegram_settings_page'
    );

    add_submenu_page(
        'cw_operator',
        $max_label,
        $max_label,
        'manage_options',
        'cw_max',
        'cw_max_settings_page'
    );

    add_submenu_page(
        'cw_operator',
        $bot_label,
        $bot_label,
        'manage_options',
        'cw_bot',
        'cw_bot_settings_page'
    );

    add_submenu_page(
        'cw_operator',
        'Команды',
        'Команды',
        'manage_options',
        'cw_commands',
        'cw_commands_settings_page'
    );
});

/* ============================================================
   ADMIN ASSETS
============================================================ */

add_action('admin_enqueue_scripts', function ($hook) {

    $page = isset($_GET['page'])
        ? sanitize_key(wp_unslash($_GET['page']))
        : '';

    $admin_pages = [
        'cw_operator',
        'cw_telegram',
        'cw_max',
        'cw_bot',
        'cw_commands',
    ];

    $admin_hooks = [
        'toplevel_page_cw_operator',
        'chat_page_cw_telegram',
        'cw_operator_page_cw_telegram',
        'chat_page_cw_max',
        'cw_operator_page_cw_max',
        'chat_page_cw_bot',
        'cw_operator_page_cw_bot',
        'chat_page_cw_commands',
        'cw_operator_page_cw_commands',
    ];

    $is_plugin_admin_page = in_array($page, $admin_pages, true) || in_array($hook, $admin_hooks, true);

    if (!$is_plugin_admin_page) {
        return;
    }

    wp_enqueue_style(
        'cw-operator-css',
        plugin_dir_url(__FILE__) . 'admin/operator-panel.css',
        [],
        file_exists(__DIR__ . '/admin/operator-panel.css')
            ? filemtime(__DIR__ . '/admin/operator-panel.css')
            : '1.0'
    );

    wp_enqueue_script(
        'cw-shared',
        plugin_dir_url(__FILE__) . 'cw-shared.js',
        [],
        file_exists(__DIR__ . '/cw-shared.js')
            ? filemtime(__DIR__ . '/cw-shared.js')
            : '1.0',
        true
    );

    $is_operator_page = ($page === 'cw_operator' || $hook === 'toplevel_page_cw_operator');

    if (!$is_operator_page) {
        return;
    }

    wp_enqueue_script(
        'cw-operator-js',
        plugin_dir_url(__FILE__) . 'admin/operator-panel.js',
        ['jquery', 'cw-shared'],
        file_exists(__DIR__ . '/admin/operator-panel.js')
            ? filemtime(__DIR__ . '/admin/operator-panel.js')
            : '1.0',
        true
    );

    wp_localize_script('cw-operator-js', 'CW_ADMIN_API', [
        'root'  => esc_url_raw(rest_url('cw/v1/')),
        'nonce' => wp_create_nonce('wp_rest')
    ]);
});