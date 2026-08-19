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
        rendererList.push(
            {
                type: 'xrpl_rlusd_payment',
                component: 'Hardcastle_LedgerDirect/js/view/payment/method-renderer/xrpl-rlusd-method'
            }
        );
        rendererList.push(
            {
                type: 'xrpl_usdc_payment',
                component: 'Hardcastle_LedgerDirect/js/view/payment/method-renderer/xrpl-usdc-method'
            }
        );
        return Component.extend({});
    }
);
