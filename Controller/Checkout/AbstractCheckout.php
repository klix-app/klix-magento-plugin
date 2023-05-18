<?php

namespace SpellPayment\ExpressCheckout\Controller\Checkout;

use SpellPayment\ExpressCheckout\Model\ExpressCheckoutService;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteRepository;

abstract class AbstractCheckout extends Action implements HttpPostActionInterface, HttpGetActionInterface
{
    /**
     * @var Session
     */
    protected $_checkoutSession;

    /**
     * @var Quote
     */
    protected $_quote;

    /**
     * @var QuoteRepository
     */
    protected $quoteRepository;

    /**
     * @var ExpressCheckoutService
     */
    protected $expressCheckoutService;

    /**
     * AbstractCheckout constructor.
     * @param Context $context
     * @param Session $checkoutSession
     * @param Quote $quote
     * @param QuoteRepository $quoteRepository
     * @param ExpressCheckoutService $expressCheckoutService
     */
    public function __construct(
        Context $context,
        Session $checkoutSession,
        Quote $quote,
        QuoteRepository $quoteRepository,
        ExpressCheckoutService $expressCheckoutService
    ) {
        parent::__construct($context);
        $this->_checkoutSession = $checkoutSession;
        $this->_quote = $quote;
        $this->quoteRepository = $quoteRepository;
        $this->expressCheckoutService = $expressCheckoutService;
    }

    /**
     * @inheritdoc
     */
    abstract public function execute();

    /**
     * Return checkout session object
     *
     * @return \Magento\Checkout\Model\Session
     */
    protected function _getCheckoutSession()
    {
        return $this->_checkoutSession;
    }

    /**
     * Return checkout session object
     *
     * @return Quote
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function _getQuote()
    {
        return $this->_quote;
    }

    /**
     * Return checkout quote object
     *
     * @return \Magento\Quote\Model\Quote
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function _prepareQuote()
    {
        if ($this->_getCheckoutSession()->getQuoteId()) {
            $this->_quote = $this->quoteRepository->get($this->_getCheckoutSession()->getQuoteId());
            $this->_getCheckoutSession()->replaceQuote($this->_quote);
        } else {
            $this->_quote = $this->_getCheckoutSession()->getQuote();
        }
        return $this->_quote;
    }

    /**
     * Ignore Address Validation for shipping and billing
     */
    protected function ignoreAddressValidation()
    {
        $this->_quote->getBillingAddress()->setShouldIgnoreValidation(true);
        if (!$this->_quote->getIsVirtual()) {
            $this->_quote->getShippingAddress()->setShouldIgnoreValidation(true);
        }
    }
}
