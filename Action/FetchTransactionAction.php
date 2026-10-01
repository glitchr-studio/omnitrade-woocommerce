<?php

namespace Omnitrade\WooCommerce\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Request\FetchTransaction;
use Omnitrade\Request\Request;
use Omnitrade\WooCommerce\Api;
use Omnitrade\WooCommerce\Orders;

/** The order's payment state as the shop has it now. */
final class FetchTransactionAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof FetchTransaction;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchTransaction);
        $request->setResult(Orders::transaction($this->api->call('GET', 'orders/'.rawurlencode($request->reference))));
    }
}
