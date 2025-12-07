jQuery(function ($) {

    const modal       = $("#cw-modal");
    const openBtn     = $("#cw-open");
    const startBtn    = $("#cw-start");
    const sendBtn     = $("#cw-send");
    const messagesEl  = $("#cw-messages");
    const chatBox     = $("#cw-chat");
    const formBox     = $("#cw-start-form");
    const replyInput  = $("#cw-reply-text");
    const statusLine  = $(".cw-chat-status");

    const STORAGE_KEY = 'cw_dialog';

    // Установка текстов кнопок из локализации
    startBtn.text(CW_API.texts.startCta || 'Начать чат');
    sendBtn.text(CW_API.texts.sendCta || 'Отправить');

    /* -------------------------------
       Открытие / закрытие виджета
    --------------------------------*/
    openBtn.on("click", function () {
        modal.fadeToggle(200);
    });

    /* -------------------------------
       Вспомогательные функции
    --------------------------------*/
    function saveSession(dialog) {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(dialog));
    }

    function loadSession() {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return null;

        try {
            return JSON.parse(raw);
        } catch (e) {
            return null;
        }
    }

    function resetChat() {
        localStorage.removeItem(STORAGE_KEY);
        formBox.show();
        chatBox.hide();
        messagesEl.empty();
    }

    const INTRO_KEY = 'cw_intro_prompt_shown';

    function renderMessages(list) {
        messagesEl.empty();

        const session = loadSession();
        const shouldShowIntro = !session && !localStorage.getItem(INTRO_KEY);
        const messagesToRender = shouldShowIntro
            ? [{ sender: 'system', message: 'Сообщение: представьтесь', created_at: '' }, ...list]
            : list;

        if (shouldShowIntro) {
            localStorage.setItem(INTRO_KEY, '1');
        }

        messagesToRender.forEach((msg) => {
            const item = $('<div class="cw-message"></div>');
            item.addClass(`from-${msg.sender}`);

            const sender = msg.sender === 'admin' ? 'Оператор'
                : msg.sender === 'telegram' ? 'Telegram'
                : msg.sender === 'system' ? ''
                : 'Вы';

            const meta = msg.created_at ? `<small>${msg.created_at}</small>` : '';
            item.append(`<div class="cw-message-top"><span>${sender}</span>${meta}</div>`);
            item.append(`<div class="cw-message-body"></div>`);
            item.find('.cw-message-body').text(msg.message);

            messagesEl.append(item);
        });

        messagesEl.scrollTop(messagesEl[0].scrollHeight);
    }

    function setMeta(dialog) {
        $("#cw-chat-username").text(dialog.user_name);
        $("#cw-chat-phone").text(dialog.phone ? `(${dialog.phone})` : '');
        statusLine.text(dialog.status === 'open' ? 'Ожидание оператора' : '');
    }

    function showChat(dialog, messages) {
        setMeta(dialog);
        renderMessages(messages || []);
        formBox.hide();
        chatBox.show();
    }

    function fetchDialog(session) {
        return $.get({
            url: `${CW_API.rest}/${session.id}`,
            data: { token: session.token },
            cache: false,
        });
    }

    function sendMessage(session, text) {
        return $.post({
            url: `${CW_API.rest}/${session.id}/message`,
            data: { token: session.token, message: text },
        });
    }

    /* -------------------------------
       Запуск чата (создание диалога)
    --------------------------------*/
    startBtn.on('click', function () {
        const name    = $("#cw-name").val().trim();
        const phone   = $("#cw-phone").val().trim();
        const message = $("#cw-message").val().trim();

        if (!name || !phone || !message) {
            alert('Пожалуйста, заполните имя, телефон и первое сообщение.');
            return;
        }

        startBtn.prop('disabled', true).text('Отправка...');

        $.ajax({
            url: CW_API.rest,
            method: 'POST',
            headers: { 'X-WP-Nonce': CW_API.nonce },
            data: { name, phone, message },
            success: function (res) {
                if (!res || res.status !== 'ok') {
                    alert('Ошибка запуска чата.');
                    return;
                }

                const session = { id: res.dialog_id, token: res.token, user_name: name, phone: phone };
                saveSession(session);

                showChat(session, [{ sender: 'user', message: message, created_at: 'Только что' }]);
                replyInput.val('');
                startPolling(session);
            },
            error: function (xhr) {
                console.error(xhr.responseText);
                let msg = 'Не удалось отправить сообщение.';
                try { const json = JSON.parse(xhr.responseText); if (json.message) msg = json.message; } catch (e) {}
                alert(msg);
            },
            complete: function () {
                startBtn.prop('disabled', false).text(CW_API.texts.startCta || 'Начать чат');
            }
        });
    });

    /* -------------------------------
       Отправка нового сообщения
    --------------------------------*/
    sendBtn.on('click', function () {
        const session = loadSession();
        if (!session) {
            alert('Сначала заполните данные.');
            resetChat();
            return;
        }

        const text = replyInput.val().trim();
        if (!text) return;

        sendBtn.prop('disabled', true).text('...');

        sendMessage(session, text)
            .done(() => {
                messagesEl.append(
                    `<div class="cw-message from-user"><div class="cw-message-top"><span>Вы</span><small>Только что</small></div><div class="cw-message-body"></div></div>`
                );
                messagesEl.find('.cw-message-body').last().text(text);
                messagesEl.scrollTop(messagesEl[0].scrollHeight);
                replyInput.val('');
            })
            .fail((xhr) => {
                console.error(xhr.responseText);
                alert('Не удалось отправить сообщение.');
            })
            .always(() => {
                sendBtn.prop('disabled', false).text(CW_API.texts.sendCta || 'Отправить');
            });
    });

    /* -------------------------------
       Периодическая загрузка
    --------------------------------*/
    function startPolling(session) {
        const interval = Math.max(3, CW_API.pollEvery || 8) * 1000;

        setInterval(() => {
            const stored = loadSession();
            if (!stored) return;

            fetchDialog(stored).done((res) => {
                if (!res || !res.dialog) return;
                showChat(res.dialog, res.messages || []);
            });
        }, interval);
    }

    // Восстанавливаем сессию, если она была
    const existing = loadSession();
    if (existing) {
        fetchDialog(existing).done((res) => {
            if (res && res.dialog) {
                showChat(res.dialog, res.messages || []);
                startPolling(existing);
            } else {
                resetChat();
            }
        }).fail(resetChat);
    } else {
        // если нет сессии — настраиваем тексты
        renderMessages([]);
        $(".cw-operator-hint").text(CW_API.texts.operator || 'Оператор скоро подключится');
        startBtn.text(CW_API.texts.startCta || 'Начать чат');
        sendBtn.text(CW_API.texts.sendCta || 'Отправить');
    }

});
