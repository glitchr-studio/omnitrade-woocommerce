<?php

namespace Omnitrade\WooCommerce\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Exception\ProviderException;
use Omnitrade\Model\Reference;
use Omnitrade\Request\FetchProduct;
use Omnitrade\Request\Request;
use Omnitrade\WooCommerce\Api;
use Omnitrade\WooCommerce\Products;

/**
 * One product: by its id (GET /products/<id>), its slug, or the address of
 * its page - the slug is the last segment of /product/<slug>/, an address
 * with ?p=<id> names the id (GET /products?slug=<slug>). A variation's id
 * answers its product. Null when the shop has no such product.
 */
final class FetchProductAction implements ActionInterface, ApiAwareInterface
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
        return $request instanceof FetchProduct;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchProduct);
        [$id, $slug] = self::target($request->reference);
        $product = null;
        if (null !== $id) {
            $product = $this->byId($id);
            if (null !== $product && 'variation' === ($product['type'] ?? null) && !empty($product['parent_id'])) {
                $product = $this->byId((string) $product['parent_id']);
            }
        } elseif (null !== $slug) {
            $product = $this->api->call('GET', 'products', null, ['slug' => $slug, 'status' => 'any'])[0] ?? null;
        }
        if (!\is_array($product) || !isset($product['id'])) {
            $request->setResult(null);

            return;
        }
        $variations = 'variable' === ($product['type'] ?? null) ? $this->api->all('products/'.$product['id'].'/variations') : [];

        $request->setResult(Products::product($product, $variations, $this->currency, $this->weightUnit));
    }

    /** @return array{0: ?string, 1: ?string} the id, or the slug */
    public static function target(Reference $reference): array
    {
        if (!$reference->isUrl()) {
            $id = trim((string) $reference->id);

            return ctype_digit($id) ? [$id, null] : [null, '' === $id ? null : $id];
        }
        parse_str((string) parse_url((string) $reference->url, \PHP_URL_QUERY), $query);
        foreach (['p', 'product_id', 'post'] as $key) {
            if (isset($query[$key]) && \is_string($query[$key]) && ctype_digit($query[$key])) {
                return [$query[$key], null];
            }
        }
        if (isset($query['product']) && \is_string($query['product']) && '' !== $query['product']) {
            return [null, $query['product']]; // ?post_type=product&product=<slug>, without pretty permalinks
        }
        $slug = $reference->slug();

        return [null, null === $slug || '' === $slug ? null : $slug];
    }

    private function byId(string $id): ?array
    {
        try {
            return $this->api->call('GET', 'products/'.rawurlencode($id));
        } catch (ProviderException $e) {
            if (str_contains((string) $e->providerCode, 'invalid_id')) {
                return null;
            }
            throw $e;
        }
    }
}
