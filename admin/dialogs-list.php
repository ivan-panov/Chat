<?php

function cw_admin_dialogs_page() {
    global $wpdb;

    // Обработка действий (закрыть / удалить)
    if (!empty($_GET['cw_action']) && !empty($_GET['_wpnonce'])) {
        $action = sanitize_text_field($_GET['cw_action']);
        $id     = isset($_GET['id']) ? intval($_GET['id']) : 0;

        if ($id && wp_verify_nonce($_GET['_wpnonce'], 'cw_dialog_action_' . $id)) {
            if ($action === 'close') {
                $wpdb->update($wpdb->prefix . 'cw_dialogs', ['status' => 'closed'], ['id' => $id]);
                echo '<div class="updated"><p>Диалог #' . intval($id) . ' закрыт.</p></div>';
            } elseif ($action === 'delete') {
                $wpdb->delete($wpdb->prefix . 'cw_messages', ['dialog_id' => $id]);
                $wpdb->delete($wpdb->prefix . 'cw_dialogs', ['id' => $id]);
                echo '<div class="updated"><p>Диалог #' . intval($id) . ' удалён.</p></div>';
            }
        }
    }

    // Загружаем список диалогов
    $dialogs = $wpdb->get_results("
        SELECT *
        FROM {$wpdb->prefix}cw_dialogs
        ORDER BY id DESC
    ");
    ?>

    <div class="wrap">

        <h1 class="wp-heading-inline">Диалоги чата</h1>
        <hr class="wp-header-end">

        <?php if (empty($dialogs)): ?>

            <p>Пока нет диалогов.</p>

        <?php else: ?>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                <tr>
                    <th width="50">ID</th>
                    <th>Имя</th>
                    <th>Телефон</th>
                    <th width="100">Статус</th>
                    <th width="150">Создан</th>
                    <th width="220">Действия</th>
                </tr>
                </thead>

                <tbody>

                <?php foreach ($dialogs as $d): ?>

                    <tr>
                        <td><?= intval($d->id) ?></td>

                        <td><?= esc_html($d->user_name) ?></td>

                        <td><?= esc_html($d->phone) ?></td>

                        <td>
                            <?php if ($d->status === 'open'): ?>
                                <span style="color: green; font-weight: bold;">Открыт</span>
                            <?php else: ?>
                                <span style="color: #666;">Закрыт</span>
                            <?php endif; ?>
                        </td>

                        <!-- ВАЖНО: показываем время как есть -->
                        <td><?= esc_html($d->created_at) ?></td>

                        <td>
                            <a href="<?= admin_url('admin.php?page=cw-dialog-view&id=' . intval($d->id)) ?>" class="button button-small">Открыть</a>
                            <a href="<?= wp_nonce_url(admin_url('admin.php?page=cw-dialogs&cw_action=close&id=' . intval($d->id)), 'cw_dialog_action_' . intval($d->id)) ?>" class="button button-small">Закрыть</a>
                            <a href="<?= wp_nonce_url(admin_url('admin.php?page=cw-dialogs&cw_action=delete&id=' . intval($d->id)), 'cw_dialog_action_' . intval($d->id)) ?>" class="button button-small" onclick="return confirm('Удалить диалог?');">Удалить</a>
                        </td>
                    </tr>

                <?php endforeach; ?>

                </tbody>
            </table>

        <?php endif; ?>

    </div>

    <?php
}
