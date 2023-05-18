<?php

namespace SpellPayment\ExpressCheckout\Plugin;

use Magento\Checkout\Block\Cart\Sidebar;
use SpellPayment\ExpressCheckout\Block\CheckoutButton;

class ExpressImage
{
    /**
     * @var CheckoutButton
     */
    private $checkoutButton;

    /**
     * @param CheckoutButton $checkoutButton
     */
    public function __construct(CheckoutButton $checkoutButton)
    {
        $this->checkoutButton = $checkoutButton;
    }

    /**
     * Get imageUrl config for minicart
     *
     * @param Sidebar $subject
     * @param array $result
     * @return array
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function afterGetConfig(Sidebar $subject, $result): array
    {
        $result['imageUrl'] = $this->checkoutButton->getButtonImageUrl();
        $result['expressCheckoutEnabled'] = $this->checkoutButton->isExpressCheckoutEnabled();

        return $result;
    }
}
