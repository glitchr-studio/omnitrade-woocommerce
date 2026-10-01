<?php

namespace Omnitrade\WooCommerce;

use Omnitrade\Config;
use Omnitrade\Exception\InvalidConfigException;
use Omnitrade\GatewayFactory;
use Omnitrade\WooCommerce\Action\FetchOrderAction;
use Omnitrade\WooCommerce\Action\FetchTransactionAction;
use Omnitrade\WooCommerce\Action\GetPaymentMethodsAction;
use Omnitrade\WooCommerce\Action\NotifyAction;
use Omnitrade\WooCommerce\Action\PurchaseAction;
use Omnitrade\WooCommerce\Action\RefundAction;
use Symfony\Component\HttpClient\HttpClient;

/**
 * WooCommerce, a commerce platform: a sale paid on the shop's own checkout
 * (an order created unpaid, with its payment URL), the shop's orders and
 * their payment state, refunds, the enabled payment gateways, and webhooks.
 *
 *   options:
 *     url: '%env(WOOCOMMERCE_URL)%'                      # https://shop.example
 *     consumer_key: '%env(WOOCOMMERCE_KEY)%'              # ck_..., a REST API key with read/write
 *     consumer_secret: '%env(WOOCOMMERCE_SECRET)%'        # cs_...
 *     webhook_secret: '%env(WOOCOMMERCE_WEBHOOK_SECRET)%' # the webhook's secret, for notify()
 *     timeout: 15
 */
final class WooCommerceGatewayFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnitrade.factory_name' => 'woocommerce',
            'omnitrade.factory_title' => 'WooCommerce',
            'omnitrade.required_options' => ['url', 'consumer_key', 'consumer_secret'],
            'webhook_secret' => null,
            'timeout' => 15,
            'omnitrade.api' => function (Config $c) {
                $http = $this->http;
                if (!$http) {
                    if (!class_exists(HttpClient::class)) {
                        throw new InvalidConfigException('The "woocommerce" gateway needs symfony/http-client.');
                    }
                    $http = HttpClient::create();
                }

                return new Api($http, (string) $c['url'], (string) $c['consumer_key'], (string) $c['consumer_secret'], $c['webhook_secret'] ?: null, (int) $c['timeout']);
            },
            'omnitrade.action.purchase' => new PurchaseAction(),
            'omnitrade.action.fetch' => new FetchTransactionAction(),
            'omnitrade.action.order' => new FetchOrderAction(),
            'omnitrade.action.refund' => new RefundAction(),
            'omnitrade.action.notify' => new NotifyAction(),
            'omnitrade.action.methods' => new GetPaymentMethodsAction(),
        ]);
    }
}
