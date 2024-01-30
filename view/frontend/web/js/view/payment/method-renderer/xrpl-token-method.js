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
                template: 'Hardcastle_LedgerDirect/payment/xrpl-token',
                code: 'xrpl_token_payment',
                customRedirect: true,
                shouldPlaceOrder: true
            },

            redirectAfterPlaceOrder: false,

            getTitle: function() {
                  return 'Pay with Token | Ledger Direct';
            },

            placeOrder: function()
            {
                let self = this;

                placeOrderAction(self.getData(), self.messageContainer)
                    .then(function (response) {
                        const ledgerDirectXrpSnippet = '/ledger-direct/payment/index?id=' + response;
                        window.location.assign(window.location.origin + ledgerDirectXrpSnippet);
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
