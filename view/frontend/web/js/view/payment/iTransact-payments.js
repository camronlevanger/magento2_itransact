
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
                type: 'iTransact',
                component: 'CamronLevanger_Itransact/js/view/payment/method-renderer/itransact-method'
            }
        );
        /** Add view logic here if needed */
        return Component.extend({});
    }
);
