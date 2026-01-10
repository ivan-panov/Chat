(function ($) {

    const api = window.CW_API || {};

    /* ----------------------------------------------------------
       JS cookie
    ---------------------------------------------------------- */
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

    /* ----------------------------------------------------------
       REST nonce
    ---------------------------------------------------------- */
    if (api.nonce) {
        $.ajaxSetup({
            beforeSend(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', api.nonce);
            },
            xhrFields: { withCredentials: true }
        });
    }

    /* ----------------------------------------------------------
       State
    ---------------------------------------------------------- */
    let dialogId = localStorage.getItem("cw_dialog_id") || null;
    let lastMessageId = 0;
    let lastReadMsg = Number(localStorage.getItem("cw_last_read_message_id") || 0);
    let isRequestFormActive = false; // ★ FIX

    const badge = $("#cw-badge");
    const chatBox = $("#cw-chat-box");
    const chatWindow = $("#cw-chat-window");
    const newDialogBtn = $("#cw-new-dialog-btn");

    newDialogBtn.addClass("disabled");

    function isChatOpen() {
        return chatBox.is(":visible");
    }

    /* ----------------------------------------------------------
       Scroll helpers
    ---------------------------------------------------------- */
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

    /* ----------------------------------------------------------
       Messages
    ---------------------------------------------------------- */
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

        // --- request ---
        if (text.startsWith("[request]")) {
            const payload = text.replace("[request]", "").trim();

            if (payload === "name_optional_contact") {
                chatWindow.append(`
                    <div class="cw-msg cw-system cw-request">
                        <div class="cw-bubble">
                            <form class="cw-request-form" data-payload="name_optional_contact">
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

        // --- system ---
        if (text.startsWith("[system]")) {
            appendSystemMessage(text.replace("[system]", "").trim());
            return;
        }

        // --- operator ---
        if (isOp) {
            chatWindow.append(`
                <div class="cw-msg cw-op">
                    <div class="cw-bubble">${m.message}</div>
                </div>
            `);
            return;
        }

        // --- user ---
        chatWindow.append(`
            <div class="cw-msg cw-user">
                <div class="cw-bubble">${escapeHtml(text)}</div>
            </div>
        `);
    }

    /* ----------------------------------------------------------
       Date helper
    ---------------------------------------------------------- */
    function createdAtToTs(createdAt) {
        if (!createdAt) return 0;
        return Date.parse(createdAt.replace(' ', 'T')) || 0;
    }

    /* ----------------------------------------------------------
       Render messages (★ FIXED)
    ---------------------------------------------------------- */
    function renderMessages(msgs, forceScroll) {
        if (!Array.isArray(msgs)) msgs = [];

        const wasAtBottom = isScrolledToBottom();

        const requests = [];
        const clientMsgs = [];

        msgs.forEach(m => {
            const ts = createdAtToTs(m.created_at);
            if (Number(m.is_operator) === 1 && (m.message || '').startsWith('[request]')) {
                requests.push({ id: m.id, ts });
            } else if (!Number(m.is_operator)) {
                clientMsgs.push({ message: m.message, ts });
            }
        });

        const answered = new Set();
        requests.forEach(r => {
            clientMsgs.forEach(cm => {
                if (cm.ts > r.ts && cm.message && cm.message.includes('Имя:')) {
                    answered.add(Number(r.id));
                }
            });
        });

        chatWindow.empty();

        let maxId = lastMessageId;

        msgs.forEach(m => {
            const mid = Number(m.id);
            if (mid > maxId) maxId = mid;

            if (
                Number(m.is_operator) === 1 &&
                (m.message || '').startsWith('[request]') &&
                answered.has(mid)
            ) return;

            appendMessage(m);
        });

        lastMessageId = maxId;

        if (forceScroll || wasAtBottom) {
            requestAnimationFrame(scrollToBottom);
        }
    }

    /* ----------------------------------------------------------
       Polling (★ FIXED)
    ---------------------------------------------------------- */
    function pollMessages() {
        if (!dialogId || isRequestFormActive) return;

        $.get(api.root + "dialogs/" + dialogId + "/messages", msgs => {
            renderMessages(msgs, false);

            msgs.forEach(m => {
                if (
                    Number(m.id) > lastReadMsg &&
                    Number(m.is_operator) === 1 &&
                    !isChatOpen()
                ) badge.show();
            });
        });
    }

    /* ----------------------------------------------------------
       Send
    ---------------------------------------------------------- */
    function sendMessage() {
        const msg = $("#cw-input").val().trim();
        if (!msg) return;

        $.post({
            url: api.root + "dialogs/" + dialogId + "/messages",
            contentType: "application/json",
            data: JSON.stringify({ message: msg, operator: 0 }),
            success() {
                $("#cw-input").val("");
                requestAnimationFrame(scrollToBottom);
            }
        });
    }

    /* ----------------------------------------------------------
       Init
    ---------------------------------------------------------- */
    $(function () {

        $("#cw-open-btn").on("click", () => {
            chatBox.fadeIn(200);
            badge.hide();
            requestAnimationFrame(scrollToBottom);
        });

        $("#cw-close").on("click", () => {
            chatBox.fadeOut(200);
        });

        if (dialogId) {
            $.get(api.root + "dialogs/" + dialogId + "/messages", msgs => {
                renderMessages(msgs, true);
            });
        }

        // ★ фикс: отслеживаем активность формы
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

            $.post({
                url: api.root + "dialogs/" + dialogId + "/messages",
                contentType: "application/json",
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
