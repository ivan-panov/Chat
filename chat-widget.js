(function ($) {

    const api = window.CW_API || {};

    let dialogId = localStorage.getItem("cw_dialog_id") || null;
    let lastMessageId = 0;
    let lastReadMsg = Number(localStorage.getItem("cw_last_read_message_id") || 0);

    const badge = $("#cw-badge");
    const chatBox = $("#cw-chat-box");
    const chatWindow = $("#cw-chat-window");
    const newDialogBtn = $("#cw-new-dialog-btn");

    // кнопка недоступна до закрытия оператором
    newDialogBtn.addClass("disabled");

    /* ----------------------------------------------------------
       Проверка — открыт ли чат
    ---------------------------------------------------------- */
    function isChatOpen() {
        return chatBox.is(":visible");
    }

    /* ----------------------------------------------------------
       Автоскролл вниз
    ---------------------------------------------------------- */
    function scrollToBottom() {
        const el = chatWindow[0];
        if (!el) return;
        el.scrollTop = el.scrollHeight;
    }

    /* ----------------------------------------------------------
       Проверка — находится ли пользователь внизу
    ---------------------------------------------------------- */
    function isScrolledToBottom() {
        const el = chatWindow[0];
        return (el.scrollHeight - el.scrollTop - el.clientHeight) < 50;
    }

    /* ----------------------------------------------------------
       Системное сообщение
    ---------------------------------------------------------- */
    function appendSystemMessage(text) {
        chatWindow.append(`
            <div class="cw-msg cw-system">
                <div class="cw-bubble">${text}</div>
            </div>
        `);
    }

    /* ----------------------------------------------------------
       Обычное сообщение
    ---------------------------------------------------------- */
    function appendMessage(m) {
        const isOp = Number(m.is_operator) === 1;
        const text = m.message || "";

        if (text.startsWith("[system]")) {
            appendSystemMessage(text.replace("[system]", "").trim());
            return;
        }

        if (isOp) {
            chatWindow.append(`
                <div class="cw-msg cw-op">
                    <div class="cw-bubble">${text}</div>
                </div>
            `);
        } else {
            chatWindow.append(`
                <div class="cw-msg cw-user">
                    <div class="cw-bubble">${text}</div>
                </div>
            `);
        }
    }

    /* ----------------------------------------------------------
       Создание нового диалога
    ---------------------------------------------------------- */
    function createDialog(callback) {
        $.ajax({
            url: api.root + "dialogs",
            method: "POST",
            success(res) {

                dialogId = res.id;
                localStorage.setItem("cw_dialog_id", dialogId);

                lastMessageId = 0;
                lastReadMsg = 0;

                chatWindow.empty();
                appendSystemMessage("Создан новый диалог.");

                badge.hide();
                newDialogBtn.addClass("disabled");

                if (callback) callback();
            }
        });
    }

    /* ----------------------------------------------------------
       ensureDialog
    ---------------------------------------------------------- */
    function ensureDialog(callback) {
        if (dialogId) callback();
        else createDialog(callback);
    }

    /* ----------------------------------------------------------
       ПОЛЛИНГ сообщений
    ---------------------------------------------------------- */
    function pollMessages() {
        if (!dialogId) return;

        $.ajax({
            url: api.root + "dialogs/" + dialogId + "/messages",
            method: "GET",

            success(msgs, status, xhr) {

                const dlgStatus = xhr.getResponseHeader("X-Dialog-Status");

                // ░░░ Диалог закрыт оператором ░░░
                if (dlgStatus === "closed") {
                    newDialogBtn.removeClass("disabled");
                }

                let maxId = lastMessageId;

                msgs.forEach(m => {

                    const mid = Number(m.id);

                    if (mid > lastMessageId) {

                        const wasAtBottom = isScrolledToBottom();

                        appendMessage(m);

                        // ░░░ бейдж обновления ░░░
                        if (
                            m.is_operator == 1 &&
                            !m.message.startsWith("[system]") &&
                            !isChatOpen() &&
                            mid > lastReadMsg
                        ) {
                            badge.show();
                        }

                        if (wasAtBottom) {
                            setTimeout(scrollToBottom, 30);
                        }

                        if (mid > maxId) maxId = mid;
                    }
                });

                if (maxId > lastMessageId) {
                    lastMessageId = maxId;
                }
            }
        });
    }

    /* ----------------------------------------------------------
       Отправка сообщения
    ---------------------------------------------------------- */
    function sendMessage() {
        const msg = $("#cw-input").val().trim();
        if (!msg) return;

        ensureDialog(function () {

            $.ajax({
                url: api.root + "dialogs/" + dialogId + "/messages",
                method: "POST",
                contentType: "application/json; charset=utf-8",
                data: JSON.stringify({
                    message: msg,
                    operator: 0
                }),

                success() {
                    $("#cw-input").val("");

                    // клиент всегда скроллит после отправки
                    setTimeout(scrollToBottom, 30);
                }
            });

        });
    }

    /* ----------------------------------------------------------
       Новый диалог вручную
    ---------------------------------------------------------- */
    newDialogBtn.on("click", function () {
        if (newDialogBtn.hasClass("disabled")) return;

        localStorage.removeItem("cw_dialog_id");
        localStorage.removeItem("cw_last_read_message_id");

        dialogId = null;
        lastMessageId = 0;
        lastReadMsg = 0;

        chatWindow.empty();
        badge.hide();

        createDialog();
    });

    /* ----------------------------------------------------------
       ИНИЦИАЛИЗАЦИЯ
    ---------------------------------------------------------- */
    $(function () {

        /* Открытие чата */
        $("#cw-open-btn").on("click", function () {
            chatBox.fadeIn(200);
            $("#cw-open-btn").fadeOut(150);

            badge.hide();

            localStorage.setItem("cw_last_read_message_id", lastMessageId);
            lastReadMsg = lastMessageId;

            setTimeout(scrollToBottom, 120);
        });

        /* Закрытие чата */
        $("#cw-close").on("click", function () {
            chatBox.fadeOut(200);
            $("#cw-open-btn").fadeIn(150);
        });

        /* Восстановление истории диалога */
        if (dialogId) {
            $.ajax({
                url: api.root + "dialogs/" + dialogId + "/messages",
                method: "GET",

                success(msgs, status, xhr) {

                    const dlgStatus = xhr.getResponseHeader("X-Dialog-Status");

                    if (dlgStatus === "closed") newDialogBtn.removeClass("disabled");

                    msgs.forEach(m => {
                        appendMessage(m);

                        const mid = Number(m.id);
                        if (mid > lastMessageId) lastMessageId = mid;
                    });

                    setTimeout(scrollToBottom, 40);
                }
            });
        }

        // запуск poll
        setInterval(pollMessages, 3000);

        $("#cw-send").on("click", sendMessage);
        $("#cw-input").on("keypress", function (e) {
            if (e.key === "Enter") {
                e.preventDefault();
                sendMessage();
            }
        });

    });

})(jQuery);
