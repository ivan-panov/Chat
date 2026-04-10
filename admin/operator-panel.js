(function ($) {
    'use strict';

    const api   = (window.CW_ADMIN_API && CW_ADMIN_API.root) || '';
    const nonce = (window.CW_ADMIN_API && CW_ADMIN_API.nonce) || '';
    const shared = window.CWShared || {};

    if (!shared.escapeHtml || !shared.renderMessageText) {
        console.error('CW: shared helpers not found');
        return;
    }

    const {
        escapeHtml,
        decodeHtmlEntities,
        sanitizeLinkHref,
        sanitizeFileHref,
        getShortLinkLabel,
        splitTrailingUrlPunctuation,
        isQrPaymentLink,
        getQrLinkIconHtml,
        renderMessageText,
        parseDateSafe,
        formatTime,
        isSystemMessage,
        isMessageRead,
        shouldRerenderMessage,
        parseFileMessagePayload
    } = shared;

    if (!api) {
        console.error('CW: REST root not found');
        return;
    }

    $.ajaxSetup({
        beforeSend(xhr) {
            if (nonce) xhr.setRequestHeader('X-WP-Nonce', nonce);
        },
        xhrFields: { withCredentials: true }
    });

    let currentDialog = null;
    let lastMessageId = 0;
    let firstLoad = true;
    let dialogStatus = 'open';
    let isLoadingMsgs = false;
    let pollTimer = null;
    let lastSoundMessageId = 0;
    let lastMarkedReadId = 0;
    let geoRetryTimer = null;
    let geoRetryCount = 0;

    const GEO_RETRY_LIMIT = 6;

    const dialogsBox  = $('#cw-dialogs-list');
    const messagesBox = $('#cw-messages-box');
    const geoBox      = $('#cw-geo-box');

    if (!dialogsBox.length || !messagesBox.length || !geoBox.length) {
        return;
    }

    const $sendInput = $('#cw-send-input');
    const $sendBtn   = $('#cw-send-btn');
    const $fileBtn   = $('#cw-file-btn');
    const $fileInput = $('#cw-admin-file');
    const $closeBtn  = $('#cw-close-btn');
    const $deleteBtn = $('#cw-delete-btn');

    const $deleteModal      = $('#cw-delete-modal');
    const $deleteCancelBtn  = $('#cw-delete-cancel-btn');
    const $deleteConfirmBtn = $('#cw-delete-confirm-btn');

    function getDateKey(d) {
        if (!d) return '';

        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');

        return `${y}-${m}-${day}`;
    }

    function formatDateDivider(d) {
        const now = new Date();

        const sameDay =
            d.getFullYear() === now.getFullYear() &&
            d.getMonth() === now.getMonth() &&
            d.getDate() === now.getDate();

        if (sameDay) return 'Сегодня';

        const yesterday = new Date();
        yesterday.setDate(now.getDate() - 1);

        const isYesterday =
            d.getFullYear() === yesterday.getFullYear() &&
            d.getMonth() === yesterday.getMonth() &&
            d.getDate() === yesterday.getDate();

        if (isYesterday) return 'Вчера';

        return new Intl.DateTimeFormat('ru-RU', {
            day: 'numeric',
            month: 'long'
        }).format(d);
    }

    function renderDateDivider(d) {
        return `
            <div class="cw-date-divider" data-date-key="${escapeHtml(getDateKey(d))}">
                <span>${formatDateDivider(d)}</span>
            </div>
        `;
    }

    function isNearBottom(el, threshold = 140) {
        if (!el) return true;
        return (el.scrollHeight - el.scrollTop - el.clientHeight) < threshold;
    }

    function scrollToBottom() {
        const el = messagesBox[0];
        if (el) {
            el.scrollTop = el.scrollHeight;
        }
    }

    function playSound() {
        const snd = document.getElementById('cw-sound');
        if (!snd) return;

        try {
            snd.pause();
            snd.currentTime = 0;

            const p = snd.play();
            if (p && typeof p.catch === 'function') {
                p.catch(function () {});
            }
        } catch (e) {}
    }

    function setInputEnabled(enabled) {
        $sendInput.prop('disabled', !enabled);
        $sendBtn.prop('disabled', !enabled);
        $fileBtn.prop('disabled', !enabled);

        $sendInput.attr(
            'placeholder',
            enabled ? 'Введите сообщение...' : 'Диалог закрыт. Отправка недоступна.'
        );
    }

    function setCloseButtonState() {
        if (!currentDialog) {
            $closeBtn.prop('disabled', true).text('Закрыть');
            $deleteBtn.prop('disabled', true);
            return;
        }

        $deleteBtn.prop('disabled', false);

        if (dialogStatus === 'closed') {
            $closeBtn.prop('disabled', true).text('Закрыт');
        } else {
            $closeBtn.prop('disabled', false).text('Закрыть');
        }
    }

    function getDialogStatusMeta(status) {
        const s = String(status || '').toLowerCase();

        switch (s) {
            case 'closed':
                return {
                    label: 'Закрыт',
                    style: 'display:inline-flex;align-items:center;padding:3px 10px;border-radius:999px;background:#fdecec;color:#c62828;font-size:12px;font-weight:700;line-height:1.2;'
                };

            case 'open':
                return {
                    label: 'Открыт',
                    style: 'display:inline-flex;align-items:center;padding:3px 10px;border-radius:999px;background:#e8f5e9;color:#2e7d32;font-size:12px;font-weight:700;line-height:1.2;'
                };

            default:
                return {
                    label: s ? s : '-',
                    style: 'display:inline-flex;align-items:center;padding:3px 10px;border-radius:999px;background:#eef2f7;color:#54606e;font-size:12px;font-weight:700;line-height:1.2;'
                };
        }
    }

    function hasGeoData(g) {
        if (!g || typeof g !== 'object') return false;

        return !!(
            String(g.geo_country || '').trim() ||
            String(g.geo_city || '').trim() ||
            String(g.geo_region || '').trim() ||
            String(g.geo_org || '').trim()
        );
    }

    function stopGeoRetry() {
        if (geoRetryTimer) {
            clearTimeout(geoRetryTimer);
            geoRetryTimer = null;
        }
    }

    function scheduleGeoRetry() {
        stopGeoRetry();

        if (!currentDialog) return;
        if (geoRetryCount >= GEO_RETRY_LIMIT) return;

        geoRetryTimer = setTimeout(function () {
            geoRetryTimer = null;

            if (currentDialog) {
                loadGeo(true);
            }
        }, 5000);
    }

    function renderGeo(g, isPending) {
        if (!g) return;

        const waitingHtml = isPending
            ? '<div style="margin-top:6px;color:#888;">Определяем геоданные...</div>'
            : '';

        geoBox.html(`
            <div class="cw-geo-main-line">
                <strong>ГЕОДАННЫЕ:</strong>
                <span>Страна: ${escapeHtml(g.geo_country || '-')}</span>
                <span>Город: ${escapeHtml(g.geo_city || '-')}</span>
                <span>Регион: ${escapeHtml(g.geo_region || '-')}</span>
            </div>
            <div class="cw-geo-provider-line">
                <span>Провайдер: ${escapeHtml(g.geo_org || '-')}</span>
                <span>IP: ${escapeHtml(g.geo_ip || '-')}</span>
            </div>
            <div>Браузер / ОС / устройство: ${escapeHtml(g.geo_browser || '-')}</div>
            ${waitingHtml}
        `);
    }

    function renderStatusTicks(m) {
        const text = String(m.message || '');

        if (text.startsWith('[system]')) {
            return '';
        }

        const isRead = Number(m.unread) === 0;
        const ticks = isRead ? '✓✓' : '✓';
        const isOperator = Number(m.is_operator) === 1;

        return `
            <span class="cw-msg-status ${isRead ? 'is-read' : 'is-sent'} ${isOperator ? 'cw-status-operator' : 'cw-status-user'}"
                  title="${isOperator
                        ? (isRead ? 'Прочитано клиентом' : 'Доставлено клиенту')
                        : (isRead ? 'Прочитано оператором' : 'Доставлено оператору')}"
                  aria-label="${isOperator
                        ? (isRead ? 'Прочитано клиентом' : 'Доставлено клиенту')
                        : (isRead ? 'Прочитано оператором' : 'Доставлено оператору')}">
                ${ticks}
            </span>
        `;
    }

    function renderMeta(m, timeHtml) {
        const text = String(m.message || '');

        if (text.startsWith('[system]')) {
            return '';
        }

        return `
            <div class="cw-msg-meta">
                <span class="cw-msg-time">${escapeHtml(timeHtml)}</span>
                ${renderStatusTicks(m)}
            </div>
        `;
    }

    function buildMessageNode(m) {
        const id         = Number(m.id || 0);
        const isOperator = Number(m.is_operator) === 1;
        const text       = String(m.message || '');
        const cls        = isOperator ? 'cw-msg-op' : 'cw-msg-user';

        const d = parseDateSafe(m.created_at);
        const dateKey = getDateKey(d);
        const timeHtml = d ? formatTime(d) : String(m.created_at || '');

        if (text.startsWith('[system]')) {
            return $(`
                <div class="cw-msg cw-msg-system" data-id="${id}" data-date-key="${escapeHtml(dateKey)}">
                    <div class="cw-msg-content">${renderMessageText(text.replace('[system]', ''))}</div>
                </div>
            `);
        }

        if (text.startsWith('[image]')) {
            const imgUrl = text.replace('[image]', '').trim();

            return $(`
                <div class="cw-msg ${cls}" data-id="${id}" data-date-key="${escapeHtml(dateKey)}">
                    <img src="${escapeHtml(imgUrl)}" class="cw-msg-image" alt="">
                    ${renderMeta(m, timeHtml)}
                </div>
            `);
        }

        if (text.startsWith('[file]')) {
            const payload = text.replace('[file]', '').trim();
            const parsedFile = parseFileMessagePayload(payload, 'Файл');
            const safeUrl = sanitizeFileHref(parsedFile.url);

            return $(`
                <div class="cw-msg ${cls} cw-msg-file" data-id="${id}" data-date-key="${escapeHtml(dateKey)}">
                    <div class="cw-msg-content">
                        <a href="${escapeHtml(safeUrl)}" target="_blank" rel="noopener noreferrer" class="cw-file-link">
                            <span class="cw-file-name">${escapeHtml(parsedFile.name)}</span>
                        </a>
                    </div>
                    ${renderMeta(m, timeHtml)}
                </div>
            `);
        }

        return $(`
            <div class="cw-msg ${cls}" data-id="${id}" data-date-key="${escapeHtml(dateKey)}">
                <div class="cw-msg-content">${renderMessageText(text)}</div>
                ${renderMeta(m, timeHtml)}
            </div>
        `);
    }

    function ensureDateDivider(dateKey, dateObj, $beforeNode) {
        if (!dateKey || !dateObj) return;

        if (messagesBox.children(`.cw-date-divider[data-date-key="${dateKey}"]`).length) {
            return;
        }

        const $divider = $(renderDateDivider(dateObj));

        if ($beforeNode && $beforeNode.length) {
            $beforeNode.before($divider);
        } else {
            messagesBox.append($divider);
        }
    }

    function rebuildDateDividers() {
        messagesBox.children('.cw-date-divider').remove();

        let prevDateKey = '';

        messagesBox.children('.cw-msg').each(function () {
            const $msg = $(this);
            const dateKey = String($msg.attr('data-date-key') || '');

            if (!dateKey || dateKey === prevDateKey) return;

            const msgId = Number($msg.attr('data-id') || 0);
            const msgData = messagesBox.data('cw-msg-map') || {};
            const raw = msgData[msgId];
            const d = raw ? parseDateSafe(raw.created_at) : null;

            if (d) {
                $msg.before($(renderDateDivider(d)));
                prevDateKey = dateKey;
            }
        });
    }

    function upsertMessage(m) {
        const id = Number(m.id || 0);
        if (!id) return false;

        const d = parseDateSafe(m.created_at);
        const dateKey = getDateKey(d);

        let msgMap = messagesBox.data('cw-msg-map');
        if (!msgMap) msgMap = {};

        const prev = msgMap[id] || null;
        const $existing = messagesBox.children(`.cw-msg[data-id="${id}"]`);

        if ($existing.length && !shouldRerenderMessage(prev, m)) {
            msgMap[id] = $.extend({}, m);
            messagesBox.data('cw-msg-map', msgMap);
            return false;
        }

        msgMap[id] = $.extend({}, m);
        messagesBox.data('cw-msg-map', msgMap);

        const $node = buildMessageNode(m);

        if ($existing.length) {
            $existing.replaceWith($node);
            return false;
        }

        let inserted = false;

        messagesBox.children('.cw-msg').each(function () {
            const existingId = Number($(this).attr('data-id') || 0);

            if (existingId > id) {
                ensureDateDivider(dateKey, d, $(this));
                $(this).before($node);
                inserted = true;
                return false;
            }
        });

        if (!inserted) {
            ensureDateDivider(dateKey, d, null);
            messagesBox.append($node);
        }

        return true;
    }

    function isCurrentDialogActuallyActive() {
        if (!currentDialog) return false;
        if (document.visibilityState !== 'visible') return false;

        return dialogsBox.find(`.cw-dialog[data-id="${currentDialog}"]`).hasClass('active');
    }

    function markRead(lastId) {
        if (!currentDialog || !lastId) return;
        if (!isCurrentDialogActuallyActive()) return;

        $.ajax({
            url: api + `dialogs/${currentDialog}/read`,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                last_read_message_id: lastId
            })
        });
    }

    function loadDialogs() {
        $.get(api + 'dialogs', function (dialogs) {
            if (!Array.isArray(dialogs)) return;

            let html = '';

            dialogs.forEach(function (d) {
                const active = (currentDialog == d.id) ? 'active' : '';
                const unread = Number(d.unread || 0);
                const statusMeta = getDialogStatusMeta(d.status);

                html += `
                    <div class="cw-dialog ${active}" data-id="${d.id}">
                        <strong>#${d.id}</strong>
                        ${unread > 0 ? `<span class="cw-unread">(${unread})</span>` : ''}
                        <div class="cw-status">
                            <span style="${statusMeta.style}">${escapeHtml(statusMeta.label)}</span>
                        </div>
                    </div>
                `;
            });

            dialogsBox.html(html);
        });
    }

    function loadGeo(forceRetry) {
        if (!currentDialog) return;

        const requestedDialog = Number(currentDialog);

        $.get(api + `dialogs/${requestedDialog}/geo`, function (geo) {
            if (requestedDialog !== Number(currentDialog)) return;

            const filled = hasGeoData(geo);

            if (filled) {
                geoRetryCount = 0;
                stopGeoRetry();
                renderGeo(geo, false);
                return;
            }

            if (forceRetry || geoRetryCount === 0) {
                geoRetryCount += 1;
            }

            renderGeo(geo, geoRetryCount < GEO_RETRY_LIMIT);

            if (geoRetryCount < GEO_RETRY_LIMIT) {
                scheduleGeoRetry();
            }
        }).fail(function () {
            if (requestedDialog !== Number(currentDialog)) return;

            renderGeo({
                geo_country: '',
                geo_city: '',
                geo_region: '',
                geo_org: '',
                geo_ip: '',
                geo_browser: ''
            }, false);
        });
    }

    function loadMessages() {
        if (!currentDialog || isLoadingMsgs) return;

        isLoadingMsgs = true;

        const requestedDialog = Number(currentDialog);
        const el = messagesBox[0];
        const nearBottomBefore = isNearBottom(el, 160);

        $.get(api + `dialogs/${requestedDialog}/messages`, function (msgs, _statusText, jqXHR) {
            if (requestedDialog !== Number(currentDialog)) return;

            const hdrStatus = jqXHR && jqXHR.getResponseHeader
                ? jqXHR.getResponseHeader('X-Dialog-Status')
                : '';

            if (hdrStatus) {
                dialogStatus = String(hdrStatus).toLowerCase();
            }

            setInputEnabled(dialogStatus !== 'closed');
            setCloseButtonState();

            if (!Array.isArray(msgs)) return;

            let hasNewMessages = false;
            let maxIdInBatch = lastMessageId;
            let shouldPlaySound = false;
            let maxSoundId = lastSoundMessageId;
            let maxUnreadUserId = 0;

            msgs.forEach(function (m) {
                const id = Number(m.id || 0);
                const isUserMessage = Number(m.is_operator) === 0;
                const text = String(m.message || '');
                const isSystem = text.startsWith('[system]');

                if (id > lastMessageId) {
                    hasNewMessages = true;
                    maxIdInBatch = Math.max(maxIdInBatch, id);

                    if (!firstLoad && isUserMessage && !isSystem && id > lastSoundMessageId) {
                        shouldPlaySound = true;
                        maxSoundId = Math.max(maxSoundId, id);
                    }
                }

                if (isUserMessage && !isSystem && Number(m.unread) === 1) {
                    maxUnreadUserId = Math.max(maxUnreadUserId, id);
                }

                upsertMessage(m);
            });

            rebuildDateDividers();

            if (msgs.length) {
                lastMessageId = Math.max(
                    maxIdInBatch,
                    ...msgs.map(function (m) { return Number(m.id || 0); })
                );
            }

            if (
                isCurrentDialogActuallyActive() &&
                maxUnreadUserId > 0 &&
                maxUnreadUserId > lastMarkedReadId
            ) {
                markRead(maxUnreadUserId);
                lastMarkedReadId = maxUnreadUserId;
            }

            if (firstLoad || hasNewMessages || nearBottomBefore) {
                scrollToBottom();
            }

            if (shouldPlaySound) {
                playSound();
                lastSoundMessageId = maxSoundId;
            }

            firstLoad = false;
        }).always(function () {
            isLoadingMsgs = false;
        });
    }

    function resetCurrentDialogView() {
        lastMessageId = 0;
        firstLoad = true;
        dialogStatus = 'open';
        lastSoundMessageId = 0;
        lastMarkedReadId = 0;
        geoRetryCount = 0;

        stopGeoRetry();

        messagesBox.html('');
        messagesBox.removeData('cw-msg-map');

        setInputEnabled(false);
        setCloseButtonState();
    }

    function resetAfterDelete() {
        currentDialog = null;
        lastMessageId = 0;
        firstLoad = true;
        dialogStatus = 'open';
        lastSoundMessageId = 0;
        lastMarkedReadId = 0;
        geoRetryCount = 0;

        stopGeoRetry();

        messagesBox.html('<div class="cw-empty">Выберите диалог слева</div>');
        messagesBox.removeData('cw-msg-map');
        geoBox.text('Выберите диалог');

        setInputEnabled(false);
        setCloseButtonState();
        loadDialogs();
    }

    function openDialog(id) {
        currentDialog = Number(id);
        resetCurrentDialogView();

        geoBox.text('Загрузка...');

        $('.cw-dialog').removeClass('active');
        dialogsBox.find(`.cw-dialog[data-id="${currentDialog}"]`).addClass('active');

        loadGeo(false);
        loadMessages();
        loadDialogs();
    }

    function openDeleteModal() {
        if (!currentDialog || !$deleteModal.length) return;

        $deleteModal
            .addClass('is-open')
            .attr('aria-hidden', 'false')
            .stop(true, true)
            .hide()
            .fadeIn(180);

        $('body').addClass('cw-modal-open');
    }

    function closeDeleteModal() {
        if (!$deleteModal.length) return;

        $deleteModal
            .removeClass('is-open')
            .attr('aria-hidden', 'true')
            .stop(true, true)
            .fadeOut(180);

        $('body').removeClass('cw-modal-open');
    }

    function sendMessage() {
        const text = ($sendInput.val() || '').trim();
        if (!text || !currentDialog || dialogStatus === 'closed') return;

        $.ajax({
            url: api + `dialogs/${currentDialog}/messages`,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                message: text,
                operator: 1
            }),
            success() {
                $sendInput.val('');
                loadMessages();
                loadDialogs();
            },
            error(xhr) {
                let msg = 'Не удалось отправить сообщение';

                if (xhr && xhr.responseJSON && (xhr.responseJSON.details || xhr.responseJSON.error)) {
                    msg += ': ' + (xhr.responseJSON.details || xhr.responseJSON.error);
                }

                alert(msg);
            }
        });
    }

    function uploadFile(file) {
        if (!currentDialog || dialogStatus === 'closed' || !file) return;

        const formData = new FormData();
        formData.append('file', file);
        formData.append('operator', 1);

        $.ajax({
            url: api + `dialogs/${currentDialog}/messages`,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success() {
                $fileInput.val('');
                loadMessages();
                loadDialogs();
            },
            error(xhr) {
                $fileInput.val('');

                let msg = 'Ошибка загрузки файла';

                if (xhr && xhr.responseJSON && (xhr.responseJSON.details || xhr.responseJSON.error)) {
                    msg += ': ' + (xhr.responseJSON.details || xhr.responseJSON.error);
                }

                alert(msg);
            }
        });
    }

    $(document).on('click', '.cw-msg-image', function (e) {
        e.preventDefault();
        e.stopPropagation();

        const src = $(this).attr('src');
        const $lightbox = $('#cw-admin-lightbox');
        const $img = $lightbox.find('img');

        if (!src || !$img.length) return;

        $img.attr('src', src);

        $lightbox
            .css({ display: 'flex', opacity: 0 })
            .animate({ opacity: 1 }, 200);
    });

    $(document).on('click', '#cw-admin-lightbox', function () {
        $(this).animate({ opacity: 0 }, 200, function () {
            $(this).css('display', 'none');
        });
    });

    $(window).on('focus', function () {
        if (currentDialog) {
            loadMessages();
            loadDialogs();
        }
    });

    $(document).on('visibilitychange', function () {
        if (document.visibilityState === 'visible' && currentDialog) {
            loadMessages();
            loadDialogs();
        }
    });

    $(function () {
        setInputEnabled(false);
        setCloseButtonState();
        loadDialogs();

        dialogsBox.on('click', '.cw-dialog', function () {
            const id = Number($(this).data('id') || 0);
            if (!id) return;
            openDialog(id);
        });

        $sendBtn.on('click', function () {
            sendMessage();
        });

        $sendInput.on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                sendMessage();
            }
        });

        $fileBtn.on('click', function () {
            if (!currentDialog || dialogStatus === 'closed') return;
            $fileInput.trigger('click');
        });

        $fileInput.on('change', function () {
            if (!currentDialog || dialogStatus === 'closed') return;

            const file = this.files && this.files[0];
            if (!file) return;

            const maxSize = 20 * 1024 * 1024;
            if (file.size > maxSize) {
                alert('Максимальный размер файла — 20 МБ');
                this.value = '';
                return;
            }

            uploadFile(file);
        });

        $closeBtn.on('click', function () {
            if (!currentDialog || dialogStatus === 'closed') return;

            $.post(api + `dialogs/${currentDialog}/close`, function () {
                dialogStatus = 'closed';
                setInputEnabled(false);
                setCloseButtonState();
                loadDialogs();
                loadMessages();
            });
        });

        $deleteBtn.on('click', function () {
            if (!currentDialog) return;
            openDeleteModal();
        });

        $deleteCancelBtn.on('click', function () {
            closeDeleteModal();
        });

        $deleteConfirmBtn.on('click', function () {
            if (!currentDialog) {
                closeDeleteModal();
                return;
            }

            $deleteConfirmBtn.prop('disabled', true);
            $deleteCancelBtn.prop('disabled', true);

            $.ajax({
                url: api + `dialogs/${currentDialog}/delete`,
                method: 'POST',
                success(res) {
                    closeDeleteModal();
                    resetAfterDelete();

                    if (res && typeof res.deleted_files !== 'undefined') {
                        console.log('CW: dialog deleted, files removed:', res.deleted_files);
                    }
                },
                error(xhr) {
                    let msg = 'Не удалось удалить диалог';

                    if (xhr && xhr.responseJSON && (xhr.responseJSON.details || xhr.responseJSON.error)) {
                        msg += ': ' + (xhr.responseJSON.details || xhr.responseJSON.error);
                    }

                    alert(msg);
                },
                complete() {
                    $deleteConfirmBtn.prop('disabled', false);
                    $deleteCancelBtn.prop('disabled', false);
                }
            });
        });

        $deleteModal.on('click', function (e) {
            if ($(e.target).closest('.cw-delete-modal-dialog').length) return;
            closeDeleteModal();
        });

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $deleteModal.hasClass('is-open')) {
                closeDeleteModal();
            }
        });

        pollTimer = setInterval(function () {
            loadDialogs();

            if (currentDialog) {
                loadMessages();
            }
        }, 5000);
    });

})(jQuery);