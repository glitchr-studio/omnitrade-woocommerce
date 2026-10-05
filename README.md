# omnitrade/woocommerce

WooCommerce for [glitchr/omnitrade](https://github.com/glitchr-studio/omnitrade): a sale paid on
the shop's own checkout (an order created unpaid, with its payment URL), the shop's orders and
their payment state, refunds, the payment gateways switched on, the catalogue, and webhooks - the
REST API v3.

```php
$gateway = (new WooCommerceGatewayFactory($http))->create(['url' => 'https://shop.example/', 'consumer_key' => '...', 'consumer_secret' => '...']);   // $http: the application's HTTP client; none given, the factory makes its own
```

No framework needed: the package requires `glitchr/omnitrade` and `symfony/http-client`. In a
Symfony application, the same through the bundle's configuration:

```yaml
omnitrade:
    gateways:
        woo:
            factory: woocommerce
            options:
                url: '%env(WOOCOMMERCE_URL)%'                       # https://shop.example
                consumer_key: '%env(WOOCOMMERCE_KEY)%'               # ck_...
                consumer_secret: '%env(WOOCOMMERCE_SECRET)%'         # cs_...
                webhook_secret: '%env(WOOCOMMERCE_WEBHOOK_SECRET)%'  # for notify()
                currency: EUR                                        # the store's, for the catalogue
```

`purchase()` creates a pending order and answers PENDING with its payment URL; the shop's own
gateways take the money there, and `fetch()` or the `order.updated` webhook say when they did
(processing/completed is PAID, failed REFUSED, cancelled CANCELLED). `refund()` refunds through
the shop's gateway; `fetchOrder()` reads any order; `paymentMethods()` lists the gateways enabled.

## Catalogue

`fetchProducts()` pages through `/products` (`modified_after`, `search`), a variable product's
variations as its variants; `fetchProduct()` finds one by id, slug or page URL;
`fetchInventory()` reads `stock_quantity` of products and variations; `notify()` reads
`product.created|updated|deleted` into a `Notification` carrying the `Product`. Brands (9.6+, or
a Brand attribute), categories, tags, the attributes (the variations' ones as options), images,
regular and sale prices are mapped: see [docs/catalogue.md](docs/catalogue.md).

Credentials: a REST API key with Read/Write (WooCommerce → Settings → Advanced → REST API) over
HTTPS, and a webhook (same screen → Webhooks) for `order.updated` with a secret of your own.

License: LGPL-3.0-or-later.
