define([
    'jquery',
    'ko',
    'underscore',
    'Magento_Ui/js/modal/confirm',
    'mage/translate',
    'mage/template',
    'mage/url',
    'uiComponent'
], function ($, ko, _, confirm, $t, mageTemplate, urlBuilder, Component) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'SpellPayment_ExpressCheckout/express-checkout-cart',
            buttonText: $t('Express Checkout'),
            purchaseUrlCart: urlBuilder.build('spellpayment/checkout/startCart'),
            productFormSelector: '#product_addtocart_form',
            expressButton: '#express-button',
            showButton: true
        },

        /** @inheritdoc */
        initialize: function () {
            this._super();
        },

        /** @inheritdoc */
        initObservable: function () {
            this._super()
                .observe('showButton');

            return this;
        },

        /**
         * Trigger popup for confirmation of express checkout
         */
        expressCheckout: function () {
            var form = $(this.productFormSelector);

            if (!(form.validation() && form.validation('isValid'))) {
                return;
            }

            $.ajax({
                url: this.purchaseUrlCart,
                data: form.serialize(),
                type: 'post',
                dataType: 'json',

                /** Show loader before send */
                beforeSend: function () {
                    $('body').trigger('processStart');
                }
            }).done(function (response) {
                window.location.href = response.returnUrl;
            }).always(function () {
                $('body').trigger('processStop');
            });
        }
    });
});
