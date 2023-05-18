<?php
namespace SpellPayment\ExpressCheckout\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use SpellPayment\ExpressCheckout\Block\CheckoutButton;

class ExpressConfigProvider implements ConfigProviderInterface
{
    /**
     * @var CheckoutButton
     */
    private $button;

    /**
     * Constructor for expressConfigProvider
     *
     * @param CheckoutButton $button
     */
    public function __construct(CheckoutButton $button)
    {
        $this->button = $button;
    }

    /**
     * Get imageUrl for express checkout button
     *
     * @return array
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getConfig()
    {
        $config = [];
        $config['imageUrl'] = $this->button->getButtonImageUrl();
        $config['expressCheckoutEnabled'] = $this->button->isExpressCheckoutEnabled();

        return $config;
    }
}
