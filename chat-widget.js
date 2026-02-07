(function ($) {

    const api = window.CW_API || {};

    /* ==========================================================
       JS cookie
    ========================================================== */
    (function setCWJsCookie() {
        try {
            function getCookie(name) {
                const v = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)');
                return v ? v.pop() : '';
            }
            if (getCookie('cw_js') === '1') return;

            const expires = new Date(Date.now() + 24 * 60 * 60 * 1000).toUTCString();
            let cookie = "cw_js=1; path=/; expires=" + expires + "; SameSite=Lax";
            if (location.protocol === 'https:') cookie += "; Secure";
            document.cookie = cookie;
        } catch (e) {
            console.warn('cw: setCWJsCookie failed', e);
        }
    })();

    /* ==========================================================
       REST nonce
    ========================================================== */
    if (api.nonce) {
        $.ajaxSetup({
            beforeSend(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', api.nonce);
            },
            xhrFields: { withCredentials: true }
        });
    }

    /* ==========================================================
       State
    ========================================================== */
    let dialogId = localStorage.getItem("cw_dialog_id") || null;
    let lastMessageId = 0;
    let lastReadMsg = Number(localStorage.getItem("cw_last_read_message_id") || 0);
    let isCreatingDialog = false;
    let isRequestFormActive = false;

    const badge = $("#cw-badge");
    const chatBox = $("#cw-chat-box");
    const chatWindow = $("#cw-chat-window");
    const newDialogBtn = $("#cw-new-dialog-btn");
    const openBtn = $("#cw-open-btn");

    newDialogBtn.addClass("disabled");

    /* ==========================================================
       Helpers
    ========================================================== */
    function isChatOpen() {
        return chatBox.is(":visible");
    }

    function scrollToBottom() {
        const el = chatWindow[0];
        if (!el) return;
        el.scrollTop = el.scrollHeight;
    }

    function isScrolledToBottom() {
        const el = chatWindow[0];
        if (!el) return true;
        return (el.scrollHeight - el.scrollTop - el.clientHeight) < 50;
    }

    /* ==========================================================
       Toggle chat
    ========================================================== */
    function openChat() {
        chatBox.fadeIn(200);

        // считаем всё прочитанным
        lastReadMsg = lastMessageId;
        try {
            localStorage.setItem("cw_last_read_message_id", lastReadMsg);
        } catch (e) {}

        syncReadStatus();
        badge.hide();
        requestAnimationFrame(scrollToBottom);
    }

    function closeChat() {
        chatBox.fadeOut(200);
    }

    function toggleChat() {
        isChatOpen() ? closeChat() : openChat();
    }

    /* ==========================================================
       Dialog helpers
    ========================================================== */
    function getClientKey() {
        let key = localStorage.getItem('cw_client_key');
        if (!key) {
            key = 'ck_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 8);
            try { localStorage.setItem('cw_client_key', key); } catch(e){}
        }
        return key;
    }

    function createDialog(callback) {
        if (isCreatingDialog) return;

        isCreatingDialog = true;
        newDialogBtn.addClass("disabled");

        $.ajax({
            url: api.root + "dialogs",
            method: "POST",
            contentType: "application/json; charset=utf-8",
            data: JSON.stringify({ client_key: getClientKey() }),

            success(res) {
                dialogId = res.id;
                isCreatingDialog = false;

                try {
                    localStorage.setItem("cw_dialog_id", dialogId);
                } catch(e){}

                chatWindow.empty();
                lastMessageId = 0;
                lastReadMsg = 0;
                try {
                    localStorage.setItem("cw_last_read_message_id", 0);
                } catch(e){}

                if (callback) callback();
            },

            error() {
                isCreatingDialog = false;
                newDialogBtn.removeClass("disabled");
                alert('Не удалось создать диалог');
            }
        });
    }

    function ensureDialog(callback) {
        if (dialogId) callback();
        else createDialog(callback);
    }

    /* ==========================================================
       Read sync with server 🔥
    ========================================================== */
    function syncReadStatus() {
        if (!dialogId || !lastReadMsg) return;

        $.ajax({
            url: api.root + "dialogs/" + dialogId + "/read",
            method: "POST",
            contentType: "application/json; charset=utf-8",
            data: JSON.stringify({
                last_read_message_id: lastReadMsg
            })
        });
    }

    /* ==========================================================
       Messages
    ========================================================== */
    function escapeHtml(text) {
        return $('<div/>').text(text).html();
    }

    function appendSystemMessage(text) {
        chatWindow.append(`
            <div class="cw-msg cw-system">
                <div class="cw-bubble">${escapeHtml(text)}</div>
            </div>
        `);
    }

    function appendMessage(m) {
        const isOp = Number(m.is_operator) === 1;
        const text = m.message || "";

        if (text.startsWith("[request]")) {
            const payload = text.replace("[request]", "").trim();

            if (payload === "name_optional_contact") {
                chatWindow.append(`
                    <div class="cw-msg cw-system cw-request">
                        <div class="cw-bubble">
                            <form class="cw-request-form">
                                <strong>Оператор запрашивает ваши данные:</strong>
                                <input name="name" placeholder="Имя (обязательно)" required />
                                <input name="phone" placeholder="Телефон" />
                                <input name="email" placeholder="Email" />
                                <button type="submit" class="cw-request-submit">Отправить</button>
                                <div class="cw-request-status"></div>
                            </form>
                        </div>
                    </div>
                `);
                return;
            }

            appendSystemMessage("Оператор запросил: " + payload);
            return;
        }

        if (text.startsWith("[system]")) {
            appendSystemMessage(text.replace("[system]", "").trim());
            return;
        }

        if (isOp) {
            chatWindow.append(`
                <div class="cw-msg cw-op">
                    <div class="cw-bubble">${m.message}</div>
                </div>
            `);
            return;
        }

        chatWindow.append(`
            <div class="cw-msg cw-user">
                <div class="cw-bubble">${escapeHtml(text)}</div>
            </div>
        `);
    }

    /* ==========================================================
       Render
    ========================================================== */
    function renderMessages(msgs, forceScroll) {
        if (!Array.isArray(msgs)) msgs = [];

        const wasAtBottom = isScrolledToBottom();
        chatWindow.empty();

        let maxId = lastMessageId;

        msgs.forEach(m => {
            const mid = Number(m.id);
            if (mid > maxId) maxId = mid;
            appendMessage(m);
        });

        lastMessageId = maxId;

        if (forceScroll || wasAtBottom) {
            requestAnimationFrame(scrollToBottom);
        }
    }

    /* ==========================================================
       Polling
    ========================================================== */
    function pollMessages() {
        if (!dialogId || isCreatingDialog || isRequestFormActive) return;

        $.ajax({
            url: api.root + "dialogs/" + dialogId + "/messages",
            method: "GET",

            success(msgs, status, xhr) {

                const dlgStatus = xhr.getResponseHeader("X-Dialog-Status");

                if (dlgStatus === "closed") {
                    newDialogBtn.removeClass("disabled");
                    dialogId = null;
                    try { localStorage.removeItem("cw_dialog_id"); } catch(e){}
                }

                renderMessages(msgs, false);

                // если чат открыт — сразу считаем прочитанным и синкаем
                if (isChatOpen()) {
                    lastReadMsg = lastMessageId;
                    try {
                        localStorage.setItem("cw_last_read_message_id", lastReadMsg);
                    } catch(e){}
                    syncReadStatus();
                }

                // бейдж — только если есть непрочитанные от оператора
                msgs.forEach(m => {
                    if (
                        Number(m.id) > lastReadMsg &&
                        Number(m.is_operator) === 1 &&
                        !isChatOpen()
                    ) {
                        badge.show();
                    }
                });
            }
        });
    }

    /* ==========================================================
       Send message
    ========================================================== */
    function sendMessage() {
        const msg = $("#cw-input").val().trim();
        if (!msg || isCreatingDialog) return;

        ensureDialog(function () {
            if (!dialogId) return;

            $.ajax({
                url: api.root + "dialogs/" + dialogId + "/messages",
                method: "POST",
                contentType: "application/json; charset=utf-8",
                data: JSON.stringify({ message: msg, operator: 0 }),

                success() {
                    $("#cw-input").val("");
                    requestAnimationFrame(scrollToBottom);
                }
            });
        });
    }

    /* ==========================================================
       Init
    ========================================================== */
    $(function () {

        // toggle чат
        openBtn.on("click", toggleChat);
        $("#cw-close").on("click", closeChat);

        // загрузка истории
        if (dialogId) {
            $.ajax({
                url: api.root + "dialogs/" + dialogId + "/messages",
                method: "GET",

                success(msgs, status, xhr) {

                    const dlgStatus = xhr.getResponseHeader("X-Dialog-Status");

                    if (dlgStatus === "closed") {
                        newDialogBtn.removeClass("disabled");
                        dialogId = null;
                        try { localStorage.removeItem("cw_dialog_id"); } catch(e){}
                    }

                    renderMessages(msgs, true);
                }
            });
        }

        chatWindow.on('focusin', '.cw-request-form input', () => {
            isRequestFormActive = true;
        });

        chatWindow.on('focusout', '.cw-request-form input', () => {
            setTimeout(() => {
                if (!chatWindow.find('.cw-request-form input:focus').length) {
                    isRequestFormActive = false;
                }
            }, 50);
        });

        chatWindow.on('submit', '.cw-request-form', function (e) {
            e.preventDefault();

            const form = $(this);
            const name = form.find('[name="name"]').val().trim();
            if (!name) return;

            const parts = [`Имя: ${name}`];
            const phone = form.find('[name="phone"]').val().trim();
            const email = form.find('[name="email"]').val().trim();
            if (phone) parts.push(`Телефон: ${phone}`);
            if (email) parts.push(`Email: ${email}`);

            ensureDialog(function () {
                if (!dialogId) return;

                $.ajax({
                    url: api.root + "dialogs/" + dialogId + "/messages",
                    method: "POST",
                    contentType: "application/json; charset=utf-8",
                    data: JSON.stringify({ message: parts.join('; '), operator: 0 }),

                    success() {
                        isRequestFormActive = false;
                        form.closest('.cw-request')
                            .find('.cw-bubble')
                            .html('<div class="cw-system">Спасибо — данные отправлены.</div>');
                        requestAnimationFrame(scrollToBottom);
                    }
                });
            });
        });

        newDialogBtn.on("click", function () {
            if (newDialogBtn.hasClass("disabled")) return;
            createDialog();
        });

        setInterval(pollMessages, 2000);

        $("#cw-send").on("click", sendMessage);
        $("#cw-input").on("keypress", e => {
            if (e.key === "Enter") {
                e.preventDefault();
                sendMessage();
            }
        });
    });

})(jQuery);
