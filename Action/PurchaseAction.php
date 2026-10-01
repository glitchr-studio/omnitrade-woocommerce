<?php

namespace Omnitrade\WooCommerce\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Exception\ProviderException;
use Omnitrade\Request\Purchase;
use Omnitrade\Request\Request;
use Omnitrade\WooCommerce\Api;
use Omnitrade\WooCommerce\Orders;

/**
 * An order created unpaid on the shop: the buyer is sent to its payment URL
 * (PENDING), where the shop's own payment gateways take the money; fetch() or
 * the order.updated webhook says when they did. WooCommerce brings nobody
 * back: returnUrl is not used.
 */
final class PurchaseAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Purchase;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Purchase);
        $order = $this->api->call('POST', 'orders', Orders::create($request->payment));
        if (empty($order['id'])) {
            throw new ProviderException('woocommerce', 'WooCommerce created no order.');
        }
        if (empty($order['payment_url'])) {
            throw new ProviderException('woocommerce', sprintf('Order %s was created, but the shop gave no payment URL.', $order['id']));
        }
        $request->setResult(Orders::transaction($order));
    }
}
