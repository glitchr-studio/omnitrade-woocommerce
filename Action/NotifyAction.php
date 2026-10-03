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
use Omnitrade\WooCommerce\Products;

/**
 * A WooCommerce webhook, its signature checked: an order topic
 * (order.created, order.updated, order.deleted) carries the order, read as
 * what its status means; any other topic is handed back with no status.
 *
 * A product topic carries the catalogue: product.created, product.updated
 * and product.restored the Product (a variable product's variations read
 * along, one call; a variation's payload read as its whole product),
 * product.deleted the product's id alone.
 */
final class NotifyAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct(
        private readonly string $currency = 'EUR',
        private readonly string $weightUnit = 'kg',
    ) {
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
        $reference = $isOrder ? (string) $payload['id'] : null;
        $product = null;
        if (str_starts_with($topic, 'product.') && isset($payload['id'])) {
            $reference = (string) $payload['id'];
            if ('product.deleted' !== $topic) {
                $item = $payload;
                if ('variation' === ($item['type'] ?? null) && !empty($item['parent_id'])) {
                    $item = $this->api->call('GET', 'products/'.$item['parent_id']);
                    $reference = (string) $item['id'];
                }
                $variations = 'variable' === ($item['type'] ?? null) ? $this->api->all('products/'.$item['id'].'/variations') : [];
                $product = Products::product($item, $variations, $this->currency, $this->weightUnit);
            }
        }

        $request->setResult(new Notification(
            provider: 'woocommerce',
            event: $topic,
            reference: $reference,
            status: $isOrder && 'order.deleted' !== $topic ? Orders::status($payload) : null,
            transaction: $isOrder && 'order.deleted' !== $topic ? Orders::transaction($payload) : null,
            id: $request->header('X-WC-Webhook-Delivery-ID'),
            raw: ['topic' => $topic, 'resource' => $request->header('X-WC-Webhook-Resource'), 'event' => $request->header('X-WC-Webhook-Event'), 'payload' => $payload],
            product: $product,
        ));
    }
}
