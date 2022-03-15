<?php

namespace SpellPayment\ExpressCheckout\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;

class SalesEventQuoteSubmitBeforeObserver implements ObserverInterface
{
    /**
     * Observer for assigning transaction id to order payment
     *
     * @param Observer $observer
     * @return $this|void
     */
    public function execute(Observer $observer)
    {
        /**
         * @var $quote Quote
         */
        $quote = $observer->getEvent()->getQuote();
        $order = $observer->getEvent()->getOrder();

        if ($order->getPayment()->getMethod() === 'spellpayment_checkout') {
            $transactionId = $quote->getPayment()->getTransactionId();
            $order->getPayment()->setIsTransactionPending(true);
            $order->getPayment()->setIsTransactionClosed(false);
            $order->getPayment()->setTransactionId($transactionId);
            $order->getPayment()->setParentId($transactionId);
        }

        return $this;
    }
}
