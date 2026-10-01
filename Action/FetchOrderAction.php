<?php

namespace Omnitrade\WooCommerce\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Request\FetchOrder;
use Omnitrade\Request\Request;
use Omnitrade\WooCommerce\Api;
use Omnitrade\WooCommerce\Orders;

/** A shop order, whole. */
final class FetchOrderAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof FetchOrder;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchOrder);
        $request->setResult(Orders::platformOrder($this->api->call('GET', 'orders/'.rawurlencode($request->reference))));
    }
}
