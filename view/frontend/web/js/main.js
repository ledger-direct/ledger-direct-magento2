require([],
    function(){
        "use strict";

        $ = jQuery.noConflict();

        const destinationAccount = $('#destination-account');
        const destinationAccountCopy = destinationAccount.next().children().eq(0);
        const destinationAccountQrCode = destinationAccount.next().children().eq(1);
        const destinationTag = $('#destination-tag');
        const destinationTagCopy = destinationTag.next().children().eq(0);
        const destinationTagQrCode = destinationTag.next().children().eq(1);

        const checkPaymentButton = $('#check-payment-button');

        console.log(this)
        //destinationAccountCopy.on('click', copyToClipboard.bind(this, destinationAccount));
        //destinationTagCopy.on('click', copyToClipboard.bind(this, destinationTag));

        checkPaymentButton.on('click', checkPayment);

        function checkPayment() {
            const checkPaymentButton = $('#check-payment-button');
            const orderId = checkPaymentButton.data('order-id');
            checkPaymentButton.disabled = true
            window.location.reload();
        }
    });

