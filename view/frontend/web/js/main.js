/**
 * Payment page behaviour: count the quote down, and ask the server which state the
 * payment is in.
 *
 * Both are conveniences. The page is fully usable without JavaScript - the amount,
 * destination and tag are server-rendered, every state block exists in the markup, the
 * check button is a plain request, and the cron settles the order regardless of whether
 * anyone is watching this page.
 *
 * The poll answers with the core's payment-status payload (state, amounts, seconds left)
 * plus a `redirect` once the order no longer waits. This script knows no sentence a
 * customer reads: it switches the server-rendered blocks and fills in two numbers.
 */
define([
        'jquery',
        'Hardcastle_LedgerDirect/js/jquery-qrcode.min'
    ], function(){
        "use strict";

        $ = jQuery.noConflict();

        const POLL_INTERVAL_MS = 8000;

        const page = document.querySelector('[data-xrp-payment-page]');
        if (!page) {
            return;
        }

        const destinationAccount = $('#destination-account');
        const destinationAccountCopy = destinationAccount.next().children().eq(0);
        const destinationAccountQrCode = destinationAccount.next().children().eq(1);
        const destinationTag = $('#destination-tag');
        const destinationTagCopy = destinationTag.next().children().eq(0);
        const destinationTagQrCode = destinationTag.next().children().eq(1);

        destinationAccountCopy.on('click', copyToClipboard.bind(this, destinationAccount, destinationAccountCopy));
        destinationTagCopy.on('click', copyToClipboard.bind(this, destinationTag, destinationTagCopy));

        attachQrCodeTooltip(destinationAccountQrCode, destinationAccount.attr("data-value"));
        attachQrCodeTooltip(destinationTagQrCode, destinationTag.attr("data-value"));

        const checkPaymentButton = $('#check-payment-button');
        const spinner = checkPaymentButton.find('span');
        checkPaymentButton.on('click', checkPayment);

        const blocks = {
            waiting: page.querySelector('[data-ld-live]'),
            expired: page.querySelector('[data-ld-expired]'),
            partial: page.querySelector('[data-ld-partial]'),
            wrong_asset: page.querySelector('[data-ld-wrong-asset]')
        };
        const countdown = page.querySelector('[data-ld-countdown]');
        const pollUrl = page.getAttribute('data-ld-poll-url');
        let state = page.getAttribute('data-ld-state') || 'waiting';
        let secondsLeft = parseInt(page.getAttribute('data-ld-seconds-left'), 10);
        let pollTimer = null;

        startCountdown();
        schedulePoll();

        /* ---- polling ---- */

        function schedulePoll() {
            if (!pollUrl) {
                return;
            }
            pollTimer = setTimeout(poll, POLL_INTERVAL_MS);
        }

        function fetchStatus() {
            return fetch(pollUrl, {
                credentials: 'same-origin',
                headers: {'Accept': 'application/json'}
            }).then(function (response) {
                // A failed poll is not worth surfacing - the next one may well work, and
                // the cron is the actual guarantee.
                return response.status === 200 ? response.json() : null;
            }).catch(function () {
                return null;
            });
        }

        /**
         * The background poll: no spinner, no disabled button - the customer did not ask
         * for anything.
         */
        function poll() {
            pollTimer = null;
            fetchStatus().then(function (payload) {
                if (applyStatus(payload)) {
                    schedulePoll();
                }
            });
        }

        /**
         * The button: the same request, with the spinner while it runs.
         */
        function checkPayment() {
            if (pollTimer) {
                clearTimeout(pollTimer);
                pollTimer = null;
            }
            spinner.show();
            checkPaymentButton.prop('disabled', true);
            fetchStatus().then(function (payload) {
                spinner.hide();
                checkPaymentButton.prop('disabled', false);
                if (applyStatus(payload)) {
                    schedulePoll();
                }
            });
        }

        /**
         * @returns {boolean} whether to keep polling
         */
        function applyStatus(payload) {
            if (!payload) {
                return true;
            }

            // Whatever ended the wait - settled on-chain, canceled by the merchant or by
            // Magento's pending-payment cron - the server sends where to go. That, and only
            // that, stops the polling: a partial payment keeps polling so the top-up is
            // noticed, and an expired quote keeps polling so a late payment is.
            if (payload.redirect) {
                window.location.href = payload.redirect;
                return false;
            }

            if (payload.state === 'partial' || payload.state === 'wrong_asset') {
                fillAmounts(blocks[payload.state], payload);
                showState(payload.state);
            } else if (payload.state === 'expired') {
                secondsLeft = 0;
                showState('expired');
            } else if (payload.state === 'waiting') {
                // Trust the server's clock over the browser's: it is the one that decides
                // whether the quote still stands.
                if (typeof payload.seconds_left === 'number') {
                    secondsLeft = payload.seconds_left;
                }
                showState('waiting');
                renderCountdown();
            }

            return true;
        }

        /* ---- state blocks ---- */

        function showState(nextState) {
            state = nextState;
            page.setAttribute('data-ld-state', nextState);

            Object.keys(blocks).forEach(function (name) {
                if (blocks[name]) {
                    blocks[name].hidden = name !== nextState;
                }
            });
        }

        /* ---- amounts ---- */

        /**
         * The only formatting in this script, and the same rule the server uses: the plain
         * decimal the core states - a native amount arrives as a number, a token amount as
         * an object with a value. Nothing is computed or rounded here; the server already
         * decided what is paid and what is missing.
         */
        function formatAmount(amount) {
            if (amount === null || amount === undefined) {
                return '';
            }
            if (typeof amount === 'number') {
                return String(amount);
            }
            return typeof amount.value === 'string' ? amount.value : '';
        }

        function fillAmounts(block, payload) {
            if (!block) {
                return;
            }
            const paid = block.querySelector('[data-ld-paid]');
            const shortfall = block.querySelector('[data-ld-shortfall]');
            if (paid) {
                paid.textContent = formatAmount(payload.amount_paid);
            }
            if (shortfall) {
                shortfall.textContent = formatAmount(payload.shortfall);
            }
        }

        /* ---- countdown ---- */

        function startCountdown() {
            if (isNaN(secondsLeft)) {
                return;
            }
            renderCountdown();
            setInterval(function () {
                secondsLeft -= 1;
                renderCountdown();
            }, 1000);
        }

        function renderCountdown() {
            if (!countdown || isNaN(secondsLeft)) {
                return;
            }

            if (secondsLeft <= 0) {
                // Only swaps which block is visible, and only while nothing has arrived:
                // once a payment is in, the partial/wrong-asset block stays and the refresh
                // button must not be offered. The refreshed amount comes from the server on
                // submit - this never recomputes a price in the browser.
                if (state === 'waiting') {
                    showState('expired');
                }
                return;
            }

            const minutes = Math.floor(secondsLeft / 60);
            const seconds = secondsLeft % 60;
            countdown.textContent = minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
        }

        /* ---- copy & QR ---- */

        /**
         * Copies the value of the given element to the clipboard.
         *
         * @param element
         * @param icon
         * @param event
         */
        function copyToClipboard(element, icon, event) {
            if (typeof navigator.clipboard === 'undefined') {
                console.log('Clipboard API not supported - is this a secure context?');

                return;
            }

            const message = 'copied!';
            navigator.clipboard.writeText(element.attr("data-value")).then(() => {
                showCopyFeedback(message, icon);
            }).catch(err => {
                console.error('Failed to copy: ', err);
                showCopyFeedback('Failed to copy to clipboard', icon, true);
            });
        }

        /**
         * Shows a temporary toast notification for copy feedback
         * @param {string} message
         * @param {boolean} isError
         */
        function showCopyFeedback(message, icon, isError = false) {
            $('.copy-toast').remove();

            const toast = $('<div class="copy-toast">')
                .text(message)
                .css('background-color', isError ? '#f44336' : '#1daae6');

            icon.parent().append(toast);

            setTimeout(() => {
                toast.addClass('fade-out');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        /**
         * Attaches a QR code tooltip to the given element.
         * The tooltip will display a QR code with the given value.
         * @param element
         * @param value
         */
        function attachQrCodeTooltip(element, value) {
            element.tooltipster({
                theme: 'tooltipster-shadow',
                //contentAsHTML: true,
                content: $('<div id="qrcode" style="width: 256px; height: 260px;">' + value + '</div>'),
                trigger: 'click',
                maxwidth: 256,
                functionReady: function() {
                    $('#qrcode').empty().qrcode({
                        width: 256,
                        height: 256,
                        text: value
                    });
                }
            });
        }
    });
