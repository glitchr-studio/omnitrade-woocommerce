<?php

namespace Omnitrade\WooCommerce\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Exception\ProviderException;
use Omnitrade\Request\FetchInventory;
use Omnitrade\Request\Request;
use Omnitrade\WooCommerce\Api;
use Omnitrade\WooCommerce\Products;

/**
 * What is left: stock_quantity where manage_stock is on (a null quantity
 * elsewhere), for simple products and variations - the variants. The ids
 * given are read through GET /products?include= (a variable product's id
 * gives its variations), the ones left - variations - one by one through
 * GET /products/<id>; none given, every product and variation of the shop.
 */
final class FetchInventoryAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof FetchInventory;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchInventory);
        $stocks = [];
        if ([] === $request->references) {
            foreach ($this->api->all('products', ['status' => 'any', 'orderby' => 'id', 'order' => 'asc']) as $product) {
                array_push($stocks, ...$this->stocks($product));
            }
            $request->setResult($stocks);

            return;
        }

        $ids = array_values(array_unique(array_map(static fn ($id) => (string) $id, $request->references)));
        $found = [];
        foreach (array_chunk($ids, 100) as $chunk) {
            foreach ($this->api->call('GET', 'products', null, ['include' => implode(',', $chunk), 'per_page' => 100, 'status' => 'any']) as $product) {
                $found[(string) $product['id']] = $product;
            }
        }
        foreach ($ids as $id) {
            if (isset($found[$id])) {
                array_push($stocks, ...$this->stocks($found[$id]));
                continue;
            }
            // Not a product: a variation, which /products/<id> answers too.
            try {
                $item = $this->api->call('GET', 'products/'.rawurlencode($id));
            } catch (ProviderException $e) {
                if (str_contains((string) $e->providerCode, 'invalid_id')) {
                    continue;
                }
                throw $e;
            }
            if ('variation' === ($item['type'] ?? null) && 'parent' === ($item['manage_stock'] ?? null) && !empty($item['parent_id'])) {
                $stocks[] = Products::stock($item, $this->api->call('GET', 'products/'.$item['parent_id']));
            } else {
                $stocks[] = Products::stock($item);
            }
        }

        $request->setResult($stocks);
    }

    /** @return list<\Omnitrade\Model\Stock> a simple product's own, a variable one's variations' */
    private function stocks(array $product): array
    {
        if ('variable' !== ($product['type'] ?? null)) {
            return [Products::stock($product)];
        }

        return array_map(static fn (array $variation) => Products::stock($variation, $product), $this->api->all('products/'.$product['id'].'/variations'));
    }
}
