---
title: Catalogue
order: 10
---

# WooCommerce's catalogue

The shop's products, variations and stock, read through the
[omnitrade catalogue contract](https://github.com/glitchr-studio/omnitrade/blob/1.x/docs/catalogue.md)
over the REST API v3.

## Installation

```sh
composer require omnitrade/woocommerce symfony/http-client
```

```yaml
omnitrade:
    gateways:
        woo:
            factory: woocommerce
            options:
                url: '%env(WOOCOMMERCE_URL)%'                       # https://shop.example
                consumer_key: '%env(WOOCOMMERCE_KEY)%'               # ck_..., Read is enough for the catalogue
                consumer_secret: '%env(WOOCOMMERCE_SECRET)%'         # cs_...
                webhook_secret: '%env(WOOCOMMERCE_WEBHOOK_SECRET)%'  # for the product webhooks
                currency: EUR
                weight_unit: kg
                product_status: any
```

| Option | Default | |
|---|---|---|
| `currency` | `EUR` | The store's currency (WooCommerce → Settings → General): the REST API gives prices as bare decimals. |
| `weight_unit` | `kg` | The store's weight unit (Settings → Products): `kg`, `g`, `lbs`, `oz`; weights are given in grams. |
| `product_status` | `any` | What `fetchProducts()` lists: `any` (drafts, pending and private ones too), or `publish`. |

## Requests

| Request | What is sent |
|---|---|
| `fetchProducts(?cursor, ?updatedSince, ?query, limit)` | `GET /products?per_page=limit (≤ 100)&page=cursor&status=any&orderby=id&order=asc`; `updatedSince` → `modified_after=2026-10-01T08:00:00&dates_are_gmt=true`, `query` → `search`. The cursor is the page number; the next one is given while `X-WP-TotalPages` says there is one (without the header: while a page comes full). Each variable product's variations are read with it, `GET /products/<id>/variations` (one call per variable product). Pass the same `updatedSince` and `query` with the cursor. |
| `fetchProduct($reference)` | A numeric id → `GET /products/<id>` (a variation's id gives its product); any other id is a slug, and a page's URL gives its slug (the last segment of `/product/<slug>/`), or its id with `?p=<id>` → `GET /products?slug=<slug>&status=any`. `null` when there is none. |
| `fetchInventory($ids)` | The ids through `GET /products?include=…` (a simple product's own stock, a variable product's variations'), the rest - variations - one by one through `GET /products/<id>`; none given, every product and variation of the shop. Unknown ids are left out. |

## What is mapped how

| WooCommerce | omnitrade |
|---|---|
| `id` | `Product::$reference` |
| `name` (HTML entities decoded), `description` (else `short_description`), `slug` | `title`, `description`, `handle` |
| `brands` (WooCommerce 9.6+), else a `Brand`/`Marque` attribute (`pa_brand`, `pa_marque`) | `brand` |
| `categories`, `tags` | `categories`, `tags` (their names) |
| attributes not used for variations | `attributes` (`['Cépage' => ['Pinot blanc', 'Auxerrois'], 'Contenance' => '75 cl']`) |
| attributes used for variations (variable products) | `options` (`Option`) |
| `images` | `media` (images) |
| `permalink` | `url` |
| `status` `publish` / `draft`, `pending`, `private`, `future` / `trash` | `Product::ACTIVE` / `DRAFT` / `ARCHIVED` |
| `date_modified_gmt` | `updatedAt` |
| a simple product | its one variant: reference the product's id, title null |
| a variable product's variations | its variants: reference the variation's id, title its values ("2019"), `options` its attribute values (`['Millésime' => '2019']`, an "any" value left out), `image`, its own `permalink` on the offer |
| `sku`, `global_unique_id` | `sku`, `barcode` |
| `regular_price`, `sale_price` | `Offer`: on sale, the price is `sale_price` and `compareAt` the regular one; else `regular_price` (or `price`). No price: no offer. `available` = published, purchasable and not `outofstock`. |
| `manage_stock`, `stock_quantity` | `Stock`: the quantity when managed, else `null` with `tracked: false`; a variation's `"parent"` counts on its product's |
| `weight` | `weight`, in grams |

## Webhooks

Add webhooks (WooCommerce → Settings → Advanced → Webhooks) for `Product created`,
`Product updated`, `Product deleted` (and `Product restored`) to your endpoint, with the same
secret as the order webhook; `notify()` checks `X-WC-Webhook-Signature`.

| Topic | Notification |
|---|---|
| `product.created`, `product.updated`, `product.restored` | `$product`, mapped as above. The payload of a variable product lists its variations' ids only: they are read along (one call). A variation's payload is read as its whole product (two calls). `$reference` is the product's id. |
| `product.deleted` | `$product` null, `$reference` the product's id. |

## Examples

```php
$page = $woo->fetchProducts(updatedSince: $lastSync);
while (true) {
    foreach ($page->products as $product) {
        // $product->brand, $product->options, $product->variants[0]->price(), ->stock->quantity
    }
    if (!$page->hasMore()) {
        break;
    }
    $page = $woo->fetchProducts($page->next, updatedSince: $lastSync);
}

$product = $woo->fetchProduct('https://shop.example/product/margaux/');
$stocks = $woo->fetchInventory(['501', '602']);
```

With the harness of glitchr/omnitrade (`core/docker`):

```sh
docker compose run --rm omnitrade catalogue woocommerce --query=margaux
docker compose run --rm omnitrade catalogue woocommerce --inventory
```
