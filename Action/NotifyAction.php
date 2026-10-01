<?php

namespace Omnitrade\WooCommerce\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Exception\ProviderException;
use Omnitrade\Model\Notification;
use Omnitrade\Request\Notify;
use Omnitrade\Request\Request;
use Omnitrade\WooCommerce\Api;
use Omnitrade\WooCommerce\Orders;

/**
 * A WooCommerce webhook, its signature checked: an order topic
 * (order.created, order.updated, order.deleted) carries the order, read as
 * what its status means; any other topic is handed back with no status.
 */
final class NotifyAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Notify;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Notify);
        $this->api->verify($request->body, $request->header('X-WC-Webhook-Signature'));
        $payload = json_decode($request->body, true);
        if (!\is_array($payload)) {
            throw new ProviderException('woocommerce', 'The notification is not JSON.');
        }
        $topic = (string) $request->header('X-WC-Webhook-Topic');
        $isOrder = str_starts_with($topic, 'order.') && isset($payload['id']);

        $request->setResult(new Notification(
            provider: 'woocommerce',
            event: $topic,
            reference: $isOrder ? (string) $payload['id'] : null,
            status: $isOrder && 'order.deleted' !== $topic ? Orders::status($payload) : null,
            transaction: $isOrder && 'order.deleted' !== $topic ? Orders::transaction($payload) : null,
            id: $request->header('X-WC-Webhook-Delivery-ID'),
            raw: ['topic' => $topic, 'resource' => $request->header('X-WC-Webhook-Resource'), 'event' => $request->header('X-WC-Webhook-Event'), 'payload' => $payload],
        ));
    }
}
