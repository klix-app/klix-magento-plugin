define([
    'jquery',
    'ko',
    'underscore',
    'Magento_Ui/js/modal/confirm',
    'mage/translate',
    'mage/template',
    'mage/url',
    'uiRegistry',
    'domReady!'
], function ($, ko, _, confirm, $t, mageTemplate, urlBuilder) {
    'use strict';

    var mixin = {
        defaults: {
            buttonText: $t('Express Checkout'),
            purchaseUrl: urlBuilder.build('spellpayment/checkout/startCart'),
            imageUrl: window.checkout.imageUrl,
            expressCheckoutEnabled: window.checkout.expressCheckoutEnabled,
            productFormSelector: '#product_addtocart_form',
            expressButton: '#express-button',
            showButton: false
        },

        /** @inheritdoc */
        initialize: function () {
            this._super();
        },

        /**
         * Check if express button is enabled
         *
         * @returns {Boolean}
         */
        isExpressButtonEnabled: function () {
            return this.expressCheckoutEnabled == 1;
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
                url: this.purchaseUrl,
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
    };

    return function (target) {
        return target.extend(mixin);
    };
});
