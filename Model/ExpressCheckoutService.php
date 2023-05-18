<?php

namespace SpellPayment\ExpressCheckout\Model;

use Magento\Catalog\Model\Product;
use Magento\Checkout\Helper\Data as CheckoutHelper;
use Magento\Checkout\Model\Session;
use Magento\Checkout\Model\Type\Onepage;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\AuthenticationException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteRepository;
use Magento\Shipping\Model\Config;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use SpellPayment\ExpressCheckout\Helper\Data;
use SpellPayment\ExpressCheckout\Helper\SpellAPIFactory;

class ExpressCheckoutService
{
    const CODE = 'spellpayment_checkout';
    const SPELL_MODULE_VERSION = 'v1.3.1';

    /**
     * @var Data
     */
    private $moduleHelper;

    /**
     * @var SpellAPIFactory
     */
    private $spellAPIFactory;

    /**
     * @var ScopeConfigInterface
     */
    private $scope;

    /**
     * @var Quote
     */
    private $quote;

    /**
     * @var Session
     */
    private $checkoutSession;

    /**
     * @var QuoteRepository
     */
    private $quoteRepository;

    /**
     * @var CustomerSession
     */
    private $customerSession;

    /**
     * @var CheckoutHelper
     */
    private $checkoutHelper;

    /**
     * @var QuoteFilling
     */
    private $quoteFilling;

    /**
     * @var QuoteFactory
     */
    private $quoteFactory;

    /**
     * @var Json
     */
    private $json;
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var Config
     */
    private $shippingConfig;

    /**
     * ExpressCheckoutService constructor.
     *
     * @param Data $moduleHelper
     * @param CheckoutHelper $checkoutHelper
     * @param SpellAPIFactory $spellAPIFactory
     * @param ScopeConfigInterface $scope
     * @param Quote $quote
     * @param QuoteFactory $quoteFactory
     * @param Session $checkoutSession
     * @param QuoteRepository $quoteRepository
     * @param CustomerSession $customerSession
     * @param QuoteFilling $quoteFilling
     * @param Json $json
     * @param StoreManagerInterface $storeManager
     * @param Config $shippingConfig
     */
    public function __construct(
        Data                  $moduleHelper,
        CheckoutHelper        $checkoutHelper,
        SpellAPIFactory       $spellAPIFactory,
        ScopeConfigInterface  $scope,
        Quote                 $quote,
        QuoteFactory          $quoteFactory,
        Session               $checkoutSession,
        QuoteRepository       $quoteRepository,
        CustomerSession       $customerSession,
        QuoteFilling          $quoteFilling,
        Json                  $json,
        StoreManagerInterface $storeManager,
        Config $shippingConfig
    ) {
        $this->moduleHelper = $moduleHelper;
        $this->spellAPIFactory = $spellAPIFactory;
        $this->scope = $scope;
        $this->quote = $quote;
        $this->checkoutSession = $checkoutSession;
        $this->quoteRepository = $quoteRepository;
        $this->customerSession = $customerSession;
        $this->checkoutHelper = $checkoutHelper;
        $this->quoteFilling = $quoteFilling;
        $this->quoteFactory = $quoteFactory;
        $this->json = $json;
        $this->storeManager = $storeManager;
        $this->shippingConfig = $shippingConfig;
    }

    /**
     * Prepare and fill quote with address details from product page
     *
     * @param Product $product
     * @param array $productRequest
     * @throws LocalizedException
     */
    public function start(
        Product $product,
        array   $productRequest
    ): void {
        $this->prepareEmptyQuote();

        $this->quoteFilling->fillQuote(
            $this->quote,
            $product,
            $productRequest
        );

        $this->initSpell($this->quote);
        $this->quote->setInventoryProcessed(false);
        $this->quote->collectTotals();
        $this->quoteRepository->save($this->quote);
        $this->checkoutSession->setQuoteId($this->quote->getId());
    }

    /**
     * Prepare and fill quote with address details from cart/checkout page
     */
    public function startCart(): void
    {
        $this->prepareQuote();

        $this->initSpell($this->quote);
        $this->quote->setInventoryProcessed(false);
        $this->quote->collectTotals();
        $this->quoteRepository->save($this->quote);
    }

    /**
     * Initialize checkout/payment method
     *
     * @param Quote $quote
     */
    public function initSpell(Quote $quote): void
    {
        $this->ignoreAddressValidation();
        $quote->setCheckoutMethod($this->getCheckoutMethod());
        $payment = $quote->getPayment();
        $payment->setMethod(self::CODE);
        $quote->collectTotals();
    }

    /**
     * Get quote
     *
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    private function prepareQuote(): void
    {
        $this->quote = $this->_getQuote();
    }

    /**
     * Get empty quote
     *
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    private function prepareEmptyQuote(): void
    {
        $this->quote = $this->_getEmptyQuote();
    }

    /**
     * Return checkout quote object
     *
     * @return Quote
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    protected function _getQuote(): Quote
    {
        if ($this->checkoutSession->getQuoteId()) {
            $this->quote = $this->quoteRepository->get($this->checkoutSession->getQuoteId());
            $this->checkoutSession->replaceQuote($this->quote);
        } else {
            $this->quote = $this->checkoutSession->getQuote();
        }
        return $this->quote;
    }

    /**
     * Return empty quote object
     *
     * @return Quote
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    protected function _getEmptyQuote(): Quote
    {
        return $this->quoteFactory->create();
    }

    /**
     * Make sure addresses will be saved without validation errors
     *
     * @return void
     */
    private function ignoreAddressValidation(): void
    {
        $this->quote->getBillingAddress()->setShouldIgnoreValidation(true);
        if (!$this->quote->getIsVirtual()) {
            $this->quote->getShippingAddress()->setShouldIgnoreValidation(true);
        }
    }

    /**
     * Get checkout method
     *
     * @return string
     */
    public function getCheckoutMethod(): string
    {
        if ($this->customerSession->isLoggedIn()) {
            return Onepage::METHOD_CUSTOMER;
        }
        if (!$this->quote->getCheckoutMethod()) {
            if ($this->checkoutHelper->isAllowedGuestCheckout($this->quote)) {
                $this->quote->setCheckoutMethod(Onepage::METHOD_GUEST);
            } else {
                $this->quote->setCheckoutMethod(Onepage::METHOD_REGISTER);
            }
        }
        return $this->quote->getCheckoutMethod();
    }

    /**
     * Make parameters for spell payment API
     *
     * @param array|null $products
     * @return array|string[]
     * @throws AuthenticationException
     */
    public function directPaymentParams(array $products = null): array
    {
        if ($products === null) {
            $products = [];
            foreach ($this->quote->getAllItems() as $item) {
                $products[] = [
                    'product_id' => $item->getProduct()->getId(),
                    'name' => $item->getName(),
                    'price' => round($item->getPrice() * 100),
                    'quantity' => $item->getQty()
                ];
            }
        }

        $params = [
            'success_callback' => $this->moduleHelper->getReturnUrl(
                self::CODE,
                'success'
            ),
            'success_redirect' => $this->moduleHelper->getReturnUrl(
                self::CODE,
                'success'
            ),
            'failure_redirect' => $this->moduleHelper->getReturnUrl(
                self::CODE,
                'failure'
            ),
            'cancel_redirect' => $this->moduleHelper->getReturnUrl(
                self::CODE,
                'cancel'
            ),
            'creator_agent' => 'Magento2Module ' . self::SPELL_MODULE_VERSION,
            'platform' => 'magento',
            'client' => [
                'email' => 'dummy@data.com',
            ],
            'purchase' => [
                'currency' => $this->storeManager->getStore()->getCurrentCurrency()->getCode(),
                "products" => $products
            ],
            'brand_id' => $this->scope->getValue(
                'payment/spellpayment_checkout/shop_id',
                ScopeInterface::SCOPE_STORE
            )
        ];

        $shippingMethod = $this->getShippingMethod();

        $params['purchase']['shipping_options'] = $shippingMethod;

        $spell = $this->spellAPIFactory->create();
        $directPayment = $spell->createPayment($params);

        if (!$directPayment || !array_key_exists('id', $directPayment)) {
            return ['status' => 'failure'];
        }

        return [
            'status' => 'success',
            'data' => $directPayment,
        ];
    }

    /**
     * Return array for shipping options
     *
     * @return array
     */
    private function getShippingMethod(): array
    {
        $defaultCountry = $this->scope->getValue('general/country/default', ScopeInterface::SCOPE_WEBSITES)
            ?: $this->scope->getValue('tax/defaults/country', ScopeInterface::SCOPE_WEBSITES);

        $address = $this->quote->getShippingAddress();
        $address->setCountryId($defaultCountry)->collectShippingRates();
        $options = [];

        foreach ($address->getAllShippingRates() as $rate) {
            if ($rate->getCode() !== '_error') {
                $options[] = [
                    'id' => $rate->getCode(),
                    'label' => $rate->getCarrierTitle(),
                    'price' => $rate->getPrice()
                ];
            }
        }

        return $options;
    }
}
