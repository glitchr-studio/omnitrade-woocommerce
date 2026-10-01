# omnitrade/woocommerce

WooCommerce for [glitchr/omnitrade](https://github.com/glitchr-studio/omnitrade): a sale paid on
the shop's own checkout (an order created unpaid, with its payment URL), the shop's orders and
their payment state, refunds, the payment gateways switched on, and webhooks - the REST API v3.

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
```

`purchase()` creates a pending order and answers PENDING with its payment URL; the shop's own
gateways take the money there, and `fetch()` or the `order.updated` webhook say when they did
(processing/completed is PAID, failed REFUSED, cancelled CANCELLED). `refund()` refunds through
the shop's gateway; `fetchOrder()` reads any order; `paymentMethods()` lists the gateways enabled.

Credentials: a REST API key with Read/Write (WooCommerce → Settings → Advanced → REST API) over
HTTPS, and a webhook (same screen → Webhooks) for `order.updated` with a secret of your own.

License: LGPL-3.0-or-later.
