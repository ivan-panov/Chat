<?php
if (!defined('ABSPATH')) exit;

/*
|--------------------------------------------------------------------------
| Операторская панель – главный callback страницы
|--------------------------------------------------------------------------
*/
function cw_operator_panel_page() {
    ?>
    <div class="wrap">
        <h1>Операторский чат</h1>

        <div id="cw-admin-wrapper">

            <!-- LEFT COLUMN -->
            <div class="cw-column-left">
                <div id="cw-dialogs-list">Загрузка...</div>
            </div>

            <!-- RIGHT COLUMN -->
            <div class="cw-column-right">

                <div id="cw-geo-box">Выберите диалог</div>

                <div id="cw-messages-box">
                    <div class="cw-empty">Сообщения будут здесь</div>
                </div>

                <div class="cw-input-panel">
                    <input id="cw-send-input" type="text" placeholder="Введите сообщение...">
                    <button id="cw-send-btn">Отправить</button>
                    <button id="cw-close-btn">Закрыть</button>
                    <button id="cw-delete-btn">Удалить</button>
                </div>

            </div>
        </div>

        <audio id="cw-sound" src="<?php echo plugin_dir_url(__FILE__) ?>alert.mp3"></audio>
    </div>
    <?php
}
