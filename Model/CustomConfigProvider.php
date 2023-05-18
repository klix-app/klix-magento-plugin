<?php

namespace SpellPayment\ExpressCheckout\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\AuthenticationException;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use SpellPayment\ExpressCheckout\Helper\SpellAPIFactory;

class CustomConfigProvider implements ConfigProviderInterface
{
    /**
     * @var LoggerInterface
     */
    protected $_logger;

    /**
     * @var StoreManagerInterface
     */
    protected $_storeManager;

    /**
     * @var ScopeConfigInterface
     */
    protected $_scopeConfig;

    /**
     * @var ResolverInterface
     */
    private $localeResolver;

    /**
     * @var SpellAPIFactory
     */
    private $spellAPIFactory;

    /**
     * CustomConfigProvider constructor
     *
     * @param LoggerInterface $logger
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     * @param ResolverInterface $localeResolver
     * @param SpellAPIFactory $spellAPIFactory
     */
    public function __construct(
        LoggerInterface $logger,
        StoreManagerInterface $storeManager,
        ScopeConfigInterface $scopeConfig,
        ResolverInterface $localeResolver,
        SpellAPIFactory $spellAPIFactory
    ) {
        $this->_logger = $logger;
        $this->_storeManager = $storeManager;
        $this->_scopeConfig = $scopeConfig;
        $this->localeResolver = $localeResolver;
        $this->spellAPIFactory = $spellAPIFactory;
    }

    //phpcs:ignore Magento2.Functions.StaticFunction
    /**
     * Collect by method
     *
     * @param $by_country
     * @return array
     */
    private function collectByMethod($by_country): array
    {
        $by_method = [];
        foreach ($by_country as $country => $pms) {
            foreach ($pms as $pm) {
                if (!array_key_exists($pm, $by_method)) {
                    $by_method[$pm] = [
                        "payment_method" => $pm,
                        "countries" => [],
                    ];
                }
                if (!in_array($country, $by_method[$pm]["countries"])) {
                    $by_method[$pm]["countries"][] = $country;
                }
            }
        }
        return $by_method;
    }

    /**
     * Get country options
     *
     * @param $payment_methods
     * @return array|string[]
     */
    private function getCountryOptions($payment_methods): array
    {
        $country_options = array_values(array_unique(
            array_keys($payment_methods['by_country'])
        ));
        $any_index = array_search('any', $country_options);
        if ($any_index !== false) {
            array_splice($country_options, $any_index, 1);
            $country_options = array_merge($country_options, ['any']);
        }
        return $country_options;
    }

    /**
     * Get config
     *
     * @return array
     * @throws AuthenticationException
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getConfig()
    {
        $config = [];
        if (!$this->_scopeConfig->getValue('payment/spellpayment_checkout/active', ScopeInterface::SCOPE_STORE)) {
            return $config;
        }

        $currency = $this->_storeManager->getStore()->getCurrentCurrency()->getCode();
        $language = strstr($this->localeResolver->getLocale(), '_', true);
        $spell = $this->spellAPIFactory->create();
        $payment_methods = $spell->paymentMethods($currency, $language);
        $msgItem = $payment_methods['__all__'][0] ?? null;
        if ('authentication_failed' === ($msgItem['code'] ?? null)) {
            $msg = 'Klix authentication_failed - ' .
                ($msgItem['message'] ?? '(no message)');
            throw new AuthenticationException(__($msg));
        }

        $payment_method_selection_enabled = +$this->_scopeConfig->getValue(
            'payment/spellpayment_checkout/payment_method_selection_enabled',
            ScopeInterface::SCOPE_STORE
        ) ? true : false;
        $country_options = $this->getCountryOptions($payment_methods);
        $config['spellpayment_checkout'] = [
            'title' => $payment_method_selection_enabled
                ? ($this->_scopeConfig->getValue(
                    'payment/spellpayment_checkout/payment_method_description',
                    ScopeInterface::SCOPE_STORE
                ) ?: 'Select payment method')
                : ($this->_scopeConfig->getValue(
                    'payment/spellpayment_checkout/payment_method_title',
                    ScopeInterface::SCOPE_STORE
                ) ?: 'Choose payment method on next page'),
            'payment_method_selection_enabled' => $payment_method_selection_enabled,
            'payment_methods_api_data' => $payment_methods,
            'country_options' => $country_options,
            'by_method' => $this->collectByMethod($payment_methods['by_country'])
        ];

        return $config;
    }
}
