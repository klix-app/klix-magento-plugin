<?php
namespace SpellPayment\ExpressCheckout\Controller\Checkout;

class SetFormData extends \Magento\Framework\App\Action\Action
{
    /**
     * @var \Magento\Checkout\Model\Session
     */
    private $_checkoutSession;

    /**
     * SetFormData constructor
     *
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Checkout\Model\Session $checkoutSession
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Checkout\Model\Session $checkoutSession
    ) {
        parent::__construct($context);
        $this->_checkoutSession = $checkoutSession;
    }

    /**
     * SetFormData controller
     *
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\ResultInterface|void
     */
    public function execute()
    {
        $formDataJson = $this->getRequest()->getParam('json');
        $this->_checkoutSession
            ->set_spellFormDataJson($formDataJson);
        $this->getResponse()->setBody(json_encode([
            'status' => 'success',
        ]));
    }
}
