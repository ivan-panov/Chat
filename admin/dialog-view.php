<?php

function cw_admin_dialog_view() {
    global $wpdb;

    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;

    if ($id <= 0) {
        echo "<h2>Ошибка: не указан ID диалога.</h2>";
        return;
    }

    $dialog = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}cw_dialogs WHERE id = $id");

    if (!$dialog) {
        echo "<h2>Диалог с ID $id не найден.</h2>";
        return;
    }
    ?>

    <div class="wrap">
        <h1>Диалог #<?= $id ?></h1>

        <div class="cw-admin-dialog-meta" style="margin-bottom: 15px;">
            <strong><?= esc_html($dialog->user_name) ?></strong>
            <span style="margin-left: 10px; color:#666;">Телефон: <?= esc_html($dialog->phone) ?></span>
            <span id="cw-admin-status" style="margin-left: 10px; color:#666;">Статус: <?= esc_html($dialog->status) ?></span>
            <span style="margin-left: 10px; color:#666;">Создан: <?= esc_html($dialog->created_at) ?></span>
        </div>

        <div style="margin-bottom: 12px; display:flex; gap:8px; align-items:center;">
            <button id="cw-close-dialog" class="button">Закрыть диалог</button>
            <button id="cw-delete-dialog" class="button button-danger" style="color:#b32d2e; border-color:#b32d2e;">Удалить</button>
            <span class="description">Ответить через Telegram: отправьте в бот <code>#<?= $id ?> Ваш ответ</code>.</span>
        </div>

        <div id="cw-admin-messages" style="
            background:#fff;
            border:1px solid #ddd;
            padding:15px;
            max-width:720px;
            border-radius:6px;
            max-height: 420px;
            overflow-y: auto;
            display:flex;
            flex-direction:column;
            gap:10px;
        "></div>

        <h2>Написать ответ</h2>

        <div style="max-width: 720px;">
            <textarea id="cw-admin-reply" rows="4" style="width:100%;"></textarea>
            <br><br>
            <button id="cw-admin-send" class="button button-primary">Отправить</button>
        </div>
    </div>

    <script>
        jQuery(function ($) {
            const dialogId = <?= intval($id) ?>;
            const apiBase  = '<?= esc_url_raw(rest_url('cw/v1/admin/dialog')) ?>';
            const nonce    = '<?= wp_create_nonce('wp_rest') ?>';

            const $list   = $('#cw-admin-messages');
            const $status = $('#cw-admin-status');

            function render(messages) {
                $list.empty();

                if (!messages || !messages.length) {
                    $list.append('<p style="color:#666;">Сообщений пока нет.</p>');
                    return;
                }

                messages.forEach((m) => {
                    const who = m.sender === 'user' ? 'Пользователь'
                        : m.sender === 'admin' ? 'Оператор'
                        : m.sender === 'telegram' ? 'Telegram'
                        : m.sender;

                    const item = $('<div style="border-bottom:1px solid #f1f1f1; padding-bottom:8px;"></div>');
                    item.append(`<div style=\"font-size:12px;color:#555;\">${who} · ${m.created_at}</div>`);
                    item.append(`<div style=\"margin-top:4px;\">${$('<div>').text(m.message).html()}</div>`);
                    $list.append(item);
                });

                $list.scrollTop($list[0].scrollHeight);
            }

            function fetchDialog() {
                $.get({
                    url: `${apiBase}/${dialogId}`,
                    headers: { 'X-WP-Nonce': nonce },
                    cache: false,
                }).done((res) => {
                    if (!res || !res.dialog) return;
                    $status.text(`Статус: ${res.dialog.status}`);
                    render(res.messages || []);
                });
            }

            function sendMessage() {
                const text = $('#cw-admin-reply').val().trim();
                if (!text) return;

                $('#cw-admin-send').prop('disabled', true);

                $.post({
                    url: `${apiBase}/${dialogId}/message`,
                    headers: { 'X-WP-Nonce': nonce },
                    data: { message: text },
                }).always(() => {
                    $('#cw-admin-reply').val('');
                    $('#cw-admin-send').prop('disabled', false);
                    fetchDialog();
                });
            }

            function closeDialog() {
                $.post({
                    url: `${apiBase}/${dialogId}/close`,
                    headers: { 'X-WP-Nonce': nonce },
                }).done(fetchDialog);
            }

            function deleteDialog() {
                if (!confirm('Удалить диалог и все сообщения?')) return;
                $.ajax({
                    url: `${apiBase}/${dialogId}`,
                    method: 'DELETE',
                    headers: { 'X-WP-Nonce': nonce },
                }).done(() => {
                    window.location.href = '<?= admin_url('admin.php?page=cw-dialogs') ?>';
                });
            }

            $('#cw-admin-send').on('click', sendMessage);
            $('#cw-close-dialog').on('click', closeDialog);
            $('#cw-delete-dialog').on('click', deleteDialog);

            fetchDialog();
            setInterval(fetchDialog, 5000);
        });
    </script>

    <?php
}
