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
                code: 'xrp_payment',
                customRedirect: true,
                shouldPlaceOrder: true
            },

            redirectAfterPlaceOrder: false,

            placeOrder: function()
            {
                console.log(' -- LedgerDirect XRP payment placeOrder: ---');

                const ledgerDirectXrpSnippet = '/ledger-direct/payment/index?id=123'

                window.location.assign(window.location.origin + ledgerDirectXrpSnippet);

                var self = this;

                placeOrderAction(self.getData(), self.messageContainer)
                    .then(function () {
                        getPaymentUrlAction(self.messageContainer).always(function () {
                            fullScreenLoader.stopLoader();
                        }).then(function (response) {
                            fullScreenLoader.startLoader();
                            self.redirect(response);
                        }, function () {
                            globalMessageList.addErrorMessage({
                                message: $t('An error occurred on the server. Please try to place the order again.')
                            });
                        });
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
