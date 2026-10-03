<?php

namespace Omnitrade\WooCommerce\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Model\ProductPage;
use Omnitrade\Request\FetchProducts;
use Omnitrade\Request\Request;
use Omnitrade\WooCommerce\Api;
use Omnitrade\WooCommerce\Products;

/**
 * A page of the shop's products, GET /products by id: the cursor is the
 * page's number (X-WP-TotalPages says whether there is another),
 * updatedSince is modified_after (in GMT), the query WooCommerce's search.
 * A variable product's variations are read with it (one call each). Pass
 * the same updatedSince and query with the cursor.
 */
final class FetchProductsAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct(
        private readonly string $currency = 'EUR',
        private readonly string $weightUnit = 'kg',
        /** "any", or "publish" for the published ones only */
        private readonly string $status = 'any',
    ) {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof FetchProducts;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchProducts);
        $page = max(1, (int) $request->cursor);
        $perPage = max(1, min(100, $request->limit));
        $query = ['per_page' => $perPage, 'page' => $page, 'status' => $this->status, 'orderby' => 'id', 'order' => 'asc'];
        if (null !== $request->updatedSince) {
            $query['modified_after'] = \DateTimeImmutable::createFromInterface($request->updatedSince)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s');
            $query['dates_are_gmt'] = 'true';
        }
        if (null !== $request->query && '' !== trim($request->query)) {
            $query['search'] = trim($request->query);
        }
        [$items, $pages] = $this->api->page('products', $query);

        $products = [];
        foreach ($items as $item) {
            $variations = 'variable' === ($item['type'] ?? null) ? $this->api->all('products/'.$item['id'].'/variations') : [];
            $products[] = Products::product($item, $variations, $this->currency, $this->weightUnit);
        }
        $more = null !== $pages ? $page < $pages : \count($items) === $perPage;

        $request->setResult(new ProductPage($products, $more ? (string) ($page + 1) : null));
    }
}
