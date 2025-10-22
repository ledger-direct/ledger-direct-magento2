define([
        'jquery',
        'Hardcastle_LedgerDirect/js/jquery-qrcode.min'
    ], function(){
        "use strict";

        $ = jQuery.noConflict();

        console.log('Ledger Direct main.js loaded');

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
        checkPaymentButton.on('click', checkPayment);

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

        /**
         * Handles the payment check process by retrieving the associated order ID,
         * disabling the check payment button, and reloading the page.
         *
         * @return {void} Does not return a value.
         */
        function checkPayment() {
            const checkPaymentButton = $('#check-payment-button');
            const orderId = checkPaymentButton.data('order-id');
            checkPaymentButton.disabled = true
            window.location.reload();
        }
    });

