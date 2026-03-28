(function ($) {
    'use strict';

    const api   = (window.CW_ADMIN_API && CW_ADMIN_API.root) || '';
    const nonce = (window.CW_ADMIN_API && CW_ADMIN_API.nonce) || '';

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

    const $sendInput = $('#cw-send-input');
    const $sendBtn   = $('#cw-send-btn');
    const $fileBtn   = $('#cw-file-btn');
    const $fileInput = $('#cw-admin-file');
    const $closeBtn  = $('#cw-close-btn');
    const $deleteBtn = $('#cw-delete-btn');

    function escapeHtml(text) {
        return $('<div/>').text(String(text ?? '')).html();
    }

    function decodeHtmlEntities(text) {
        return $('<textarea/>').html(String(text ?? '')).text();
    }

    function sanitizeLinkHref(href) {
        const decoded = decodeHtmlEntities(href).trim();
        if (!decoded) return '';

        try {
            const url = new URL(decoded, window.location.origin);
            const protocol = (url.protocol || '').toLowerCase();

            if (
                protocol === 'http:' ||
                protocol === 'https:' ||
                protocol === 'mailto:' ||
                protocol === 'tel:'
            ) {
                return url.href;
            }
        } catch (e) {}

        return '';
    }

    function getShortLinkLabel(href, fallbackText) {
        const fallback = String(fallbackText || '').trim();

        try {
            const url = new URL(String(href || ''), window.location.origin);
            const host = String(url.hostname || '').replace(/^www\./i, '');
            return host || fallback || String(href || '');
        } catch (e) {}

        return fallback || String(href || '');
    }

    function splitTrailingUrlPunctuation(urlText) {
        let clean = String(urlText || '');
        let trailing = '';

        while (clean && /[.,!?;:)\]"']$/.test(clean)) {
            const lastChar = clean.slice(-1);

            if (lastChar === ')') {
                const openCount = (clean.match(/\(/g) || []).length;
                const closeCount = (clean.match(/\)/g) || []).length;

                if (closeCount <= openCount) {
                    break;
                }
            }

            if (lastChar === ']') {
                const openCount = (clean.match(/\[/g) || []).length;
                const closeCount = (clean.match(/\]/g) || []).length;

                if (closeCount <= openCount) {
                    break;
                }
            }

            trailing = lastChar + trailing;
            clean = clean.slice(0, -1);
        }

        return {
            clean: clean,
            trailing: trailing
        };
    }

    function isQrPaymentLink(href, labelText) {
        const safeLabel = String(labelText || '').toLowerCase();

        if (safeLabel.includes('сбп qr') || safeLabel.includes('qr code')) {
            return true;
        }

        try {
            const url = new URL(String(href || ''), window.location.origin);
            return String(url.hostname || '').toLowerCase() === 'qr.nspk.ru';
        } catch (e) {}

        return false;
    }

    function getQrLinkIconHtml() {
        return '<span class="cw-link-qr-icon" aria-hidden="true" style="display:inline-flex;vertical-align:-2px;margin-right:6px;">' +
            '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">' +
            '<rect x="1" y="1" width="4" height="4" rx="0.5" stroke="currentColor" stroke-width="1.2"/>' +
            '<rect x="9" y="1" width="4" height="4" rx="0.5" stroke="currentColor" stroke-width="1.2"/>' +
            '<rect x="1" y="9" width="4" height="4" rx="0.5" stroke="currentColor" stroke-width="1.2"/>' +
            '<path d="M8 8H9.5V9.5H8V8ZM10.5 8H12V10H10.5V8ZM8 10.5H10V12H8V10.5ZM11 11H13V13H11V11Z" fill="currentColor"/>' +
            '</svg>' +
        '</span>';
    }

    function renderMessageText(text) {
        const source = String(text ?? '');
        const links = [];

        function pushLinkToken(hrefRaw, labelText) {
            const safeHref = sanitizeLinkHref(hrefRaw);

            if (!safeHref) {
                return '';
            }

            const normalizedLabel = String(labelText || '').trim();
            const finalLabel = (
                !normalizedLabel ||
                normalizedLabel === safeHref ||
                normalizedLabel === hrefRaw
            )
                ? getShortLinkLabel(safeHref, normalizedLabel)
                : normalizedLabel;

            const token = '__CW_LINK_' + links.length + '__';

            const iconHtml = isQrPaymentLink(safeHref, finalLabel)
                ? getQrLinkIconHtml()
                : '';

            links.push(
                '<a href="' + escapeHtml(safeHref) + '" target="_blank" rel="noopener noreferrer">' +
                    iconHtml +
                    '<span>' + escapeHtml(finalLabel) + '</span>' +
                '</a>'
            );

            return token;
        }

        const withAnchorTokens = source.replace(
            /<a\b[^>]*href\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))[^>]*>([\s\S]*?)<\/a>/gi,
            function (_match, href1, href2, href3, labelHtml) {
                const hrefRaw = href1 || href2 || href3 || '';
                const rawLabelText = decodeHtmlEntities(
                    String(labelHtml || '').replace(/<[^>]*>/g, '')
                ).trim();

                const token = pushLinkToken(hrefRaw, rawLabelText);

                if (!token) {
                    return decodeHtmlEntities(labelHtml || '');
                }

                return token;
            }
        );

        const withAllTokens = withAnchorTokens.replace(
            /(^|[\s(>])((?:https?:\/\/)[^\s<]+)/gi,
            function (match, prefix, urlText) {
                const parts = splitTrailingUrlPunctuation(urlText);
                const token = pushLinkToken(parts.clean, parts.clean);

                if (!token) {
                    return match;
                }

                return prefix + token + parts.trailing;
            }
        );

        let html = escapeHtml(withAllTokens).replace(/\r\n|\r|\n/g, '<br>');

        links.forEach(function (linkHtml, index) {
            const token = '__CW_LINK_' + index + '__';
            html = html.replace(token, linkHtml);
        });

        return html;
    }

    function parseDateSafe(input) {
        if (!input) return null;

        let s = String(input);
        if (s.includes(' ') && !s.includes('T')) {
            s = s.replace(' ', 'T');
        }

        const d = new Date(s);
        return isNaN(d.getTime()) ? null : d;
    }

    function formatTime(d) {
        return new Intl.DateTimeFormat('ru-RU', {
            hour: '2-digit',
            minute: '2-digit'
        }).format(d);
    }

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

    function isMessageRead(m) {
        return Number(m && m.unread) === 0;
    }

    function shouldRerenderMessage(prev, next) {
        if (!prev) return true;

        const prevText    = String(prev.message || '');
        const nextText    = String(next.message || '');
        const prevCreated = String(prev.created_at || '');
        const nextCreated = String(next.created_at || '');
        const prevOp      = Number(prev.is_operator) === 1;
        const nextOp      = Number(next.is_operator) === 1;
        const prevUnread  = Number(prev.unread || 0);
        const nextUnread  = Number(next.unread || 0);
        const prevRead    = isMessageRead(prev);
        const nextRead    = isMessageRead(next);

        if (prevRead && nextRead) {
            return false;
        }

        return (
            prevText !== nextText ||
            prevCreated !== nextCreated ||
            prevOp !== nextOp ||
            prevUnread !== nextUnread
        );
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
            const sep = payload.indexOf('|');

            let url = payload;
            let name = 'Файл';

            if (sep !== -1) {
                url = payload.slice(0, sep).trim();
                name = payload.slice(sep + 1).trim() || 'Файл';
            } else {
                try {
                    name = decodeURIComponent(url.split('/').pop() || 'Файл');
                } catch (e) {
                    name = url.split('/').pop() || 'Файл';
                }
            }

            const safeUrl = sanitizeLinkHref(url) || encodeURI(url);

            return $(`
                <div class="cw-msg ${cls} cw-msg-file" data-id="${id}" data-date-key="${escapeHtml(dateKey)}">
                    <div class="cw-msg-content">
                        <a href="${escapeHtml(safeUrl)}" target="_blank" rel="noopener noreferrer" class="cw-file-link">
                            <span class="cw-file-name">${escapeHtml(name)}</span>
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
            if (!confirm('Удалить диалог?')) return;

            $.post(api + `dialogs/${currentDialog}/delete`, function () {
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
            });
        });

        pollTimer = setInterval(function () {
            loadDialogs();

            if (currentDialog) {
                loadMessages();
            }
        }, 5000);
    });

})(jQuery);