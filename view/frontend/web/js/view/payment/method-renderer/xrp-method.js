define(
    [
        'Magento_Checkout/js/view/payment/default'
    ],
    function (Component) {
        'use strict';
        return Component.extend({
            defaults: {
                template: 'Hardcastle_LedgerDirect/payment/xrp'
            },
            getMailingAddress: function () {
                return window.checkoutConfig.payment.checkmo.mailingAddress;
            },
        });
    }
);
