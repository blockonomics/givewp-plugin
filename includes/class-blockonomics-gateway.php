<?php

use Give\Donations\Models\Donation;
use Give\Framework\PaymentGateways\Commands\PaymentRefunded;
use Give\Framework\PaymentGateways\Commands\RedirectOffsite;
use Give\Framework\PaymentGateways\Exceptions\PaymentGatewayException;
use Give\Framework\PaymentGateways\PaymentGateway;

/**
 * @inheritDoc
 */
class GiveWpBlockonomicsPaymentGateway extends PaymentGateway
{
    /**
     * @inheritDoc
     */
    public static function id(): string
    {
        return 'blockonomics-gateway';
    }

    /**
     * @inheritDoc
     */
    public function getId(): string
    {
        return self::id();
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return __('Blockonomics - Crypto', 'blockonomics-give');
    }

    /**
     * @inheritDoc
     */
    public function getPaymentMethodLabel(): string
    {
        return __('Crypto', 'blockonomics-give');
    }

    /**
     * @inheritDoc
     */
    public function getLegacyFormFieldMarkup(int $formId, array $args): string
    {
        // For an offsite gateway, this is just help text that displays on the form. 
        return "<div class='example-offsite-help-text'>
                    <p>You will be taken to the Bitcoin donation page to complete the donation!</p>
                </div>";
    }

    /**
     * @inheritDoc
     */
    public function createPayment(Donation $donation, $gatewayData): RedirectOffsite
    {
        include_once 'blockonomics.php';
        $blockonomics = new GiveWpBlockonomics;
        
        $order_url = $blockonomics->get_order_checkout_url($donation->id);
        $gatewayUrl = str_replace( ['http:', 'https:'], '', $order_url );
        return new RedirectOffsite($gatewayUrl);
    }


    /**
     * @inerhitDoc
     */
    public function refundDonation(Donation $donation): PaymentRefunded
    {
        $errorMessage = "Cannot automatically refund crypto. Contact site owner for manual refund";
        throw new PaymentGatewayException($errorMessage);
    }
}
