<?php
if (!defined('ABSPATH')) exit;

function cw_operator_panel_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Access denied');
    }
    ?>

    <div class="wrap">
        <h1>Операторская панель</h1>

        <div id="cw-admin-wrapper">

            <div class="cw-column-left">
                <div class="cw-left-header">
                    <strong>Диалоги</strong>
                </div>

                <div id="cw-dialogs-list">
                    <div class="cw-empty">Загрузка диалогов...</div>
                </div>
            </div>

            <div class="cw-column-right">
                <div id="cw-geo-box">Выберите диалог</div>

                <div id="cw-messages-box">
                    <div class="cw-empty">Выберите диалог слева</div>
                </div>

                <div class="cw-input-panel">
                    <input
                        type="file"
                        id="cw-admin-file"
                        accept="image/jpeg,image/png,image/webp,application/pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar"
                        style="display:none;"
                    />

                    <input
                        type="text"
                        id="cw-send-input"
                        placeholder="Введите сообщение..."
                        autocomplete="off"
                    />

                    <button id="cw-send-btn" class="button button-primary">
                        Отправить
                    </button>

                    <button id="cw-file-btn" class="button" type="button" title="Прикрепить файл">
                        📎
                    </button>

                    <button id="cw-close-btn" class="button button-secondary" type="button">
                        Закрыть
                    </button>

                    <button id="cw-delete-btn" class="button button-secondary" type="button">
                        Удалить
                    </button>
                </div>

            </div>
        </div>

        <div id="cw-admin-lightbox" style="display:none;">
            <img src="" alt="">
        </div>

        <audio id="cw-sound" preload="auto">
            <source
                src="<?php echo esc_url(plugin_dir_url(__FILE__) . '../assets/notify.mp3'); ?>"
                type="audio/mpeg"
            >
        </audio>
    </div>

    <?php
}