(function ($) {
    'use strict';

    const TEASER_ID = 'cw-chat-teaser';
    const TEASER_HIDE_UNTIL_KEY = 'cw_teaser_hide_until';
    const TEASER_INDEX_KEY = 'cw_teaser_index';
    const TEASER_MIN_DELAY = 3000;
    const TEASER_MAX_DELAY = 4000;
    const TEASER_HIDE_MS = 24 * 60 * 60 * 1000;

    const TEASER_MESSAGES = [
        'Есть вопрос? Напишите',
        'Нужна помощь? Мы онлайн',
        'Подскажем по заказу',
        'Помочь с выбором?',
        'Ответим в чате'
    ];

    let teaserTimer = null;
    let observer = null;

    function getOpenBtn() {
        return $('#cw-open-btn');
    }

    function getChatBox() {
        return $('#cw-chat-box');
    }

    function isChatOpen() {
        const $chatBox = getChatBox();
        return $chatBox.length && $chatBox.is(':visible');
    }

    function clearTeaserTimer() {
        if (teaserTimer) {
            clearTimeout(teaserTimer);
            teaserTimer = null;
        }
    }

    function getRandomDelay() {
        return Math.floor(Math.random() * (TEASER_MAX_DELAY - TEASER_MIN_DELAY + 1)) + TEASER_MIN_DELAY;
    }

    function setCooldown() {
        localStorage.setItem(TEASER_HIDE_UNTIL_KEY, String(Date.now() + TEASER_HIDE_MS));
    }

    function getHiddenUntil() {
        return Number(localStorage.getItem(TEASER_HIDE_UNTIL_KEY) || 0);
    }

    function getStoredTeaserIndex() {
        if (!TEASER_MESSAGES.length) {
            return 0;
        }

        const index = Number(localStorage.getItem(TEASER_INDEX_KEY) || 0);

        if (!Number.isFinite(index) || index < 0) {
            return 0;
        }

        return index % TEASER_MESSAGES.length;
    }

    function setStoredTeaserIndex(index) {
        localStorage.setItem(TEASER_INDEX_KEY, String(index));
    }

    function getNextTeaserMessage() {
        if (!TEASER_MESSAGES.length) {
            return 'Есть вопрос? Напишите';
        }

        const index = getStoredTeaserIndex();
        const text = TEASER_MESSAGES[index];
        const nextIndex = (index + 1) % TEASER_MESSAGES.length;

        setStoredTeaserIndex(nextIndex);

        return text;
    }

    function ensureTeaser() {
        let $teaser = $('#' + TEASER_ID);

        if ($teaser.length) {
            return $teaser;
        }

        $('body').append(
            '<div id="' + TEASER_ID + '" style="display:none;" aria-live="polite">' +
                '<button type="button" class="cw-chat-teaser-close" aria-label="Закрыть подсказку">×</button>' +
                '<div class="cw-chat-teaser-body" role="button" tabindex="0" aria-label="Открыть чат">' +
                    '<div class="cw-chat-teaser-text"></div>' +
                '</div>' +
            '</div>'
        );

        return $('#' + TEASER_ID);
    }

    function setTeaserText(text) {
        const $teaser = ensureTeaser();
        $teaser.find('.cw-chat-teaser-text').text(text || '');
    }

    function canShowTeaser() {
        if (!getOpenBtn().length) return false;
        if (document.hidden) return false;
        if (isChatOpen()) return false;
        if (getHiddenUntil() > Date.now()) return false;

        return true;
    }

    function showTeaser() {
        clearTeaserTimer();

        if (!canShowTeaser()) return;

        setTeaserText(getNextTeaserMessage());

        const $teaser = ensureTeaser();
        $teaser.stop(true, true).fadeIn(200);
    }

    function hideTeaser(withCooldown) {
        const $teaser = $('#' + TEASER_ID);

        clearTeaserTimer();

        if (withCooldown) {
            setCooldown();
        }

        if ($teaser.length) {
            $teaser.stop(true, true).fadeOut(180);
        }
    }

    function scheduleTeaser() {
        clearTeaserTimer();

        if (!canShowTeaser()) return;

        teaserTimer = setTimeout(function () {
            showTeaser();
        }, getRandomDelay());
    }

    function openChatFromTeaser() {
        const $openBtn = getOpenBtn();
        if (!$openBtn.length) return;

        hideTeaser(true);
        $openBtn.trigger('click');
    }

    function bindTeaserEvents() {
        $(document).on('click', '#' + TEASER_ID + ' .cw-chat-teaser-body', function () {
            openChatFromTeaser();
        });

        $(document).on('keydown', '#' + TEASER_ID + ' .cw-chat-teaser-body', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                openChatFromTeaser();
            }
        });

        $(document).on('click', '#' + TEASER_ID + ' .cw-chat-teaser-close', function (e) {
            e.preventDefault();
            e.stopPropagation();
            hideTeaser(true);
        });

        $(document).on('click', '#cw-open-btn', function () {
            hideTeaser(true);
        });

        $(document).on('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                clearTeaserTimer();
                return;
            }

            if (document.visibilityState === 'visible') {
                scheduleTeaser();
            }
        });

        $(window).on('pagehide beforeunload', function () {
            clearTeaserTimer();
        });
    }

    function watchChatVisibility() {
        const chatBox = getChatBox().get(0);
        if (!chatBox || typeof MutationObserver === 'undefined') {
            return;
        }

        observer = new MutationObserver(function () {
            if (isChatOpen()) {
                hideTeaser(false);
            }
        });

        observer.observe(chatBox, {
            attributes: true,
            attributeFilter: ['style', 'class']
        });
    }

    $(function () {
        if (!getOpenBtn().length) {
            return;
        }

        ensureTeaser();
        bindTeaserEvents();
        watchChatVisibility();
        scheduleTeaser();
    });
})(jQuery);