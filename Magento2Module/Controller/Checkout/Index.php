<?php
namespace SpellPayment\ExpressCheckout\Controller\Checkout;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NotFoundException;

class Index extends \Magento\Framework\App\Action\Action
{
    /**
     * @var \Magento\Checkout\Model\Session
     */
    private $_checkoutSession;

    /**
     * @var \Magento\Sales\Model\OrderFactory
     */
    private $_orderFactory;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Sales\Model\OrderFactory $orderFactory
    ) {
        parent::__construct($context);
        $this->_checkoutSession = $checkoutSession;
        $this->_orderFactory = $orderFactory;
    }

    /**
     * Get checkout session
     *
     * @return \Magento\Checkout\Model\Session
     */
    protected function getCheckoutSession()
    {
        return $this->_checkoutSession;
    }

    /**
     * Get order factory
     *
     * @return \Magento\Sales\Model\OrderFactory
     */
    protected function getOrderFactory()
    {
        return $this->_orderFactory;
    }

    /**
     * Get an Instance of the current Checkout Order Object
     *
     * @return \Magento\Sales\Model\Order
     */
    protected function getOrder(): ?\Magento\Sales\Model\Order
    {
        $orderId = $this->getCheckoutSession()->getLastRealOrderId();

        if (!isset($orderId)) {
            return null;
        }

        $order = $this->getOrderFactory()->create()->loadByIncrementId(
            $orderId
        );

        if (!$order->getId()) {
            return null;
        }

        return $order;
    }

    /**
     * Go to checkout payment page
     */
    protected function redirectToCheckoutFragmentPayment()
    {
        $this->_redirect('checkout', ['_fragment' => 'payment']);
    }

    /**
     * Index controller
     *
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\ResultInterface|void
     * @throws LocalizedException
     * @throws NotFoundException
     */
    public function execute()
    {
        $order = $this->getOrder();
        if (!$order) {
            throw new NotFoundException(__('No active order in session.'));
        }
        $redirectUrl = $this->getCheckoutSession()->get_spellPaymentCheckoutRedirectUrl();
        if (!$redirectUrl) {
            throw new LocalizedException(__('Failed to pass the payment gateway url.'));
        }
        $this->getResponse()->setRedirect($redirectUrl);
    }
}
