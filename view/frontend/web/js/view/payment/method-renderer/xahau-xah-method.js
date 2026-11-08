define(
    [
        'Magento_Checkout/js/view/payment/default',
        'Magento_Checkout/js/action/place-order',
        'Magento_Checkout/js/model/full-screen-loader',
        'Magento_Ui/js/model/messageList',
        'mage/translate'
    ],
    function (
        Component,
        placeOrderAction,
        fullScreenLoader,
        globalMessageList,
        $t
    ) {
        'use strict';
        return Component.extend({
            defaults: {
                self: this,
                template: 'Hardcastle_LedgerDirect/payment/xrp',
                code: 'xahau_xah_payment',
                customRedirect: true,
                shouldPlaceOrder: true
            },

            redirectAfterPlaceOrder: false,

            /**
             * The asset's icon, from the module's static files
             */
            getIconUrl: function () {
                return require.toUrl('Hardcastle_LedgerDirect/images/xah_payment.svg');
            },

            placeOrder: function()
            {
                let self = this;

                placeOrderAction(self.getData(), self.messageContainer)
                    .then(function (response) {
                        const ledgerDirectSnippet = '/ledger-direct/payment/index?id=' + response;
                        window.location.assign(window.location.origin + ledgerDirectSnippet);
                    }, function (e) {
                        globalMessageList.addErrorMessage({
                            message: $t(e.responseJSON.message)
                        });
                    }).always(function () {
                    fullScreenLoader.stopLoader();
                });

                return false;
            },
        });
    }
);
