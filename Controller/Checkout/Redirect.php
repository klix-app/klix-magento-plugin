<?php

namespace SpellPayment\ExpressCheckout\Controller\Checkout;

use InvalidArgumentException;
use Magento\Checkout\Model\Session;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Redirect as ResultRedirect;
use Magento\Framework\Exception\AuthenticationException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Framework\Webapi\Exception;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteRepository;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use SpellPayment\ExpressCheckout\Helper\Checkout;
use SpellPayment\ExpressCheckout\Helper\SpellAPIFactory;
use SpellPayment\ExpressCheckout\Model\OrderFinder;
use SpellPayment\ExpressCheckout\Model\Transaction;

/**
 * Process customers redirect from Gateway and Webhook update requests
 */
class Redirect extends Action implements CsrfAwareActionInterface
{
    const STATUS_PAID = 'paid';
    const STATUS_CREATED = 'created';

    const DEFAULT_SHIPPING_METHOD = 'flatrate_flatrate';

    /**
     * @var Order $order
     */
    private $order;

    /**
     * @var Transaction
     */
    protected $transaction;

    /**
     * @var Context
     */
    protected $context;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var Session
     */
    protected $checkoutSession;

    /**
     * @var OrderFinder
     */
    protected $orderFinder;

    /**
     * @var Checkout
     */
    private $checkoutHelper;

    /**
     * @var SpellAPIFactory
     */
    private $spellAPIFactory;

    /**
     * @var QuoteRepository
     */
    private $quoteRepository;

    /**
     * @var CartManagementInterface
     */
    private $cartManagement;

    /**
     * @var Quote
     */
    private $quote;

    /**
     * @var \Magento\Customer\Model\Session
     */
    private $customerSession;

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * Constructor for expressConfigProvider
     *
     * @param Context $context
     * @param LoggerInterface $logger
     * @param ScopeConfigInterface $scopeConfig
     * @param Session $checkoutSession
     * @param OrderFinder $orderFinder
     * @param Transaction $transaction
     * @param Checkout $checkoutHelper
     * @param SpellAPIFactory $spellAPIFactory
     * @param CartManagementInterface $cartManagement
     * @param Quote $quote
     * @param QuoteRepository $quoteRepository
     * @param \Magento\Customer\Model\Session $customerSession
     * @param CustomerRepositoryInterface $customerRepository
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        Context $context,
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        Session $checkoutSession,
        OrderFinder $orderFinder,
        Transaction $transaction,
        Checkout $checkoutHelper,
        SpellAPIFactory $spellAPIFactory,
        CartManagementInterface $cartManagement,
        Quote $quote,
        QuoteRepository $quoteRepository,
        \Magento\Customer\Model\Session $customerSession,
        CustomerRepositoryInterface $customerRepository,
        StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
        $this->context = $context;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->checkoutSession = $checkoutSession;
        $this->orderFinder = $orderFinder;
        $this->transaction = $transaction;
        $this->checkoutHelper = $checkoutHelper;
        $this->spellAPIFactory = $spellAPIFactory;
        $this->quoteRepository = $quoteRepository;
        $this->cartManagement = $cartManagement;
        $this->quote = $quote;
        $this->customerSession = $customerSession;
        $this->customerRepository = $customerRepository;
        $this->storeManager = $storeManager;
    }

    /**
     * Handle the result from the Payment Gateway
     * Receives two types of requests, one is customers 'redirect back to merchant' where we load data from session
     *  the other one is Webhook request from gateway. There we look up order information in database.
     *
     * @return ResponseInterface|ResultRedirect
     * @throws LocalizedException
     */
    public function execute()
    {
        $isWebhook = $this->getRequest()->isPost();
        $data = $isWebhook ? $this->loadPostData() : $this->getRequest()->getParams();
        $action = $this->getRequest()->getParam('action');

        switch ($action) {
            case 'success':
                $this->processSuccess($data);
                break;
            case 'failure':
                $this->processFailure($data);
                break;
            case 'cancel':
                $this->processCancellation();
                break;
            case 'back':
                $this->processBack();
                break;
            default:
                $this->getResponse()->setHttpResponseCode(Exception::HTTP_NOT_FOUND);
                $this->getResponse()->setBody('Unknown redirect action - ' . $action);
        }
    }

    /**
     * This is incoming webhook request so CSRF doesn't exactly apply
     *
     * @return bool
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Handle express checkout
     *
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @throws AuthenticationException
     */
    private function handleExpressCheckout()
    {
        if ($this->checkoutSession->get_expressCheckout()) {
            $spellPayment = $this->spellAPIFactory
                ->create()
                ->purchases($this->checkoutSession->get_spelPaymentId());
            if (array_key_exists('client', $spellPayment)) {
                $client = $spellPayment['client'];
                $this->logger->info('Client data from Klix', $client);
                $customer = $this->customerSession->getCustomer();
                $orderData = $this->getOrderData($client);
                $quote = $this->_getQuote();
                $quote->setStore($this->storeManager->getStore());
                $quote->getShippingAddress()->setShouldIgnoreValidation(true);
                $quote->getBillingAddress()->setShouldIgnoreValidation(true);
                if ($customer->getId()) {
                    $customer = $this->customerRepository->getById($customer->getId());
                    $quote->setCustomer($customer);
                    $quote->setCustomerIsGuest(false);
                } else {
                    $quote->setCustomerEmail($orderData['email']);
                    $quote->setCustomerIsGuest(true);
                }
                $quote->getBillingAddress()->addData($orderData['shipping_address']);
                $quote->getShippingAddress()->addData($orderData['shipping_address']);
                $quote->getShippingAddress()->setShippingMethod($this->getShippingMethodId(
                    $quote->getShippingAddress(),
                    $orderData['shipping_address']['street']
                ))
                    ->setCollectShippingRates(true)
                    ->collectShippingRates();
                $quote->getPayment()->setMethod('spellpayment_checkout');
                $quote->setPaymentMethod('spellpayment_checkout'); //payment method
                $quote->getPayment()->setTransactionId($this->checkoutSession->get_spelPaymentId());
                $quote->getPayment()->setIsTransactionPending(true);
                $quote->getPayment()->setIsTransactionClosed(false);
                $quote->collectTotals()->save();
                $this->place($quote);

                $this->checkoutSession->clearHelperData();

                $quoteId = $quote->getId();
                $this->checkoutSession
                    ->setLastQuoteId($quoteId)
                    ->setLastSuccessQuoteId($quoteId);

                $order = $this->order;
                if ($order) {
                    $this->checkoutSession->setLastOrderId($order->getId())
                        ->setLastRealOrderId($order->getIncrementId())
                        ->setLastOrderStatus($order->getStatus());
                }
            }
        }
    }

    /**
     * We will always get it as a first element before ","
     *
     * @param $shippingAddress
     * @param $streetAddress
     * @return mixed|string
     */
    private function getShippingMethodId($shippingAddress, $streetAddress)
    {
        $array = explode(",", $streetAddress);

        foreach ($shippingAddress->collectShippingRates()->getAllShippingRates() as $rate) {
            if ($rate->getCode() === $array[0]) {
                return $rate->getCode();
            }
        }

        return self::DEFAULT_SHIPPING_METHOD;
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
     * Process order success
     *
     * @param array $data
     *
     * @return void
     * @throws InputException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @throws AuthenticationException
     */
    private function processSuccess(array $data): void
    {
        $this->handleExpressCheckout();
        $isWebhook = $this->getRequest()->isPost();
        $purchase = $this->findSpellOrder($data);
        $order = $this->findMagentoOrder($data);

        $status = $purchase['status'];
        if ($status === self::STATUS_PAID
            && $this->canInvoice($order)
            && !$this->isInvoiced($order)
        ) {
            try {
                if ($isWebhook) {
                    $order->addCommentToStatusHistory($this->statusText($purchase));
                }
                $this->transaction->invoice($order);
            } catch (LocalizedException $exception) {
                $this->logger->critical($exception->getMessage());
                $this->logger->critical($exception->getTraceAsString());
                throw $exception;
            }
        }

        // Not very interested in 'created' statuses so return 200 regardless so that webhook stops
        // Other type is 'paid' and if order is invoiced, 200 too
        if ($status === self::STATUS_CREATED || ($isWebhook && $this->isInvoiced($order))) {
            $this->getResponse();
            return;
        }

        $this->_redirect('checkout/onepage/success');
    }

    /**
     * Process failure
     *
     * @param array $data
     * @throws AuthenticationException|LocalizedException
     */
    private function processFailure(array $data): void
    {
        /**
         * A custom handler for the "Back" -> "Forward" browser button case
         */
        $message = isset($data['id']) ? __('Klix order cannot be found in request') : __('Unrecognized error');
        $purchase = isset($data['id']) ? $this->findSpellOrder($data) : [];

        if (isset($purchase['transaction_data']['attempts'])) {
            $attempts = count($purchase['transaction_data']['attempts']);
            $message = $purchase['transaction_data']['attempts'][$attempts - 1]['error']['message'] ?? '';
        }

        $comment = __('Gateway system failed to process payment - %1', $message);
        if (!$this->checkoutSession->get_expressCheckout()) {
            $this->checkoutSession->set_expressCheckout(false);
            $this->checkoutHelper->cancelCurrentOrderAndRestoreQuote($comment);
        }
        $this->context->getMessageManager()->addErrorMessage($comment);

        $this->_redirect('checkout/cart');
    }

    /**
     * Process order cancellation
     */
    private function processCancellation(): void
    {
        $comment = __('Customer cancelled transaction');
        if (!$this->checkoutSession->get_expressCheckout()) {
            $this->checkoutSession->set_expressCheckout(false);
            $this->checkoutHelper->cancelCurrentOrderAndRestoreQuote($comment);
        }
        $this->_redirect('checkout/cart');
    }

    /**
     * Process browser "Back" button action
     */
    private function processBack(): void
    {
        $comment = __('Customer clicked browser "back" button and cancelled transaction');
        if (!$this->checkoutSession->get_expressCheckout()) {
            $this->checkoutSession->set_expressCheckout(false);
            $this->checkoutHelper->cancelCurrentOrderAndRestoreQuote($comment);
        }
        $this->_redirect('checkout/cart');
    }

    /**
     * Place Order
     *
     * @param Quote $quote
     * @throws LocalizedException
     */
    private function place(Quote $quote): void
    {
        $order = $this->cartManagement->submit($quote);

        if (!$order) {
            return;
        }

        $this->order = $order;
    }

    /**
     * Find spell order by spell id
     *
     * @param array $data
     * @return array
     * @throws AuthenticationException
     */
    protected function findSpellOrder(array $data): array
    {
        $spellId = $this->checkoutSession->get_spelPaymentId();
        if ($spellId) {
            $spellApi = $this->spellAPIFactory->create();
            $purchase = $spellApi->purchases($spellId);
        } else {
            $purchase = $this->readRequestData($data);
        }

        return $purchase;
    }

    /**
     * Find magento order
     *
     * @param array $data
     * @return OrderInterface|Order
     * @throws InputException
     * @throws NoSuchEntityException
     */
    protected function findMagentoOrder(array $data): OrderInterface
    {
        $order = $this->checkoutSession->getLastRealOrder();
        if (!$order || !$order->getId()) {
            $purchase = $this->readRequestData($data);
            $order = $this->orderFinder->findOrderBySpellId($purchase['id']);
        }

        return $order;
    }

    /**
     * @param array $data
     *
     * @return array
     * @throws AuthenticationException
     */
    protected function readRequestData(array $data): array
    {
        if (!isset($data['id'])) {
            $message = __('Gateway order cannot be found in request');
            $this->logger->critical($message, $data);
            throw new InvalidArgumentException($message);
        }

        $data = $this->validatePurchaseId($data['id']);

        return $data;
    }

    /**
     * @param $purchaseId
     * @return array
     * @throws AuthenticationException
     */
    protected function validatePurchaseId($purchaseId): array
    {
        $spellApi = $this->spellAPIFactory->create();
        $purchase = $spellApi->purchases($purchaseId);

        if (!isset($purchase['id'])) {
            $message = __('Gateway order %1 cannot be found in request', $purchaseId);
            $this->logger->critical($message);
            throw new InvalidArgumentException($message);
        }

        return $purchase;
    }

    /**
     * Load post data
     *
     * @return array
     */
    protected function loadPostData(): array
    {
        return \json_decode($this->getRequest()->getContent(), true);
    }

    /**
     * Check if can invoice order
     *
     * @param Order $order
     * @return bool
     */
    protected function canInvoice(Order $order): bool
    {
        $state = $order->getState();
        if ($state === Order::STATE_CANCELED || $state === Order::STATE_COMPLETE || $state === Order::STATE_CLOSED) {
            return false;
        }

        if ($order->getActionFlag(Order::ACTION_FLAG_INVOICE) === false) {
            return false;
        }

        return true;
    }

    /**
     * Check if invoiced
     *
     * @param Order $order
     * @return bool
     */
    protected function isInvoiced(Order $order): bool
    {
        return $order->getInvoiceCollection()->count() > 0;
    }

    /**
     * Return purchase status text
     *
     * @param array $purchase
     * @return Phrase
     */
    protected function statusText(array $purchase): Phrase
    {
        return __('Order was invoiced due to Webhook HTTP request with status %1', $purchase['status']);
    }

    /**
     * Return data for response from gateway
     *
     * @param array $client
     * @return array
     */
    private function getOrderData(array $client): array
    {
        return [
            'email' => $client['email'] !== '' ?
                $client['email'] :
                'johndoe@gmail.com',
            'shipping_address' => [
                'firstname' => $this->getFirstName($client['full_name']) !== '' ?
                    $this->getFirstName($client['full_name']) :
                    'John',
                'lastname' => $this->getLastName($client['full_name']) !== '' ?
                    $this->getLastName($client['full_name']) :
                    'Doe',
                'email' => $client['email'] !== '' ?
                    $client['email'] :
                    'johndoe@gmail.com',
                'street' => $client['shipping_street_address'] !== '' ?
                    $client['shipping_street_address'] :
                    'xxxxx',
                'city' => $client['shipping_city'] !== '' ?
                    $client['shipping_city'] :
                    'xxxxx',
                'country_id' => $client['shipping_country'] !== '' ?
                    $client['shipping_country'] :
                    'LV',
                'region' => 'Ādažu novads',
                'region_id' => '471',
                'postcode' => $client['shipping_zip_code'] !== '' ?
                    $client['shipping_zip_code'] :
                    '1234',
                'telephone' => $client['phone'] !== '' ?
                    $client['phone'] :
                    '52332'
            ]
        ];
    }

    /**
     * Get firstname from fullname
     *
     * @param string $fullName
     * @return mixed|string
     */
    private function getFirstName(string $fullName)
    {
        if ($fullName === '') {
            return '';
        }
        $name = explode(' ', $fullName);
        return $name[0];
    }

    /**
     * Get lastname from fullname
     *
     * @param string $fullName
     * @return mixed|string
     */
    private function getLastName(string $fullName)
    {
        if ($fullName === '') {
            return '';
        }
        $name = explode(' ', $fullName);
        return $name[1];
    }
}
