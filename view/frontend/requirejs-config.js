var config = {
    map: {
        '*': {
            'Magento_Checkout/template/shipping': 'SpellPayment_ExpressCheckout/template/shipping',
            'Magento_Checkout/template/minicart/content': 'SpellPayment_ExpressCheckout/template/minicart/content'
        }
    },
    config: {
        mixins: {
            'Magento_Checkout/js/view/shipping': {
                'SpellPayment_ExpressCheckout/js/view/shipping-mixin': true
            },
            'Magento_Checkout/js/view/minicart': {
                'SpellPayment_ExpressCheckout/js/view/minicart-mixin': true
            }
        }
    }
};
