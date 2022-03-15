<?php

namespace SpellPayment\ExpressCheckout\Controller\Checkout;

use SpellPayment\ExpressCheckout\Model\ExpressCheckoutService;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteRepository;

class StartCart extends AbstractCheckout
{

    /**
     * @var ExpressCheckoutService
     */
    protected $expressCheckoutService;

    /**
     * @var Json
     */
    private $json;

    /**
     * @var Session
     */
    private $checkoutSession;

    /**
     * Start constructor.
     * @param Context $context
     * @param Session $checkoutSession
     * @param Quote $quote
     * @param Json $json
     * @param QuoteRepository $quoteRepository
     * @param ExpressCheckoutService $expressCheckoutService
     */
    public function __construct(
        Context $context,
        Session $checkoutSession,
        Quote $quote,
        Json $json,
        QuoteRepository $quoteRepository,
        ExpressCheckoutService $expressCheckoutService
    ) {
        parent::__construct(
            $context,
            $checkoutSession,
            $quote,
            $quoteRepository,
            $expressCheckoutService
        );
        $this->expressCheckoutService = $expressCheckoutService;
        $this->json = $json;
        $this->checkoutSession = $checkoutSession;
    }

    /**
     * Controller for starting express checkout process
     *
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\ResultInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function execute()
    {
        if (!$this->getRequest()->isAjax()) {
            $this->getResponse()->representJson(
                $this->json->serialize(
                    ['returnUrl' => $this->_redirect->getRefererUrl()]
                )
            );
            return $this->getResponse();
        }

        $this->expressCheckoutService->startCart();

        $result = $this->expressCheckoutService->directPaymentParams();

        if ($result['status'] !== 'failure' && $result['data']['checkout_url']) {
            $this->checkoutSession->set_expressCheckout(true);
            $this->checkoutSession->set_spellPaymentCheckoutRedirectUrl($result['data']['checkout_url']);
            $this->checkoutSession->set_spelPaymentId($result['data']['id']);
            $this->getResponse()->representJson(
                $this->json->serialize(
                    ['returnUrl' => $result['data']['checkout_url']]
                )
            );
            return $this->getResponse();
        }

        $this->getResponse()->representJson($this->json->serialize(
            ['returnUrl' => $this->_redirect->getRefererUrl()]
        ));
        return $this->getResponse();
    }
}
