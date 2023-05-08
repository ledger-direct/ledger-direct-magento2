define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list',
        'Magento_Checkout/js/model/payment-service',
    ],
    function (
        Component,
        rendererList,
        paymentService
    ) {
        'use strict';

        window.testRendererList = rendererList;
        window.paymentService = paymentService;

        rendererList.push(
            {
                type: 'xrp_payment',
                component: 'Hardcastle_LedgerDirect/js/view/payment/method-renderer/xrp-method'
            }
        );
        return Component.extend({});
    }
);
