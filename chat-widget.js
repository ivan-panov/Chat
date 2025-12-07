jQuery(function ($) {

    const modal   = $("#cw-modal");
    const openBtn = $("#cw-open");
    const sendBtn = $("#cw-send");

    /* -------------------------------
       Открытие / закрытие виджета
    --------------------------------*/
    openBtn.on("click", function () {
        modal.fadeToggle(200);
    });


    /* -------------------------------
       Отправка сообщения
    --------------------------------*/
    sendBtn.on("click", function () {

        const name    = $("#cw-name").val().trim();
        const phone   = $("#cw-phone").val().trim();
        const message = $("#cw-message").val().trim();

        if (!name || !phone || !message) {
            alert("Пожалуйста, заполните все поля.");
            return;
        }

        // UI блокировка
        sendBtn.prop("disabled", true);
        sendBtn.text("Отправка...");

        $.ajax({
            url: CW_API.rest,
            method: "POST",
            headers: {
                "X-WP-Nonce": CW_API.nonce
            },
            data: {
                name: name,
                phone: phone,
                message: message
            },

            success: function (res) {

                // Проверка структуры ответа
                if (!res || res.status !== "ok") {
                    alert("Ошибка: сервер вернул неправильный ответ.");
                    console.error("Ответ сервера:", res);
                    return;
                }

                alert("Ваше сообщение успешно отправлено!");

                // Очистка полей
                $("#cw-name").val("");
                $("#cw-phone").val("");
                $("#cw-message").val("");

                modal.fadeOut(200);
            },

            error: function (xhr) {

                // Покажем максимально подробную ошибку
                console.error("Ошибка AJAX →", {
                    status: xhr.status,
                    response: xhr.responseText,
                    xhr: xhr
                });

                let msg = "Ошибка отправки. Попробуйте позже.";

                // Если сервер вернул читаемую ошибку — покажем её
                try {
                    const json = JSON.parse(xhr.responseText);
                    if (json && json.message) {
                        msg = "Ошибка: " + json.message;
                    }
                } catch (e) {}

                alert(msg);
            },

            complete: function () {
                sendBtn.prop("disabled", false);
                sendBtn.text("Отправить");
            }

        });

    });

});
