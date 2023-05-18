<?php

declare(strict_types=1);

namespace SpellPayment\ExpressCheckout\Plugin;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Sales\Model\Service\CreditmemoService;
use Psr\Log\LoggerInterface;
use SpellPayment\ExpressCheckout\Helper\SpellAPIFactory;
use SpellPayment\ExpressCheckout\Model\Method\Checkout;

/**
 * Process online refunds
 */
class Refund
{
    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var SpellAPIFactory
     */
    private $spellAPIFactory;

    /**
     * @var TransactionRepositoryInterface
     */
    private $transactionRepositoryInterface;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * Refund constructor.
     *
     * @param LoggerInterface $logger
     * @param ScopeConfigInterface $scopeConfig
     * @param SpellAPIFactory $spellAPIFactory
     * @param TransactionRepositoryInterface $transactionRepositoryInterface
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        SpellAPIFactory $spellAPIFactory,
        TransactionRepositoryInterface $transactionRepositoryInterface,
        SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->spellAPIFactory = $spellAPIFactory;
        $this->transactionRepositoryInterface = $transactionRepositoryInterface;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
    }

    /**
     * Around refund plugin
     *
     * @param CreditmemoService $subject
     * @param \Closure $proceed
     * @param CreditmemoInterface $creditmemo
     * @param $offlineRequested
     * @return CreditmemoInterface
     * @throws \Exception
     * @throws \RuntimeException
     */
    public function aroundRefund(
        CreditmemoService $subject,
        \Closure $proceed,
        CreditmemoInterface $creditmemo,
        $offlineRequested
    ) {
        /** @var CreditmemoInterface $result */
        $result = $proceed($creditmemo, $offlineRequested);
        $order = $result->getOrder();
        $payment = $order->getPayment();
        $methodCode = $payment->getMethod();

        if (!$offlineRequested && $methodCode == Checkout::CODE) {
            $transactionId = $this->getTransactionId($payment);
            $amount = $creditmemo->getGrandTotal();

            $params = [
                'amount' => round($amount * 100),
            ];

            $spellApi = $this->spellAPIFactory->create();
            $refundResponse = $spellApi->refundPayment($transactionId, $params);
            $spellApi->logInfo(sprintf(
                "Refund response: %s",
                var_export($refundResponse, true)
            ));
        }

        return $result;
    }

    /**
     * Get the transaction id. Magento 2.0, 2.1, 2.2 truncates the transaction id stored in sales_order_payment table.
     *
     * @param \Magento\Sales\Api\Data\OrderPaymentInterface $payment
     * @return string
     * @throws \RuntimeException
     */
    private function getTransactionId($payment)
    {
        $this->searchCriteriaBuilder->addFilter('payment_id', $payment->getEntityId());
        $this->searchCriteriaBuilder->addFilter('method', Checkout::CODE);
        $searchCriteria = $this->searchCriteriaBuilder->create();
        $transactionItems = $this->transactionRepositoryInterface->getList($searchCriteria)->getItems();

        if (!is_array($transactionItems) || count($transactionItems) < 1) {
            throw new \RuntimeException('Could not retrieve the full Klix transaction ID during order refund.');
        }
        return array_values(array_reverse($transactionItems))[0]->getTxnId();
    }
}
