<?php

function cw_admin_dialog_view() {
    global $wpdb;

    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;

    // Проверяем ID
    if ($id <= 0) {
        echo "<h2>Ошибка: не указан ID диалога.</h2>";
        return;
    }

    // Загружаем диалог
    $dialog = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}cw_dialogs WHERE id = $id");

    if (!$dialog) {
        echo "<h2>Диалог с ID $id не найден.</h2>";
        return;
    }

    /* -----------------------------------
       Обработка ответа администратора
    ------------------------------------ */
    if (!empty($_POST['answer'])) {

        $msg = sanitize_textarea_field($_POST['answer']);

        // Сохраняем сообщение по времени WordPress
        $wpdb->insert($wpdb->prefix . 'cw_messages', [
            'dialog_id'  => $id,
            'sender'     => 'admin',
            'message'    => $msg,
            'created_at' => current_time('mysql') // ← локальное WP время
        ]);

        // Отправка в Telegram
        if (function_exists('cw_send_to_telegram')) {
            cw_send_to_telegram("📩 Ответ администратора\nДиалог #$id\n\n$msg");
        }

        echo '<div class="updated"><p>Ответ отправлен.</p></div>';
    }

    /* -----------------------------------
       Загружаем сообщения
    ------------------------------------ */

    $messages = $wpdb->get_results("
        SELECT *
        FROM {$wpdb->prefix}cw_messages
        WHERE dialog_id = $id
        ORDER BY id ASC
    ");
    ?>

    <div class="wrap">
        <h1>Диалог #<?= $id ?></h1>

        <p>
            <strong><?= esc_html($dialog->user_name) ?></strong><br>
            Телефон: <?= esc_html($dialog->phone) ?><br>
            Статус: <?= esc_html($dialog->status) ?><br>
            Создан: <?= esc_html($dialog->created_at) ?>   <!-- ← ВРЕМЯ WP -->
        </p>

        <h2>Сообщения</h2>

        <p class="description">Ответить через Telegram: отправьте в бот строку вида <code>#<?= $id ?> Ваш ответ</code>.</p>

        <div style="
            background:#fff;
            border:1px solid #ddd;
            padding:15px;
            max-width:600px;
            border-radius:6px;
        ">

            <?php if (!empty($messages)): ?>

                <?php foreach ($messages as $m): ?>

                    <div style="margin-bottom: 15px;">

                        <div>
                            <strong>
                                <?php
                                if ($m->sender === 'user')       echo 'Пользователь';
                                elseif ($m->sender === 'admin')  echo 'Администратор';
                                elseif ($m->sender === 'telegram') echo 'Telegram';
                                else echo ucfirst($m->sender);
                                ?>
                            </strong>

                            <span style="color:#666;font-size:12px;">
                                (<?= esc_html($m->created_at) ?>) <!-- ← ВРЕМЯ WP -->
                            </span>
                        </div>

                        <div style="
                            background:#f6f7f7;
                            border-radius:6px;
                            padding:8px 10px;
                            margin-top:3px;
                        ">
                            <?= nl2br(esc_html($m->message)) ?>
                        </div>

                    </div>

                <?php endforeach; ?>

            <?php else: ?>

                <p>Сообщений пока нет.</p>

            <?php endif; ?>

        </div>

        <h2>Написать ответ</h2>

        <form method="post" style="max-width: 600px;">
            <textarea name="answer" rows="4" style="width:100%;"></textarea>
            <br><br>
            <button type="submit" class="button button-primary">Отправить</button>
        </form>

    </div>

    <?php
}
