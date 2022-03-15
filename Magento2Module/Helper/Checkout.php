<?php

namespace SpellPayment\ExpressCheckout\Helper;

use Magento\Sales\Model\Order;
use Magento\Framework\Message\ManagerInterface;
use Magento\Checkout\Model\Session;

/**
 * Checkout workflow helper
 */
class Checkout
{
    /**
     * @var Session
     */
    private $session;

    /**
     * @var ManagerInterface
     */
    private $messageManager;

    /**
     * @param Session $session
     * @param ManagerInterface $messageManager
     */
    public function __construct(
        Session $session,
        ManagerInterface $messageManager
    ) {
        $this->session = $session;
        $this->messageManager = $messageManager;
    }

    /**
     * Cancel last placed order with specified comment message
     *
     * @param string $comment Comment appended to order history
     * @return Order True if order cancelled, false otherwise
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function cancelCurrentOrderAndRestoreQuote($comment)
    {
        $order = $this->session->getLastRealOrder();

        if ($order->getId() && $order->getState() != Order::STATE_CANCELED) {
            $order->registerCancellation($comment)->save();
            $this->restoreQuote();

            return $order;
        }

        $this->messageManager->addErrorMessage(
            __('There is no active quote to restore.')
        );

        return $order;
    }

    /**
     * Restores quote
     *
     * @return bool
     */
    public function restoreQuote(): bool
    {
        return $this->session->restoreQuote();
    }
}
