jQuery(function ($) {

    const api = CW_ADMIN.rest;

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

        $.post(api + `dialogs/${currentDialog}/read/`);
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

                    html += `
                        <div class="cw-msg ${cls}">
                            ${m.message}
                            <div class="cw-msg-time">${m.created_at}</div>
                        </div>
                        <div style="clear:both"></div>
                    `;

                    if (!isOp && m.id > lastMessageId && !firstLoad) {
                        document.getElementById("cw-sound").play();
                    }

                    if (m.id > lastMessageId) {
                        lastMessageId = m.id;
                    }
                });

                $("#cw-messages-box").html(html);

                if (scroll)
                    $("#cw-messages-box").scrollTop(999999);

                firstLoad = false;
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

        $.post(api + `dialogs/${currentDialog}/messages/`, {
            message: text,
            operator: 1
        }, function () {
            $("#cw-send-input").val("");
            loadMessages(true);
        });
    });


    $("#cw-send-input").on("keydown", function (e) {
        if (e.key === "Enter") $("#cw-send-btn").click();
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
        });
    });

});
