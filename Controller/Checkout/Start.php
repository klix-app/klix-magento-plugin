<?php

namespace SpellPayment\ExpressCheckout\Controller\Checkout;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteRepository;
use Magento\Store\Model\StoreManagerInterface;
use SpellPayment\ExpressCheckout\Model\ExpressCheckoutService;

class Start extends AbstractCheckout
{
    /**
     * List of request params that handled by the controller.
     *
     * @var array
     */
    private static $knownRequestParams = [
        'form_key',
        'product'
    ];

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var ExpressCheckoutService
     */
    protected $expressCheckoutService;

    /**
     * @var Json
     */
    private $json;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;
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
     * @param StoreManagerInterface $storeManager
     * @param ExpressCheckoutService $expressCheckoutService
     * @param ProductRepositoryInterface $productRepository
     */
    public function __construct(
        Context                    $context,
        Session                    $checkoutSession,
        Quote                      $quote,
        Json                       $json,
        QuoteRepository            $quoteRepository,
        StoreManagerInterface      $storeManager,
        ExpressCheckoutService     $expressCheckoutService,
        ProductRepositoryInterface $productRepository
    ) {
        parent::__construct(
            $context,
            $checkoutSession,
            $quote,
            $quoteRepository,
            $expressCheckoutService
        );
        $this->storeManager = $storeManager;
        $this->expressCheckoutService = $expressCheckoutService;
        $this->productRepository = $productRepository;
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

        $productId = (int)$this->getRequest()->getParam('product');
        $productRequest = $this->getRequestUnknownParams($this->getRequest());

        $store = $this->storeManager->getStore();
        $product = $this->productRepository->getById(
            $productId,
            false,
            $store->getId(),
            false
        );

        $this->expressCheckoutService->start(
            $product,
            $productRequest
        );

        $product = [
            [
                'product_id' => $productId,
                'name' => $product->getName(),
                'price' => round($product->getPrice() * 100),
                'quantity' => 1
            ]
        ];

        $result = $this->expressCheckoutService->directPaymentParams($product);

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

        $this->getResponse()->representJson(
            $this->json->serialize(
                ['returnUrl' => $this->_redirect->getRefererUrl()]
            )
        );
        return $this->getResponse();
    }

    /**
     * Filters out parameters that handled by controller.
     *
     * @param RequestInterface $request
     * @return array
     */
    private function getRequestUnknownParams(RequestInterface $request): array
    {
        $requestParams = $request->getParams();
        $unknownParams = [];
        foreach ($requestParams as $param => $value) {
            if (!isset(self::$knownRequestParams[$param])) {
                $unknownParams[$param] = $value;
            }
        }
        return $unknownParams;
    }
}
