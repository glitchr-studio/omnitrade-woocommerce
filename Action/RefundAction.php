<?php

namespace Omnitrade\WooCommerce\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Exception\ProviderException;
use Omnitrade\Model\Money;
use Omnitrade\Model\Refund as RefundModel;
use Omnitrade\Model\Status;
use Omnitrade\Request\Refund;
use Omnitrade\Request\Request;
use Omnitrade\WooCommerce\Api;

/** A refund on an order, through the shop's payment gateway when it can (api_refund). */
final class RefundAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Refund;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Refund);
        $order = $this->api->call('GET', 'orders/'.rawurlencode($request->reference));
        $currency = strtoupper((string) ($order['currency'] ?? $request->amount?->currency ?? 'EUR'));
        $amount = $request->amount ?? Money::fromDecimal((string) ($order['total'] ?? '0'), $currency);
        $data = $this->api->call('POST', 'orders/'.rawurlencode($request->reference).'/refunds', array_filter([
            'amount' => $amount->decimal(),
            'reason' => $request->reason,
            'api_refund' => true,
        ], static fn ($v) => null !== $v));
        if (empty($data['id'])) {
            throw new ProviderException('woocommerce', 'WooCommerce recorded no refund.');
        }

        $request->setResult(new RefundModel(
            provider: 'woocommerce',
            reference: (string) $data['id'],
            amount: isset($data['amount']) ? Money::fromDecimal((string) $data['amount'], $currency) : $amount,
            status: Status::REFUNDED,
            transactionReference: $request->reference,
            message: $data['reason'] ?? null,
            raw: $data,
        ));
    }
}
