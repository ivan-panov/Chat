jQuery(function ($) {

    const api = CW_ADMIN.rest;

    // Устанавливаем глобально X-WP-Nonce и отправку cookies для всех jQuery AJAX-запросов
    if (typeof CW_ADMIN !== 'undefined' && CW_ADMIN.nonce) {
        $.ajaxSetup({
            beforeSend(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', CW_ADMIN.nonce);
            },
            xhrFields: {
                withCredentials: true
            }
        });
    }

    let currentDialog = null;
    let lastMessageId = 0;
    let firstLoad = true;

    /* ----------------------------------------------------------
       ЗАГРУЗКА СПИСКА ДИАЛОГОВ
    ---------------------------------------------------------- */
    function loadDialogs() {
        $.get(api + "dialogs/", function (list) {

            let html = "";
            list.forEach(d => {

                let unread = Number(d.unread);
                let badge = unread > 0 ? `<span class="cw-unread-badge">${unread}</span>` : "";

                html += `
                    <div class="cw-dialog-item" data-id="${d.id}">
                        <b>Диалог #${d.id}</b> ${badge}
                    </div>
                `;
            });

            $("#cw-dialogs-list").html(html);
        });
    }

    loadDialogs();
    setInterval(loadDialogs, 4000);


    /* ----------------------------------------------------------
       ОТКРЫТИЕ ДИАЛОГА
    ---------------------------------------------------------- */
    $(document).on("click", ".cw-dialog-item", function () {

        $(".cw-dialog-item").removeClass("active");
        $(this).addClass("active");

        currentDialog = $(this).data("id");
        lastMessageId = 0;

        loadGeo();
        loadMessages(true);

        // Пометим прочитанным у сервера — отправляется с nonce+cookies
        $.post(api + `dialogs/${currentDialog}/read/`)
            .fail(function () {
                // не критично — просто логируем
                console.warn('cw: failed to mark dialog read');
            });
    });


    /* ----------------------------------------------------------
       ЗАГРУЗКА GEO
    ---------------------------------------------------------- */
    function loadGeo() {
        if (!currentDialog) return;

        $.get(api + `dialogs/${currentDialog}/geo/`, function (g) {

            $("#cw-geo-box").html(`
                <b>ГЕОДАННЫЕ:</b><br>
                Страна: ${g.geo_country}<br>
                Город: ${g.geo_city}<br>
                Регион: ${g.geo_region}<br>
                IP: ${g.geo_ip}<br>
                Браузер: ${g.geo_browser}<br>
            `);
        });
    }


    /* ----------------------------------------------------------
       ЗАГРУЗКА СООБЩЕНИЙ — ИСТОРИЯ ВСЕГДА ПОКАЗЫВАЕТСЯ
    ---------------------------------------------------------- */
    function loadMessages(scroll) {

        if (!currentDialog) return;

        $.ajax({
            url: api + `dialogs/${currentDialog}/messages/`,
            method: "GET",

            success(msgs, status, xhr) {

                let dlgStatus = xhr.getResponseHeader("X-Dialog-Status");

                let html = "";

                msgs.forEach(m => {
                    const isOp = Number(m.is_operator) === 1;
                    const cls = isOp ? "cw-msg-op" : "cw-msg-user";

                    // Экранируем пользовательские сообщения (чтобы избежать XSS),
                    // для operator сообщений допускаем HTML (сервер должен их очищать/фильтровать).
                    let content = '';
                    if (isOp) {
                        content = m.message;
                    } else {
                        content = $('<div/>').text(m.message).html();
                    }

                    html += `
                        <div class="cw-msg ${cls}">
                            ${content}
                            <div class="cw-msg-time">${m.created_at}</div>
                        </div>
                        <div style="clear:both"></div>
                    `;

                    if (!isOp && m.id > lastMessageId && !firstLoad) {
                        // play sound
                        const snd = document.getElementById("cw-sound");
                        if (snd && typeof snd.play === 'function') {
                            try { snd.play(); } catch(e) { /* ignore */ }
                        }
                    }

                    if (m.id > lastMessageId) {
                        lastMessageId = m.id;
                    }
                });

                $("#cw-messages-box").html(html);

                if (scroll)
                    $("#cw-messages-box").scrollTop(999999);

                firstLoad = false;
            },
            error() {
                console.warn('cw: failed to load messages for dialog', currentDialog);
            }
        });
    }


    /* ----------------------------------------------------------
       ПОЛЛИНГ — ДАЖЕ ДЛЯ ЗАКРЫТОГО ДИАЛОГА ИСТОРИЯ НЕ ПРОПАДАЕТ
    ---------------------------------------------------------- */
    setInterval(() => {
        if (currentDialog) loadMessages(false);
    }, 2000);


    /* ----------------------------------------------------------
       ОТПРАВКА СООБЩЕНИЯ
    ---------------------------------------------------------- */
    $("#cw-send-btn").on("click", function () {

        if (!currentDialog) return;

        let text = $("#cw-send-input").val().trim();
        if (!text) return;

        // POST отправит nonce и куки благодаря $.ajaxSetup
        $.post(api + `dialogs/${currentDialog}/messages/`, {
            message: text,
            operator: 1
        }, function () {
            $("#cw-send-input").val("");
            loadMessages(true);
        }).fail(function () {
            alert('Ошибка при отправке сообщения.');
        });
    });


    $("#cw-send-input").on("keydown", function (e) {
        if (e.key === "Enter") $("#cw-send-btn").click();
    });


    /* ----------------------------------------------------------
       КНОПКА: Запросить данные у клиента (Имя обяз., Телефон/Email опц.)
    ---------------------------------------------------------- */
    $("#cw-request-btn").on("click", function () {
        if (!currentDialog) {
            alert('Выберите диалог.');
            return;
        }

        if (!confirm('Отправить клиенту запрос: "Имя (обязательно) + Телефон/Email (опционально)"?')) return;

        const btn = $(this);
        btn.prop('disabled', true);
        // Отправляем единый тип запроса: name_optional_contact
        $.post(api + `dialogs/${currentDialog}/messages/`, {
            message: '[request]name_optional_contact',
            operator: 1
        }, function () {
            // Обновляем сообщения, чтобы увидеть запрос
            loadMessages(true);
            // маленькая задержка для UX
            setTimeout(function () {
                btn.prop('disabled', false);
            }, 800);
        }).fail(function () {
            btn.prop('disabled', false);
            alert('Ошибка при отправке запроса.');
        });
    });


    /* ----------------------------------------------------------
       ЗАКРЫТЬ ДИАЛОГ — ТЕПЕРЬ НЕ СБРАСЫВАЕТ ИСТОРИЮ!
    ---------------------------------------------------------- */
    $("#cw-close-btn").on("click", function () {

        if (!currentDialog) return;

        $.post(api + `dialogs/${currentDialog}/close/`, function () {

            // НИЧЕГО НЕ ОЧИЩАЕМ
            // ИСТОРИЯ ДОЛЖНА ОСТАТЬСЯ

            loadDialogs();
            loadMessages(true);
        }).fail(function () {
            alert('Ошибка при закрытии диалога.');
        });
    });


    /* ----------------------------------------------------------
       УДАЛИТЬ ДИАЛОГ
    ---------------------------------------------------------- */
    $("#cw-delete-btn").on("click", function () {

        if (!currentDialog) return;

        if (!confirm("Удалить диалог?")) return;

        $.post(api + `dialogs/${currentDialog}/delete/`, function () {

            currentDialog = null;
            lastMessageId = 0;

            $("#cw-messages-box").html("");
            $("#cw-geo-box").html("Выберите диалог");

            loadDialogs();
        }).fail(function () {
            alert('Ошибка при удалении диалога.');
        });
    });

});
