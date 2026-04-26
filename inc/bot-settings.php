<?php
if (!defined('ABSPATH')) exit;

function cw_bot_render_settings_alert(string $type, string $title, string $message): void {
    $type = in_array($type, ['success', 'warning', 'info', 'error'], true) ? $type : 'info';

    $icons = [
        'success' => 'OK',
        'warning' => '!',
        'info'    => 'i',
        'error'   => '!',
    ];

    $allowed_html = [
        'br'     => [],
        'strong' => [],
        'b'      => [],
        'em'     => [],
        'code'   => [],
    ];
    ?>
    <div class="cw-settings-alert cw-settings-alert-<?php echo esc_attr($type); ?>" role="status">
        <div class="cw-settings-alert-icon" aria-hidden="true"><?php echo esc_html($icons[$type]); ?></div>
        <div class="cw-settings-alert-content">
            <div class="cw-settings-alert-title"><?php echo esc_html($title); ?></div>
            <?php if ($message !== ''): ?>
                <div class="cw-settings-alert-body"><?php echo wp_kses($message, $allowed_html); ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}


function cw_bot_settings_parse_rule_rows(string $rules_text): array {
    $rules_text = html_entity_decode($rules_text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $rules_text = str_replace(["\r\n", "\r"], "\n", $rules_text);

    $rows = [];
    foreach (explode("\n", $rules_text) as $line) {
        $line = trim((string) $line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }

        $line = str_replace('=&gt;', '=>', $line);
        if (strpos($line, '=>') === false) {
            continue;
        }

        [$keywords, $reply] = array_pad(explode('=>', $line, 2), 2, '');
        $keywords = trim((string) $keywords);
        $reply = trim((string) $reply);

        if ($keywords === '' || $reply === '') {
            continue;
        }

        $rows[] = [
            'keywords' => $keywords,
            'reply'    => $reply,
        ];
    }

    return $rows;
}

function cw_bot_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Access denied');
    }

    $saved = false;

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        check_admin_referer('cw_bot_settings_nonce');

        $enabled = !empty($_POST['cw_bot_enabled']) ? 1 : 0;
        $bot_name = sanitize_text_field(wp_unslash($_POST['cw_bot_name'] ?? 'Бот'));
        $bot_command = sanitize_text_field(wp_unslash($_POST['cw_bot_command_label'] ?? '/бот'));
        $operator_command = sanitize_text_field(wp_unslash($_POST['cw_bot_operator_label'] ?? '/оператор'));
        $stop_command = sanitize_text_field(wp_unslash($_POST['cw_bot_stop_label'] ?? '/стоп'));

        $rules_text_raw = (string) wp_unslash($_POST['cw_bot_rules_text'] ?? '');
        $rules_text_raw = html_entity_decode($rules_text_raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $rules_text = wp_kses(
            $rules_text_raw,
            function_exists('cw_bot_allowed_html') ? cw_bot_allowed_html() : []
        );

        update_option('cw_bot_enabled', $enabled);
        update_option('cw_bot_name', $bot_name !== '' ? $bot_name : 'Бот');
        update_option('cw_bot_command_label', $bot_command !== '' ? $bot_command : '/бот');
        update_option('cw_bot_operator_label', $operator_command !== '' ? $operator_command : '/оператор');
        update_option('cw_bot_stop_label', $stop_command !== '' ? $stop_command : '/стоп');
        update_option('cw_bot_rules_text', trim($rules_text));

        $saved = true;
    }

    $enabled          = (int) get_option('cw_bot_enabled', 1);
    $bot_name         = (string) get_option('cw_bot_name', 'Бот');
    $bot_command      = (string) get_option('cw_bot_command_label', '/бот');
    $operator_command = (string) get_option('cw_bot_operator_label', '/оператор');
    $stop_command     = (string) get_option('cw_bot_stop_label', '/стоп');
    $rules_text       = (string) get_option('cw_bot_rules_text', '');

    if (trim($rules_text) === '' && function_exists('cw_bot_default_rules_text')) {
        $rules_text = cw_bot_default_rules_text();
    }

    $rule_rows = cw_bot_settings_parse_rule_rows($rules_text);
    ?>
    <div class="wrap cw-settings-page cw-bot-settings-page">
        <div class="cw-settings-hero">
            <div>
                <div class="cw-settings-kicker">Chat Widget</div>
                <h1>Бот</h1>
                <p>Настройка автоответов, команд и правил обработки сообщений.</p>
            </div>
            <div class="cw-settings-status <?php echo $enabled ? 'is-active' : 'is-inactive'; ?>">
                <span class="cw-settings-status-icon" aria-hidden="true">
                    <?php if ($enabled): ?>
                        <svg viewBox="0 0 24 24" focusable="false">
                            <path d="M20 6L9 17l-5-5"></path>
                        </svg>
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" focusable="false">
                            <path d="M12 3v9"></path>
                            <path d="M6.35 7.35a8 8 0 1 0 11.3 0"></path>
                        </svg>
                    <?php endif; ?>
                </span>
                <span><?php echo $enabled ? 'Бот включён' : 'Бот выключен'; ?></span>
            </div>
        </div>

        <div class="cw-settings-notices" aria-live="polite">
            <?php
            if ($enabled) {
                cw_bot_render_settings_alert(
                    'success',
                    'Бот включён',
                    'Автоответ работает по правилам ниже. Команда <code>' . esc_html($operator_command) . '</code> отключает бота в текущем диалоге и зовёт оператора.'
                );
            } else {
                cw_bot_render_settings_alert(
                    'warning',
                    'Бот выключен',
                    'Автоответы отключены. Все сообщения будут уходить оператору как обычно.'
                );
            }

            if ($saved) {
                cw_bot_render_settings_alert('success', 'Настройки сохранены', 'Изменения применены.');
            }
            ?>
        </div>

        <form method="post" class="cw-settings-form">
            <?php wp_nonce_field('cw_bot_settings_nonce'); ?>

            <div class="cw-settings-grid">
                <section class="cw-settings-card">
                    <div class="cw-settings-card-head">
                        <div class="cw-settings-card-icon">BOT</div>
                        <div>
                            <h2>Основные настройки</h2>
                            <p>Включение автоответа и имя, которое используется в шаблонах.</p>
                        </div>
                    </div>

                    <div class="cw-settings-field is-checkbox">
                        <label class="cw-settings-check">
                            <input type="checkbox" name="cw_bot_enabled" value="1" <?php checked($enabled, 1); ?>>
                            <span>
                                <strong>Бот активен</strong>
                                <em>Если выключить, автоответы не будут отправляться, а сообщения продолжат приходить оператору.</em>
                            </span>
                        </label>
                    </div>

                    <div class="cw-settings-field">
                        <label for="cw_bot_name">Имя бота</label>
                        <input id="cw_bot_name" type="text" name="cw_bot_name" value="<?php echo esc_attr($bot_name); ?>" autocomplete="off" />
                        <p>Используется в плейсхолдере <code>{bot_name}</code>.</p>
                    </div>
                </section>

                <section class="cw-settings-card">
                    <div class="cw-settings-card-head">
                        <div class="cw-settings-card-icon">CMD</div>
                        <div>
                            <h2>Команды</h2>
                            <p>Фразы, которыми пользователь управляет автоответами в текущем диалоге.</p>
                        </div>
                    </div>

                    <div class="cw-settings-field">
                        <label for="cw_bot_command_label">Команда включения</label>
                        <input id="cw_bot_command_label" type="text" name="cw_bot_command_label" value="<?php echo esc_attr($bot_command); ?>" autocomplete="off" />
                        <p>Эта команда снова включает автоответы в текущем диалоге.</p>
                    </div>

                    <div class="cw-settings-field">
                        <label for="cw_bot_operator_label">Команда оператора</label>
                        <input id="cw_bot_operator_label" type="text" name="cw_bot_operator_label" value="<?php echo esc_attr($operator_command); ?>" autocomplete="off" />
                        <p>Эта команда отключает бота в текущем диалоге и сразу зовёт оператора.</p>
                    </div>

                    <div class="cw-settings-field">
                        <label for="cw_bot_stop_label">Команда остановки</label>
                        <input id="cw_bot_stop_label" type="text" name="cw_bot_stop_label" value="<?php echo esc_attr($stop_command); ?>" autocomplete="off" />
                        <p>Альтернативная команда для отключения бота и подключения оператора.</p>
                    </div>
                </section>

                <section class="cw-settings-card cw-settings-card-full cw-bot-rules-card">
                    <div class="cw-settings-card-head cw-bot-rules-head">
                        <div class="cw-settings-card-icon">TXT</div>
                        <div>
                            <h2>Правила ответов</h2>
                            <p>Создавайте и редактируйте правила через форму. Ключевые слова добавляются отдельными полями.</p>
                        </div>
                        <button type="button" class="button cw-settings-secondary-link cw-bot-rule-add" id="cwBotRuleAdd">Добавить правило</button>
                    </div>

                    <input type="hidden" id="cw_bot_rules_text" name="cw_bot_rules_text" value="<?php echo esc_attr($rules_text); ?>">

                    <div class="cw-bot-rules-table-wrap">
                        <table class="cw-bot-rules-table" id="cwBotRulesTable">
                            <thead>
                                <tr>
                                    <th>Ключевые слова</th>
                                    <th>Ответ</th>
                                    <th class="cw-bot-rules-actions-col">Действия</th>
                                </tr>
                            </thead>
                            <tbody id="cwBotRulesBody">
                                <?php foreach ($rule_rows as $index => $rule): ?>
                                    <tr data-keywords="<?php echo esc_attr($rule['keywords']); ?>" data-reply="<?php echo esc_attr($rule['reply']); ?>">
                                        <td>
                                            <div class="cw-bot-rule-keywords"><?php echo esc_html($rule['keywords']); ?></div>
                                        </td>
                                        <td>
                                            <div class="cw-bot-rule-reply"><?php echo esc_html(wp_strip_all_tags($rule['reply'])); ?></div>
                                        </td>
                                        <td class="cw-bot-rules-actions">
                                            <button type="button" class="button cw-bot-rule-edit">Редактировать</button>
                                            <button type="button" class="button cw-bot-rule-delete">Удалить</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="cw-bot-rules-empty" id="cwBotRulesEmpty" <?php echo $rule_rows ? 'hidden' : ''; ?>>
                            Правил пока нет. Нажмите «Добавить правило», чтобы создать первый автоответ.
                        </div>
                    </div>

                    <div class="cw-settings-help-list">
                        <p><strong>Ключевые слова:</strong> каждый вариант добавляется отдельным полем. При сохранении варианты автоматически объединяются через <code>|</code>.</p>
                        <p><strong>Плейсхолдеры:</strong> <code>{bot_name}</code>, <code>{operator_command}</code>, <code>{bot_command}</code>, <code>{stop_command}</code>.</p>
                        <p><strong>HTML-ссылки разрешены:</strong> <code>&lt;a href="/contacts/" target="_blank" rel="noopener noreferrer"&gt;Контакты&lt;/a&gt;</code></p>
                    </div>
                </section>
            </div>

            <div class="cw-settings-actions">
                <button type="submit" name="cw_bot_save" class="button button-primary">Сохранить настройки</button>
            </div>
        </form>

        <div class="cw-bot-rule-modal" id="cwBotRuleModal" hidden>
            <div class="cw-bot-rule-modal-backdrop" data-close="1"></div>
            <div class="cw-bot-rule-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="cwBotRuleModalTitle">
                <div class="cw-bot-rule-modal-head">
                    <h2 id="cwBotRuleModalTitle">Новое правило</h2>
                    <button type="button" class="cw-bot-rule-modal-close" id="cwBotRuleClose" aria-label="Закрыть">×</button>
                </div>

                <div class="cw-settings-field">
                    <label>Ключевые слова</label>
                    <div class="cw-bot-keyword-list" id="cwBotKeywordList" aria-label="Ключевые слова правила"></div>
                    <button type="button" class="button cw-bot-keyword-add" id="cwBotKeywordAdd">Добавить ключ</button>
                    <p>Каждый вариант указывается в отдельном поле. При сохранении они объединятся через <code>|</code>.</p>
                </div>

                <div class="cw-settings-field">
                    <label for="cwBotRuleReply">Ответ бота</label>
                    <textarea id="cwBotRuleReply" class="cw-bot-rule-reply-input" rows="8" placeholder="Ответ, который увидит пользователь"></textarea>
                    <p>Можно использовать плейсхолдеры и разрешённые HTML-ссылки.</p>
                </div>

                <div class="cw-bot-rule-modal-error" id="cwBotRuleError" hidden></div>

                <div class="cw-bot-rule-modal-actions">
                    <button type="button" class="button button-primary" id="cwBotRuleSave">Сохранить правило</button>
                    <button type="button" class="button" id="cwBotRuleCancel">Отмена</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            var form = document.querySelector('.cw-bot-settings-page .cw-settings-form');
            var addBtn = document.getElementById('cwBotRuleAdd');
            var body = document.getElementById('cwBotRulesBody');
            var empty = document.getElementById('cwBotRulesEmpty');
            var hidden = document.getElementById('cw_bot_rules_text');
            var modal = document.getElementById('cwBotRuleModal');
            var modalTitle = document.getElementById('cwBotRuleModalTitle');
            var keywordList = document.getElementById('cwBotKeywordList');
            var keywordAddBtn = document.getElementById('cwBotKeywordAdd');
            var replyInput = document.getElementById('cwBotRuleReply');
            var errorBox = document.getElementById('cwBotRuleError');
            var saveBtn = document.getElementById('cwBotRuleSave');
            var closeBtn = document.getElementById('cwBotRuleClose');
            var cancelBtn = document.getElementById('cwBotRuleCancel');
            var editingRow = null;

            function text(value) {
                return String(value || '').trim();
            }

            function stripTags(value) {
                var tmp = document.createElement('div');
                tmp.innerHTML = String(value || '');
                return text(tmp.textContent || tmp.innerText || value);
            }

            function setText(node, value) {
                node.textContent = value;
            }

            function splitKeywords(value) {
                return String(value || '')
                    .split('|')
                    .map(function (item) { return text(item); })
                    .filter(function (item) { return item !== ''; });
            }

            function firstKeywordInput() {
                return keywordList ? keywordList.querySelector('.cw-bot-keyword-input') : null;
            }

            function updateKeywordRemoveState() {
                if (!keywordList) return;
                var rows = keywordList.querySelectorAll('.cw-bot-keyword-row');
                rows.forEach(function (row) {
                    var button = row.querySelector('.cw-bot-keyword-remove');
                    if (button) {
                        button.disabled = rows.length <= 1;
                    }
                });
            }

            function createKeywordRow(value) {
                var row = document.createElement('div');
                row.className = 'cw-bot-keyword-row';

                var input = document.createElement('input');
                input.type = 'text';
                input.className = 'cw-bot-keyword-input';
                input.autocomplete = 'off';
                input.placeholder = 'Например: визитки';
                input.value = value || '';
                input.setAttribute('aria-label', 'Ключевое слово');

                var removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'button cw-bot-keyword-remove';
                removeBtn.textContent = '×';
                removeBtn.setAttribute('aria-label', 'Удалить ключевое слово');
                removeBtn.title = 'Удалить ключевое слово';

                row.appendChild(input);
                row.appendChild(removeBtn);

                return row;
            }

            function addKeywordRow(value, shouldFocus) {
                if (!keywordList) return null;
                var row = createKeywordRow(value || '');
                keywordList.appendChild(row);
                updateKeywordRemoveState();

                if (shouldFocus) {
                    setTimeout(function () {
                        var input = row.querySelector('.cw-bot-keyword-input');
                        if (input) input.focus();
                    }, 20);
                }

                return row;
            }

            function renderKeywordRows(keywords) {
                if (!keywordList) return;
                keywordList.innerHTML = '';
                var values = splitKeywords(keywords);
                if (!values.length) {
                    values = [''];
                }

                values.forEach(function (value) {
                    addKeywordRow(value, false);
                });

                updateKeywordRemoveState();
            }

            function collectKeywords() {
                if (!keywordList) return '';
                var values = [];
                keywordList.querySelectorAll('.cw-bot-keyword-input').forEach(function (input) {
                    var value = text(input.value);
                    if (value) {
                        values.push(value);
                    }
                });
                return values.join('|');
            }

            function updateEmptyState() {
                if (!empty || !body) return;
                empty.hidden = body.querySelectorAll('tr').length > 0;
            }

            function formatKeywordsForTable(keywords) {
                return splitKeywords(keywords).join(', ');
            }

            function createRuleRow(keywords, reply) {
                var tr = document.createElement('tr');
                tr.dataset.keywords = keywords;
                tr.dataset.reply = reply;

                var keywordsTd = document.createElement('td');
                var keywordsDiv = document.createElement('div');
                keywordsDiv.className = 'cw-bot-rule-keywords';
                setText(keywordsDiv, formatKeywordsForTable(keywords));
                keywordsTd.appendChild(keywordsDiv);

                var replyTd = document.createElement('td');
                var replyDiv = document.createElement('div');
                replyDiv.className = 'cw-bot-rule-reply';
                setText(replyDiv, stripTags(reply));
                replyTd.appendChild(replyDiv);

                var actionsTd = document.createElement('td');
                actionsTd.className = 'cw-bot-rules-actions';

                var editBtn = document.createElement('button');
                editBtn.type = 'button';
                editBtn.className = 'button cw-bot-rule-edit';
                editBtn.textContent = 'Редактировать';

                var deleteBtn = document.createElement('button');
                deleteBtn.type = 'button';
                deleteBtn.className = 'button cw-bot-rule-delete';
                deleteBtn.textContent = 'Удалить';

                actionsTd.appendChild(editBtn);
                actionsTd.appendChild(deleteBtn);

                tr.appendChild(keywordsTd);
                tr.appendChild(replyTd);
                tr.appendChild(actionsTd);

                return tr;
            }

            function refreshRow(row, keywords, reply) {
                row.dataset.keywords = keywords;
                row.dataset.reply = reply;
                row.querySelector('.cw-bot-rule-keywords').textContent = formatKeywordsForTable(keywords);
                row.querySelector('.cw-bot-rule-reply').textContent = stripTags(reply);
            }

            function serializeRules() {
                if (!hidden || !body) return;
                var lines = [];
                body.querySelectorAll('tr').forEach(function (row) {
                    var keywords = text(row.dataset.keywords);
                    var reply = text(row.dataset.reply);
                    if (keywords && reply) {
                        lines.push(keywords + ' => ' + reply);
                    }
                });
                hidden.value = lines.join('\n');
            }

            function showError(message) {
                if (!errorBox) return;
                errorBox.textContent = message;
                errorBox.hidden = false;
            }

            function clearError() {
                if (!errorBox) return;
                errorBox.textContent = '';
                errorBox.hidden = true;
            }

            function openModal(row) {
                editingRow = row || null;
                clearError();
                modalTitle.textContent = editingRow ? 'Редактировать правило' : 'Новое правило';
                renderKeywordRows(editingRow ? text(editingRow.dataset.keywords) : '');
                replyInput.value = editingRow ? text(editingRow.dataset.reply).replace(/\\n/g, '\n') : '';
                modal.hidden = false;
                document.body.classList.add('cw-bot-rule-modal-open');
                setTimeout(function () {
                    var input = firstKeywordInput();
                    if (input) input.focus();
                }, 30);
            }

            function closeModal() {
                modal.hidden = true;
                document.body.classList.remove('cw-bot-rule-modal-open');
                editingRow = null;
                clearError();
            }

            function saveModalRule() {
                var keywords = collectKeywords();
                var reply = text(replyInput.value).replace(/\r\n/g, '\n').replace(/\r/g, '\n').replace(/\n/g, '\\n');

                if (!keywords) {
                    showError('Добавьте хотя бы одно ключевое слово для правила.');
                    var input = firstKeywordInput();
                    if (input) input.focus();
                    return;
                }

                if (!reply) {
                    showError('Укажите ответ бота.');
                    replyInput.focus();
                    return;
                }

                if (editingRow) {
                    refreshRow(editingRow, keywords, reply);
                } else {
                    body.appendChild(createRuleRow(keywords, reply));
                }

                updateEmptyState();
                serializeRules();
                closeModal();
            }

            if (addBtn) {
                addBtn.addEventListener('click', function () { openModal(null); });
            }

            if (keywordAddBtn) {
                keywordAddBtn.addEventListener('click', function () {
                    addKeywordRow('', true);
                });
            }

            if (keywordList) {
                keywordList.addEventListener('click', function (event) {
                    var removeButton = event.target.closest('.cw-bot-keyword-remove');
                    if (!removeButton) return;

                    var rows = keywordList.querySelectorAll('.cw-bot-keyword-row');
                    var row = removeButton.closest('.cw-bot-keyword-row');

                    if (rows.length <= 1) {
                        var input = row ? row.querySelector('.cw-bot-keyword-input') : null;
                        if (input) {
                            input.value = '';
                            input.focus();
                        }
                        return;
                    }

                    if (row) {
                        row.remove();
                        updateKeywordRemoveState();
                    }
                });

                keywordList.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter' && event.target.classList.contains('cw-bot-keyword-input')) {
                        event.preventDefault();
                        addKeywordRow('', true);
                    }
                });
            }

            if (body) {
                body.addEventListener('click', function (event) {
                    var editButton = event.target.closest('.cw-bot-rule-edit');
                    var deleteButton = event.target.closest('.cw-bot-rule-delete');
                    var row = event.target.closest('tr');

                    if (editButton && row) {
                        openModal(row);
                        return;
                    }

                    if (deleteButton && row) {
                        if (window.confirm('Удалить это правило?')) {
                            row.remove();
                            updateEmptyState();
                            serializeRules();
                        }
                    }
                });
            }

            [closeBtn, cancelBtn].forEach(function (button) {
                if (button) button.addEventListener('click', closeModal);
            });

            if (modal) {
                modal.addEventListener('click', function (event) {
                    if (event.target && event.target.dataset.close === '1') {
                        closeModal();
                    }
                });
            }

            if (saveBtn) {
                saveBtn.addEventListener('click', saveModalRule);
            }

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && modal && !modal.hidden) {
                    closeModal();
                }
            });

            if (form) {
                form.addEventListener('submit', serializeRules);
            }

            if (body) {
                body.querySelectorAll('tr').forEach(function (row) {
                    var keywordsCell = row.querySelector('.cw-bot-rule-keywords');
                    if (keywordsCell) {
                        keywordsCell.textContent = formatKeywordsForTable(row.dataset.keywords);
                    }
                });
            }

            updateEmptyState();
            serializeRules();
        })();
    </script>
    <?php
}
