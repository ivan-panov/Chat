(function ($) {
    'use strict';

    const api = window.CW_API || {};
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

    let dialogId = localStorage.getItem('cw_dialog_id') || null;
    let lastMessageId = 0;
    let lastReadMsg = Number(localStorage.getItem('cw_last_read_message_id') || 0);
    let isCreatingDialog = false;
    let currentDialogStatus = null;
    let isPolling = false;
    let pendingCounter = 0;
    let pendingMessageMap = {};
    let messageCache = [];
    let renderedMessageMap = {};

    const badge = $('#cw-badge');
    const chatBox = $('#cw-chat-box');
    const chatWindow = $('#cw-chat-window');
    const newDialogBtn = $('#cw-new-dialog-btn');
    const openBtn = $('#cw-open-btn');

    const SOUND_UNLOCK_KEY = 'cw_sound_unlocked';
    const SOUND_LAST_OPERATOR_KEY = 'cw_last_notified_operator_message_id';
    const READ_SYNC_KEY = 'cw_last_synced_read_message_id';
    const CONSENT_PLACEHOLDER_ID = 'cw-consent-placeholder';

    let soundUnlocked = localStorage.getItem(SOUND_UNLOCK_KEY) === '1';
    let soundUnlocking = false;
    let lastNotifiedOperatorMessageId = Number(localStorage.getItem(SOUND_LAST_OPERATOR_KEY) || 0);
    let lastSyncedReadMsg = Number(localStorage.getItem(READ_SYNC_KEY) || 0);
    let shouldForceScrollOnNextRender = false;
    let autoScrollPinned = true;

    function updateNewDialogBtn() {
        if (isCreatingDialog) {
            newDialogBtn.addClass('disabled');
            return;
        }

        if (currentDialogStatus === 'closed') {
            newDialogBtn.removeClass('disabled');
        } else {
            newDialogBtn.addClass('disabled');
        }
    }

    function getClientKey() {
        let key = localStorage.getItem('cw_client_key');
        if (!key) {
            key = 'ck_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10);
            localStorage.setItem('cw_client_key', key);
        }
        return key;
    }

    function getClientInfo() {
        let timezone = '';

        try {
            timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
        } catch (e) {}

        return {
            ua: navigator.userAgent || '',
            platform: navigator.platform || '',
            language: navigator.language || '',
            languages: Array.isArray(navigator.languages) ? navigator.languages.join(', ') : '',
            screen: (window.screen ? `${screen.width}x${screen.height}` : ''),
            viewport: `${window.innerWidth || 0}x${window.innerHeight || 0}`,
            timezone: timezone,
            touch: ('ontouchstart' in window || navigator.maxTouchPoints > 0) ? 1 : 0
        };
    }

    function setJsCookie() {
        try {
            document.cookie = 'cw_js=1; path=/; max-age=' + (60 * 60 * 24 * 365);
        } catch (e) {}
    }

    $.ajaxSetup({
        beforeSend(xhr) {
            if (api.nonce) {
                xhr.setRequestHeader('X-WP-Nonce', api.nonce);
            }

            xhr.setRequestHeader('X-CW-Client-Key', getClientKey());
        },
        xhrFields: { withCredentials: true }
    });

    function nowMysqlString() {
        const d = new Date();
        const pad = function (n) {
            return String(n).padStart(2, '0');
        };

        return (
            d.getFullYear() + '-' +
            pad(d.getMonth() + 1) + '-' +
            pad(d.getDate()) + ' ' +
            pad(d.getHours()) + ':' +
            pad(d.getMinutes()) + ':' +
            pad(d.getSeconds())
        );
    }

    function scrollToBottom() {
        const el = chatWindow[0];
        if (!el) return;

        autoScrollPinned = true;
        el.scrollTop = el.scrollHeight;

        window.requestAnimationFrame(function () {
            el.scrollTop = el.scrollHeight;
            autoScrollPinned = true;
        });

        setTimeout(function () {
            el.scrollTop = el.scrollHeight;
            autoScrollPinned = true;
        }, 60);
    }

    function getConsentMessage() {
        return String(api.consent_message || '').trim();
    }

    function hasRealUserMessages(messages) {
        if (!Array.isArray(messages)) return false;

        return messages.some(function (m) {
            return Number(m && m.is_operator) !== 1;
        });
    }

    function hasPendingUserMessages() {
        return Object.keys(pendingMessageMap).length > 0;
    }

    function removeConsentPlaceholder() {
        chatWindow.find('.cw-msg[data-id="' + CONSENT_PLACEHOLDER_ID + '"]').remove();
    }

    function shouldShowConsentPlaceholder() {
        if (!getConsentMessage()) return false;
        if (currentDialogStatus === 'closed') return false;
        if (hasPendingUserMessages()) return false;

        return !hasRealUserMessages(messageCache);
    }

    function syncConsentPlaceholder() {
        if (!shouldShowConsentPlaceholder()) {
            removeConsentPlaceholder();
            return;
        }

        if (chatWindow.find('.cw-msg[data-id="' + CONSENT_PLACEHOLDER_ID + '"]').length) {
            return;
        }

        chatWindow.prepend(buildMessageHtml({
            id: CONSENT_PLACEHOLDER_ID,
            message: '[system]' + getConsentMessage(),
            is_operator: 1,
            unread: 0,
            created_at: ''
        }));
    }

    function getBottomGap() {
        const el = chatWindow[0];
        if (!el) return 0;

        return Math.max(0, el.scrollHeight - el.scrollTop - el.clientHeight);
    }

    function isNearBottom(threshold = 24) {
        return getBottomGap() <= threshold;
    }

    function updateAutoScrollPinned() {
        autoScrollPinned = isNearBottom(140);
    }

    function isChatOpen() {
        return chatBox.is(':visible');
    }

    function isUserInactiveForSound() {
        return !isChatOpen() || document.hidden;
    }

    function unlockSound() {
        if (soundUnlocked || soundUnlocking) return;

        const snd = document.getElementById('cw-sound');
        if (!snd) return;

        soundUnlocking = true;

        try {
            const prevMuted = snd.muted;
            const prevVolume = snd.volume;

            snd.muted = true;
            snd.volume = 0;
            snd.currentTime = 0;

            const finishUnlock = function () {
                try {
                    snd.pause();
                    snd.currentTime = 0;
                    snd.muted = prevMuted;
                    snd.volume = prevVolume;
                } catch (e) {}

                soundUnlocked = true;
                soundUnlocking = false;
                localStorage.setItem(SOUND_UNLOCK_KEY, '1');
            };

            const failUnlock = function () {
                try {
                    snd.pause();
                    snd.currentTime = 0;
                    snd.muted = prevMuted;
                    snd.volume = prevVolume;
                } catch (e) {}

                soundUnlocking = false;
            };

            const playPromise = snd.play();

            if (playPromise && typeof playPromise.then === 'function') {
                playPromise.then(finishUnlock).catch(failUnlock);
            } else {
                finishUnlock();
            }
        } catch (e) {
            soundUnlocking = false;
        }
    }

    function playNotificationSound() {
        const snd = document.getElementById('cw-sound');
        if (!snd || !soundUnlocked) return;

        try {
            snd.pause();
            snd.currentTime = 0;

            const playPromise = snd.play();
            if (playPromise && typeof playPromise.catch === 'function') {
                playPromise.catch(function () {});
            }
        } catch (e) {}
    }

    function saveLastNotifiedOperatorMessageId(id) {
        lastNotifiedOperatorMessageId = Number(id || 0);
        localStorage.setItem(SOUND_LAST_OPERATOR_KEY, String(lastNotifiedOperatorMessageId));
    }

    function saveLastSyncedReadMsg(id) {
        lastSyncedReadMsg = Number(id || 0);
        localStorage.setItem(READ_SYNC_KEY, String(lastSyncedReadMsg));
    }

    function resetDialogState(keepDialogId) {
        if (!keepDialogId) {
            dialogId = null;
            localStorage.removeItem('cw_dialog_id');
        }

        lastMessageId = 0;
        lastReadMsg = 0;
        pendingCounter = 0;
        pendingMessageMap = {};
        messageCache = [];
        renderedMessageMap = {};

        saveLastNotifiedOperatorMessageId(0);
        saveLastSyncedReadMsg(0);

        localStorage.setItem('cw_last_read_message_id', '0');

        currentDialogStatus = null;
        autoScrollPinned = true;
        updateNewDialogBtn();

        chatWindow.empty();
        badge.hide();
        syncConsentPlaceholder();
    }

    function switchToDialog(newDialogId) {
        const nextId = Number(newDialogId || 0);
        if (!nextId) return;

        dialogId = String(nextId);
        localStorage.setItem('cw_dialog_id', dialogId);

        resetDialogState(true);

        currentDialogStatus = 'open';
        updateNewDialogBtn();

        pollMessages(true);
    }

    function openChat() {
        shouldForceScrollOnNextRender = true;

        chatBox.stop(true, true).fadeIn(200, function () {
            scrollToBottom();
        });

        badge.hide();
        syncConsentPlaceholder();

        if (lastMessageId > lastReadMsg) {
            lastReadMsg = lastMessageId;
            localStorage.setItem('cw_last_read_message_id', String(lastReadMsg));
            syncReadStatus();
        }

        if (dialogId) {
            pollMessages(true);
        } else {
            scrollToBottom();
        }
    }

    function closeChat() {
        chatBox.fadeOut(200);
    }

    function toggleChat() {
        if (isChatOpen()) {
            closeChat();
        } else {
            openChat();
        }
    }

    function showAjaxError(prefix, xhr) {
        let msg = prefix || 'Ошибка';

        if (xhr && xhr.responseJSON && (xhr.responseJSON.details || xhr.responseJSON.error)) {
            msg += ': ' + (xhr.responseJSON.details || xhr.responseJSON.error);
        } else if (xhr && xhr.status) {
            msg += ' (HTTP ' + xhr.status + ')';
        }

        alert(msg);
    }

    function createDialog(callback) {
        if (isCreatingDialog) return;

        isCreatingDialog = true;
        updateNewDialogBtn();

        $.ajax({
            url: api.root + 'dialogs',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                client_key: getClientKey(),
                client_info: getClientInfo()
            }),
            success(res) {
                dialogId = String(res.id);
                localStorage.setItem('cw_dialog_id', dialogId);

                lastMessageId = 0;
                lastReadMsg = 0;
                pendingCounter = 0;
                pendingMessageMap = {};
                messageCache = [];
                renderedMessageMap = {};

                saveLastNotifiedOperatorMessageId(0);
                saveLastSyncedReadMsg(0);

                localStorage.setItem('cw_last_read_message_id', '0');

                currentDialogStatus = 'open';
                autoScrollPinned = true;
                updateNewDialogBtn();

                chatWindow.empty();
                badge.hide();

                isCreatingDialog = false;
                updateNewDialogBtn();

                if (callback) callback();
            },
            error(xhr) {
                isCreatingDialog = false;
                updateNewDialogBtn();
                showAjaxError('Ошибка создания диалога', xhr);
            }
        });
    }

    function ensureDialog(callback) {
        if (dialogId) {
            callback();
        } else {
            createDialog(callback);
        }
    }

    function nextPendingId() {
        pendingCounter += 1;
        return 'pending_' + Date.now() + '_' + pendingCounter;
    }

    function normalizePendingCompareText(text) {
        return String(text || '')
            .replace(/\s+/g, ' ')
            .trim()
            .toLowerCase();
    }

    function getPendingComparableDataFromServerMessage(message) {
        const text = String(message && message.message || '');

        if (text.startsWith('[file]')) {
            const payload = (text.replace('[file]', '') || '').trim();
            const parsed = parseFileMessagePayload(payload, 'Файл');

            return {
                kind: 'file',
                name: normalizePendingCompareText(parsed.name)
            };
        }

        if (text.startsWith('[image]')) {
            return {
                kind: 'image',
                url: normalizePendingCompareText((text.replace('[image]', '') || '').trim())
            };
        }

        if (text.startsWith('[system]')) {
            return {
                kind: 'system',
                text: normalizePendingCompareText(text.replace('[system]', ''))
            };
        }

        return {
            kind: 'text',
            text: normalizePendingCompareText(text)
        };
    }

    function findMatchingPendingId(message) {
        const comparable = getPendingComparableDataFromServerMessage(message);
        const pendingIds = Object.keys(pendingMessageMap);

        for (let i = 0; i < pendingIds.length; i += 1) {
            const pendingId = pendingIds[i];
            const pendingItem = pendingMessageMap[pendingId];

            if (!pendingItem) continue;
            if (Number(pendingItem.is_operator) !== Number(message.is_operator || 0)) continue;

            if (pendingItem.kind !== comparable.kind) continue;

            if (comparable.kind === 'text' && pendingItem.text === comparable.text) {
                return pendingId;
            }

            if (comparable.kind === 'file' && pendingItem.name === comparable.name) {
                return pendingId;
            }

            if (comparable.kind === 'image' && pendingItem.url && pendingItem.url === comparable.url) {
                return pendingId;
            }
        }

        return '';
    }

    function addPendingTextMessage(text) {
        removeConsentPlaceholder();

        const pendingId = nextPendingId();
        const pendingMessage = {
            id: pendingId,
            pending: true,
            message: text,
            is_operator: 0,
            unread: 1,
            created_at: nowMysqlString()
        };

        pendingMessageMap[pendingId] = {
            kind: 'text',
            text: normalizePendingCompareText(text),
            is_operator: 0
        };

        const wasNearBottom = isNearBottom(140);
        chatWindow.append(buildMessageHtml(pendingMessage));

        if (isChatOpen() && wasNearBottom) {
            scrollToBottom();
        }

        return pendingId;
    }

    function addPendingFileMessage(file) {
        removeConsentPlaceholder();

        const pendingId = nextPendingId();
        const fakeUrl = URL.createObjectURL(file);

        const pendingMessage = {
            id: pendingId,
            pending: true,
            message: '[file]' + fakeUrl + '|' + (file.name || 'Файл'),
            is_operator: 0,
            unread: 1,
            created_at: nowMysqlString(),
            _objectUrl: fakeUrl
        };

        pendingMessageMap[pendingId] = {
            kind: 'file',
            name: normalizePendingCompareText(file.name || 'Файл'),
            is_operator: 0
        };

        const wasNearBottom = isNearBottom(140);
        chatWindow.append(buildMessageHtml(pendingMessage));

        if (isChatOpen() && wasNearBottom) {
            scrollToBottom();
        }

        return pendingId;
    }

    function removePendingMessage(pendingId) {
        if (!pendingId) return;

        delete pendingMessageMap[pendingId];

        const $msg = chatWindow.find(`.cw-msg[data-id="${pendingId}"]`);
        if ($msg.length) {
            const objectUrl = $msg.attr('data-object-url');

            if (objectUrl) {
                try {
                    URL.revokeObjectURL(objectUrl);
                } catch (e) {}
            }

            $msg.remove();
        }
    }

    function markPendingFailed(pendingId) {
        if (!pendingId) return;

        delete pendingMessageMap[pendingId];

        const $msg = chatWindow.find(`.cw-msg[data-id="${pendingId}"]`);
        if (!$msg.length) return;

        $msg.removeClass('cw-pending').addClass('cw-failed');

        const $meta = $msg.find('.cw-msg-meta');
        if ($meta.length) {
            $meta.html('<span class="cw-msg-time">Не отправлено</span>');
        }
    }

    function sendMessage() {
        const $input = $('#cw-input');
        const msg = ($input.val() || '').trim();
        if (!msg) return;

        ensureDialog(function () {
            const pendingId = addPendingTextMessage(msg);

            $input.val('');
            scrollToBottom();

            $.ajax({
                url: api.root + 'dialogs/' + dialogId + '/messages',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    message: msg,
                    operator: 0
                }),
                success(res) {
                    removePendingMessage(pendingId);

                    if (res && res.switch_dialog) {
                        switchToDialog(res.switch_dialog);
                        return;
                    }

                    pollMessages(true);
                },
                error(xhr) {
                    if (xhr && xhr.status === 403 && xhr.responseJSON && xhr.responseJSON.error === 'dialog_closed') {
                        removePendingMessage(pendingId);
                        alert('Диалог закрыт оператором. Нажмите «Начать новый диалог».');
                        pollMessages(true);
                        return;
                    }

                    markPendingFailed(pendingId);
                    showAjaxError('Не удалось отправить сообщение', xhr);
                }
            });
        });
    }

    function uploadFile(file, inputEl) {
        ensureDialog(function () {
            const pendingId = addPendingFileMessage(file);
            const formData = new FormData();

            formData.append('file', file);
            formData.append('operator', 0);

            $.ajax({
                url: api.root + 'dialogs/' + dialogId + '/messages',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success(res) {
                    if (inputEl) inputEl.value = '';
                    removePendingMessage(pendingId);

                    if (res && res.switch_dialog) {
                        switchToDialog(res.switch_dialog);
                        return;
                    }

                    pollMessages(true);
                },
                error(xhr) {
                    if (inputEl) inputEl.value = '';

                    if (xhr && xhr.status === 403 && xhr.responseJSON && xhr.responseJSON.error === 'dialog_closed') {
                        removePendingMessage(pendingId);
                        alert('Диалог закрыт оператором. Нажмите «Начать новый диалог».');
                        pollMessages(true);
                        return;
                    }

                    markPendingFailed(pendingId);
                    showAjaxError('Ошибка загрузки файла', xhr);
                }
            });
        });
    }

    function getOperatorReplyAnchorMap(messages) {
        const sorted = (Array.isArray(messages) ? messages.slice() : []).sort(function (a, b) {
            return Number(a.id || 0) - Number(b.id || 0);
        });

        const replyAfterUser = {};
        let seenOperatorReply = false;

        for (let i = sorted.length - 1; i >= 0; i -= 1) {
            const m = sorted[i];
            const id = Number(m.id || 0);
            const isOperator = Number(m.is_operator) === 1;
            const isSystem = isSystemMessage(m.message);

            if (isOperator && !isSystem) {
                seenOperatorReply = true;
                continue;
            }

            if (!isOperator) {
                replyAfterUser[id] = seenOperatorReply;
            }
        }

        return replyAfterUser;
    }

    function renderStatus(m) {
        const text = String(m.message || '');

        if (text.startsWith('[system]')) {
            return '';
        }

        const isOperator = Number(m.is_operator) === 1;

        if (m.pending) {
            return `
                <span class="cw-msg-status is-sending" aria-label="Отправляется">
                    ⏳
                </span>
            `;
        }

        if (isOperator) {
            const isReadByClient = Number(m.unread) === 0;

            return `
                <span class="cw-msg-status ${isReadByClient ? 'is-read' : 'is-sent'}"
                      aria-label="${isReadByClient ? 'Прочитано вами' : 'Доставлено вам'}"
                      title="${isReadByClient ? 'Прочитано вами' : 'Доставлено вам'}">
                    ${isReadByClient ? '✓✓' : '✓'}
                </span>
            `;
        }

        const isReallyReadForUi = Number(m.unread) === 0;

        return `
            <span class="cw-msg-status ${isReallyReadForUi ? 'is-read' : 'is-sent'}"
                  aria-label="${isReallyReadForUi ? 'Прочитано' : 'Доставлено'}">
                ${isReallyReadForUi ? '✓✓' : '✓'}
            </span>
        `;
    }

    function renderMeta(m) {
        const time = formatTime(m.created_at);

        return `
            <div class="cw-msg-meta">
                ${time ? `<span class="cw-msg-time">${escapeHtml(time)}</span>` : ''}
                ${renderStatus(m)}
            </div>
        `;
    }

    function buildMessageHtml(m) {
        const id = String(m.id || '');
        const text = String(m.message || '');
        const isOperator = Number(m.is_operator) === 1;
        const cls = isOperator ? 'cw-op' : 'cw-user';
        const pendingClass = m.pending ? ' cw-pending' : '';
        const objectUrlAttr = m._objectUrl ? ` data-object-url="${escapeHtml(m._objectUrl)}"` : '';

        if (text.startsWith('[image]')) {
            const imgUrl = (text.replace('[image]', '') || '').trim();
            const safeUrl = sanitizeFileHref(imgUrl);

            return `
                <div class="cw-msg ${cls}${pendingClass}" data-id="${escapeHtml(id)}"${objectUrlAttr}>
                    <div class="cw-bubble">
                        <img src="${escapeHtml(safeUrl)}" style="max-width:60px;border-radius:8px;" alt="">
                        ${renderMeta(m)}
                    </div>
                </div>
            `;
        }

        if (text.startsWith('[file]')) {
            const payload = (text.replace('[file]', '') || '').trim();
            const parsedFile = parseFileMessagePayload(payload, 'Файл');
            const safeUrl = sanitizeFileHref(parsedFile.url);

            return `
                <div class="cw-msg ${cls}${pendingClass}" data-id="${escapeHtml(id)}"${objectUrlAttr}>
                    <div class="cw-bubble cw-file">
                        <a href="${escapeHtml(safeUrl)}" target="_blank" rel="noopener noreferrer" class="cw-file-link">
                            <span class="cw-file-name">${escapeHtml(parsedFile.name)}</span>
                        </a>
                        ${renderMeta(m)}
                    </div>
                </div>
            `;
        }

        if (text.startsWith('[system]')) {
            return `
                <div class="cw-msg cw-system" data-id="${escapeHtml(id)}">
                    <div class="cw-bubble">
                        <div class="cw-msg-text">${renderMessageText(text.replace('[system]', ''))}</div>
                    </div>
                </div>
            `;
        }

        return `
            <div class="cw-msg ${cls}${pendingClass}" data-id="${escapeHtml(id)}">
                <div class="cw-bubble">
                    <div class="cw-msg-text">${renderMessageText(text)}</div>
                    ${renderMeta(m)}
                </div>
            </div>
        `;
    }

    function upsertMessage(m) {
        const id = Number(m.id || 0);
        if (!id) return false;

        const prev = renderedMessageMap[id] || null;
        const $existing = chatWindow.find(`.cw-msg[data-id="${id}"]`);

        if ($existing.length && !shouldRerenderMessage(prev, m, {
            compareReplyAfter: true,
            comparePending: true
        })) {
            renderedMessageMap[id] = Object.assign({}, m);
            return false;
        }

        const html = buildMessageHtml(m);

        renderedMessageMap[id] = Object.assign({}, m);

        if ($existing.length) {
            const objectUrl = $existing.attr('data-object-url');
            if (objectUrl) {
                try {
                    URL.revokeObjectURL(objectUrl);
                } catch (e) {}
            }

            $existing.replaceWith(html);
            return false;
        }

        if (!m.pending) {
            const matchedPendingId = findMatchingPendingId(m);

            if (matchedPendingId) {
                const $pending = chatWindow.find(`.cw-msg[data-id="${matchedPendingId}"]`);

                if ($pending.length) {
                    const pendingObjectUrl = $pending.attr('data-object-url');
                    if (pendingObjectUrl) {
                        try {
                            URL.revokeObjectURL(pendingObjectUrl);
                        } catch (e) {}
                    }

                    delete pendingMessageMap[matchedPendingId];
                    $pending.replaceWith(html);
                    return false;
                }

                delete pendingMessageMap[matchedPendingId];
            }
        }

        chatWindow.append(html);
        return true;
    }

    function updateMessageStatusNode($msg, m) {
        const $status = $msg.find('.cw-msg-status').first();
        if (!$status.length) return;

        const isOperator = Number(m.is_operator) === 1;
        const isRead = Number(m.unread) === 0;
        const label = isOperator
            ? (isRead ? 'Прочитано вами' : 'Доставлено вам')
            : (isRead ? 'Прочитано' : 'Доставлено');

        $status
            .removeClass('is-sent is-read')
            .addClass(isRead ? 'is-read' : 'is-sent')
            .attr('title', label)
            .attr('aria-label', label)
            .text(isRead ? '✓✓' : '✓');
    }

    function markOperatorMessagesReadLocally(lastId) {
        const targetId = Number(lastId || 0);
        if (!targetId) return;

        let changed = false;

        Object.keys(renderedMessageMap).forEach(function (key) {
            const id = Number(key || 0);
            const m = renderedMessageMap[key];
            const text = String((m && m.message) || '');

            if (
                id > 0 &&
                id <= targetId &&
                m &&
                Number(m.is_operator) === 1 &&
                Number(m.unread) === 1 &&
                !text.startsWith('[system]')
            ) {
                m.unread = 0;
                changed = true;

                const $msg = chatWindow.find(`.cw-msg[data-id="${id}"]`);
                if ($msg.length) {
                    updateMessageStatusNode($msg, m);
                }
            }
        });

        if (changed && Array.isArray(messageCache)) {
            messageCache = messageCache.map(function (m) {
                const id = Number(m.id || 0);
                const text = String(m.message || '');

                if (
                    id > 0 &&
                    id <= targetId &&
                    Number(m.is_operator) === 1 &&
                    Number(m.unread) === 1 &&
                    !text.startsWith('[system]')
                ) {
                    const copy = Object.assign({}, m);
                    copy.unread = 0;
                    return copy;
                }

                return m;
            });
        }
    }

    function enrichMessagesForUi(messages) {
        const replyMap = getOperatorReplyAnchorMap(messages);

        return messages.map(function (m) {
            const clone = Object.assign({}, m);
            clone._hasOperatorReplyAfter = !!replyMap[Number(clone.id || 0)];
            return clone;
        });
    }

    function pollMessages(force) {
        if (!dialogId) {
            currentDialogStatus = null;
            updateNewDialogBtn();
            syncConsentPlaceholder();
            return;
        }

        if (isPolling && !force) return;
        isPolling = true;

        const requestedDialog = String(dialogId);

        $.ajax({
            url: api.root + 'dialogs/' + requestedDialog + '/messages',
            method: 'GET',
            success(msgs, status, xhr) {
                if (requestedDialog !== String(dialogId)) return;
                if (!Array.isArray(msgs)) return;

                const bottomGapBeforeRender = getBottomGap();
                const nearBottomBefore = bottomGapBeforeRender <= 140;
                const forceScrollAfterRender = shouldForceScrollOnNextRender;
                const stickToBottomBeforeRender = forceScrollAfterRender || autoScrollPinned || nearBottomBefore;
                let hasNewMessages = false;
                let hasNewOperatorMessages = false;
                let shouldPlaySound = false;
                let maxNewOperatorId = lastNotifiedOperatorMessageId;
                const prevLastMessageId = lastMessageId;

                const preparedMessages = enrichMessagesForUi(msgs);
                messageCache = preparedMessages;

                preparedMessages.forEach(function (m) {
                    const id = Number(m.id || 0);
                    const isOperator = Number(m.is_operator) === 1;
                    const text = String(m.message || '');
                    const isSystem = text.startsWith('[system]');

                    if (id > lastMessageId) {
                        lastMessageId = id;
                        hasNewMessages = true;
                    }

                    if (id > prevLastMessageId && isOperator && !isSystem) {
                        hasNewOperatorMessages = true;

                        if (isUserInactiveForSound()) {
                            maxNewOperatorId = Math.max(maxNewOperatorId, id);

                            if (id > lastNotifiedOperatorMessageId) {
                                shouldPlaySound = true;
                            }
                        }
                    }

                    upsertMessage(m);
                });

                const s = (xhr.getResponseHeader('X-Dialog-Status') || '').toLowerCase();
                currentDialogStatus = s || 'open';
                updateNewDialogBtn();
                syncConsentPlaceholder();

                if (isChatOpen() && !document.hidden) {
                    if (lastMessageId > lastReadMsg) {
                        lastReadMsg = lastMessageId;
                        localStorage.setItem('cw_last_read_message_id', String(lastReadMsg));
                        markOperatorMessagesReadLocally(lastReadMsg);
                        syncReadStatus();
                    }

                    const shouldScrollToLatest = hasNewMessages && (
                        stickToBottomBeforeRender ||
                        (hasNewOperatorMessages && bottomGapBeforeRender <= 220)
                    );

                    if (shouldScrollToLatest) {
                        scrollToBottom();
                    } else {
                        updateAutoScrollPinned();
                    }
                }

                shouldForceScrollOnNextRender = false;

                preparedMessages.forEach(function (m) {
                    if (
                        Number(m.id || 0) > lastReadMsg &&
                        Number(m.is_operator) === 1 &&
                        (!isChatOpen() || document.hidden)
                    ) {
                        badge.show();
                    }
                });

                if (shouldPlaySound) {
                    playNotificationSound();
                    saveLastNotifiedOperatorMessageId(maxNewOperatorId);
                }
            },
            complete() {
                isPolling = false;
            }
        });
    }

    function syncReadStatus() {
        if (!dialogId || !lastReadMsg) return;
        if (lastReadMsg <= lastSyncedReadMsg) return;

        const requestedDialog = String(dialogId);
        const readTarget = Number(lastReadMsg || 0);

        $.ajax({
            url: api.root + 'dialogs/' + requestedDialog + '/read',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                last_read_message_id: readTarget
            }),
            success() {
                if (requestedDialog !== String(dialogId)) return;
                saveLastSyncedReadMsg(Math.max(lastSyncedReadMsg, readTarget));
            }
        });
    }

    $(function () {
        setJsCookie();

        currentDialogStatus = null;
        updateNewDialogBtn();

        function closeImgModal() {
            $('#cw-img-modal').fadeOut(150, function () {
                $('#cw-img-modal .cw-img-modal-img').attr('src', '');
            });
        }

        if (!$('#cw-img-modal').length) {
            $('body').append(`
                <div id="cw-img-modal" style="display:none;">
                    <div class="cw-img-modal-inner">
                        <div class="cw-img-modal-wrap">
                            <button type="button" class="cw-img-modal-close" aria-label="Закрыть">×</button>
                            <img class="cw-img-modal-img" src="" alt="preview">
                        </div>
                    </div>
                </div>
            `);
        }

        $(document).on('click keydown touchstart', function () {
            unlockSound();
        });

        $(window).on('focus', function () {
            if (dialogId) {
                if (isChatOpen() && autoScrollPinned) {
                    shouldForceScrollOnNextRender = true;
                }

                pollMessages(true);
            }
        });

        $(document).on('visibilitychange', function () {
            if (document.visibilityState === 'visible' && dialogId) {
                badge.hide();

                if (isChatOpen() && autoScrollPinned) {
                    shouldForceScrollOnNextRender = true;
                }

                pollMessages(true);

                if (isChatOpen() && lastMessageId > lastReadMsg) {
                    lastReadMsg = lastMessageId;
                    localStorage.setItem('cw_last_read_message_id', String(lastReadMsg));
                    markOperatorMessagesReadLocally(lastReadMsg);
                    syncReadStatus();
                }
            }
        });

        $(document).on('click', '#cw-chat-window .cw-op img, #cw-chat-window .cw-user img', function () {
            const src = $(this).attr('src');
            if (!src) return;

            $('#cw-img-modal .cw-img-modal-img').attr('src', src);
            $('#cw-img-modal').fadeIn(150);
        });

        $(document).on('click', '#cw-img-modal .cw-img-modal-img', function () {
            closeImgModal();
        });

        $(document).on('click', '#cw-img-modal', function (e) {
            if ($(e.target).closest('.cw-img-modal-inner').length) return;
            closeImgModal();
        });

        $(document).on('click', '#cw-img-modal .cw-img-modal-close', function () {
            closeImgModal();
        });

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $('#cw-img-modal').is(':visible')) {
                closeImgModal();
            }
        });

        openBtn.on('click', function () {
            unlockSound();
            toggleChat();
        });

        chatWindow.on('scroll', function () {
            updateAutoScrollPinned();
        });

        $('#cw-close').on('click', closeChat);

        $('#cw-send').on('click', function () {
            unlockSound();
            sendMessage();
        });

        $('#cw-input').on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                unlockSound();
                sendMessage();
            }
        });

        $(document).on('click', '#cw-file-btn', function () {
            unlockSound();

            const $file = $('#cw-file');
            if ($file.length) {
                $file.trigger('click');
            }
        });

        $(document).on('change', '#cw-file', function () {
            const file = this.files && this.files[0];
            if (!file) return;

            const maxSize = 20 * 1024 * 1024;
            if (file.size > maxSize) {
                alert('Максимальный размер файла — 20 МБ');
                this.value = '';
                return;
            }

            unlockSound();
            uploadFile(file, this);
        });

        newDialogBtn.on('click', function () {
            if (newDialogBtn.hasClass('disabled')) return;

            resetDialogState(false);

            createDialog(function () {
                pollMessages(true);
            });
        });

        if (dialogId) {
            pollMessages(true);
        }

        setInterval(function () {
            pollMessages(false);
        }, 2000);
    });

})(jQuery);