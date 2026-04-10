(function (window) {
    'use strict';

    if (!window || window.CWShared) {
        return;
    }

    const doc = window.document;

    function toStringSafe(value) {
        return String(value ?? '');
    }

    function escapeHtml(text) {
        const div = doc.createElement('div');
        div.textContent = toStringSafe(text);
        return div.innerHTML;
    }

    function decodeHtmlEntities(text) {
        const textarea = doc.createElement('textarea');
        textarea.innerHTML = toStringSafe(text);
        return textarea.value;
    }

    function sanitizeLinkHref(href) {
        const decoded = decodeHtmlEntities(href).trim();
        if (!decoded) return '';

        try {
            const url = new URL(decoded, window.location.origin);
            const protocol = toStringSafe(url.protocol).toLowerCase();

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

    function sanitizeFileHref(href) {
        const decoded = decodeHtmlEntities(href).trim();
        if (!decoded) return '';

        if (/^blob:/i.test(decoded)) {
            return decoded;
        }

        return sanitizeLinkHref(decoded) || encodeURI(decoded);
    }

    function getShortLinkLabel(href, fallbackText) {
        const fallback = toStringSafe(fallbackText).trim();

        try {
            const url = new URL(toStringSafe(href), window.location.origin);
            const host = toStringSafe(url.hostname).replace(/^www\./i, '');
            return host || fallback || toStringSafe(href);
        } catch (e) {}

        return fallback || toStringSafe(href);
    }

    function splitTrailingUrlPunctuation(urlText) {
        let clean = toStringSafe(urlText);
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
        const safeLabel = toStringSafe(labelText).toLowerCase();

        if (safeLabel.includes('сбп qr') || safeLabel.includes('qr code')) {
            return true;
        }

        try {
            const url = new URL(toStringSafe(href), window.location.origin);
            return toStringSafe(url.hostname).toLowerCase() === 'qr.nspk.ru';
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
        const source = toStringSafe(text);
        const links = [];

        function pushLinkToken(hrefRaw, labelText) {
            const safeHref = sanitizeLinkHref(hrefRaw);

            if (!safeHref) {
                return '';
            }

            const normalizedLabel = toStringSafe(labelText).trim();
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
                    toStringSafe(labelHtml).replace(/<[^>]*>/g, '')
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

        let s = toStringSafe(input);

        if (s.includes(' ') && !s.includes('T')) {
            s = s.replace(' ', 'T');
        }

        const d = new Date(s);
        return isNaN(d.getTime()) ? null : d;
    }

    function formatTime(input, locale) {
        const d = input instanceof Date ? input : parseDateSafe(input);
        if (!d) return '';

        return new Intl.DateTimeFormat(locale || 'ru-RU', {
            hour: '2-digit',
            minute: '2-digit'
        }).format(d);
    }

    function isSystemMessage(message) {
        return toStringSafe(message).startsWith('[system]');
    }

    function isMessageRead(message) {
        return Number(message && message.unread) === 0;
    }

    function shouldRerenderMessage(prev, next, options) {
        if (!prev) return true;

        const opts = options || {};
        const compareReplyAfter = !!opts.compareReplyAfter;
        const comparePending = !!opts.comparePending;

        const prevText = toStringSafe(prev.message);
        const nextText = toStringSafe(next.message);
        const prevCreated = toStringSafe(prev.created_at);
        const nextCreated = toStringSafe(next.created_at);
        const prevOp = Number(prev.is_operator) === 1;
        const nextOp = Number(next.is_operator) === 1;
        const prevUnread = Number(prev.unread || 0);
        const nextUnread = Number(next.unread || 0);
        const prevRead = isMessageRead(prev);
        const nextRead = isMessageRead(next);

        if (prevRead && nextRead) {
            return false;
        }

        if (
            prevText !== nextText ||
            prevCreated !== nextCreated ||
            prevOp !== nextOp ||
            prevUnread !== nextUnread
        ) {
            return true;
        }

        if (compareReplyAfter) {
            const prevReplyAfter = !!prev._hasOperatorReplyAfter;
            const nextReplyAfter = !!next._hasOperatorReplyAfter;

            if (prevReplyAfter !== nextReplyAfter) {
                return true;
            }
        }

        if (comparePending) {
            const prevPending = !!prev.pending;
            const nextPending = !!next.pending;

            if (prevPending !== nextPending) {
                return true;
            }
        }

        return false;
    }

    function parseFileMessagePayload(payload, fallbackName) {
        const safeFallback = toStringSafe(fallbackName || 'Файл');
        const rawPayload = toStringSafe(payload).trim();
        const sep = rawPayload.indexOf('|');

        let url = rawPayload;
        let name = safeFallback;

        if (sep !== -1) {
            url = rawPayload.slice(0, sep).trim();
            name = rawPayload.slice(sep + 1).trim() || safeFallback;
        } else {
            try {
                name = decodeURIComponent(url.split('/').pop() || safeFallback);
            } catch (e) {
                name = url.split('/').pop() || safeFallback;
            }
        }

        return {
            url: url,
            name: name
        };
    }

    window.CWShared = {
        escapeHtml: escapeHtml,
        decodeHtmlEntities: decodeHtmlEntities,
        sanitizeLinkHref: sanitizeLinkHref,
        sanitizeFileHref: sanitizeFileHref,
        getShortLinkLabel: getShortLinkLabel,
        splitTrailingUrlPunctuation: splitTrailingUrlPunctuation,
        isQrPaymentLink: isQrPaymentLink,
        getQrLinkIconHtml: getQrLinkIconHtml,
        renderMessageText: renderMessageText,
        parseDateSafe: parseDateSafe,
        formatTime: formatTime,
        isSystemMessage: isSystemMessage,
        isMessageRead: isMessageRead,
        shouldRerenderMessage: shouldRerenderMessage,
        parseFileMessagePayload: parseFileMessagePayload
    };
})(window);