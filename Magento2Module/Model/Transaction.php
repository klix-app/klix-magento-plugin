<?php

declare(strict_types=1);

namespace SpellPayment\ExpressCheckout\Model;

use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;

/**
 * Do order invoicing and saving
 */
class Transaction
{
    /**
     * @var OrderSender
     */
    private $sender;

    /**
     * @var TransactionFactory
     */
    private $transactionFactory;

    /**
     * @var TransactionRepositoryInterface
     */
    private $transactionRepository;

    /**
     * @param OrderSender                    $sender
     * @param TransactionFactory             $transactionFactory
     * @param TransactionRepositoryInterface $transactionRepository
     */
    public function __construct(
        OrderSender $sender,
        TransactionFactory $transactionFactory,
        TransactionRepositoryInterface $transactionRepository
    ) {
        $this->sender = $sender;
        $this->transactionFactory = $transactionFactory;
        $this->transactionRepository = $transactionRepository;
    }

    /**
     * Invoice order
     *
     * @param OrderInterface|Order $order
     * @throws LocalizedException
     */
    public function invoice(OrderInterface $order)
    {
        $invoice = $order->prepareInvoice();
        if (!$invoice) {
            throw new LocalizedException(__('An invoice could not be created.'));
        }

        $invoice->register()->capture();
        $order = $invoice->getOrder();

        $payment = $order->getPayment();
        $transaction = $this->getOrderTransaction($payment);
        $transaction->setIsPending(false)->setIsClosed(true)->save();

        $dbTransaction = $this->transactionFactory->create();
        $dbTransaction
            ->addObject($payment)
            ->addObject($transaction)
            ->addObject($invoice)
            ->addObject($order)
            ->save();
    }

    /**
     * Send email
     *
     * @param OrderInterface|Order $order
     */
    public function sendEmail(OrderInterface $order)
    {
        $order->setCanSendNewEmailFlag(true);
        $this->sender->send($order, true);
    }

    /**
     * Get transaction with type order
     *
     * @param OrderPaymentInterface $payment
     * @return false|TransactionInterface
     * @throws \Magento\Framework\Exception\InputException
     */
    protected function getOrderTransaction($payment)
    {
        return $this->transactionRepository->getByTransactionType(
            \Magento\Sales\Model\Order\Payment\Transaction::TYPE_ORDER,
            $payment->getId(),
            $payment->getOrder()->getId()
        );
    }
}
