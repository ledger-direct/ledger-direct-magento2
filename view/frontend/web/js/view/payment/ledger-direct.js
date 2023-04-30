define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'ledger-direct-xrp',
                component: 'Hardcastle_LedgerDirect/js/view/payment/method-renderer/xrp-method'
            }
        );
        return Component.extend({});
    }
);
