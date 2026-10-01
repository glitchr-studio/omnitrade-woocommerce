<?php

namespace Omnitrade\WooCommerce\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Model\PaymentMethod;
use Omnitrade\Request\GetPaymentMethods;
use Omnitrade\Request\Request;
use Omnitrade\WooCommerce\Api;

/** The shop's payment gateways switched on (GET payment_gateways). */
final class GetPaymentMethodsAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof GetPaymentMethods;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof GetPaymentMethods);
        $methods = [];
        foreach ($this->api->call('GET', 'payment_gateways') as $gateway) {
            if (!\is_array($gateway) || empty($gateway['enabled'])) {
                continue;
            }
            $id = (string) ($gateway['id'] ?? '');
            $methods[] = new PaymentMethod($id, (string) ($gateway['title'] ?? $id), match (true) {
                str_contains($id, 'stripe'), str_contains($id, 'card') => PaymentMethod::CARD,
                str_contains($id, 'paypal'), str_contains($id, 'apple'), str_contains($id, 'google') => PaymentMethod::WALLET,
                str_contains($id, 'bacs'), str_contains($id, 'sepa'), str_contains($id, 'bank') => PaymentMethod::BANK,
                str_contains($id, 'klarna'), str_contains($id, 'afterpay') => PaymentMethod::BUY_NOW_PAY_LATER,
                default => PaymentMethod::OTHER,
            }, raw: $gateway);
        }
        $request->setResult($methods);
    }
}
