<?php

namespace Omnitrade\WooCommerce\Tests;

use Omnitrade\Exception\InvalidNotificationException;
use Omnitrade\Model\Money;
use Omnitrade\Model\Status;
use Omnitrade\Request\Authorize;
use Omnitrade\Tests\Fixtures;
use Omnitrade\WooCommerce\Api;
use Omnitrade\WooCommerce\WooCommerceGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WooCommerceGatewayTest extends TestCase
{
    private const SECRET = 'wc-secret';

    /** @var list<array{string, string, array}> */
    private array $calls = [];

    private function gateway(): \Omnitrade\GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertStringStartsWith('https://shop.example/wp-json/wc/v3/', $url);
            self::assertContains('Authorization: Basic '.base64_encode('ck_x:cs_x'), $options['headers'], 'the key and secret as basic auth');
            $path = substr((string) parse_url($url, \PHP_URL_PATH), \strlen('/wp-json/wc/v3/'));
            $sent = \is_string($options['body'] ?? null) && '' !== $options['body'] ? json_decode($options['body'], true) : [];
            $this->calls[] = [$method, $path, \is_array($sent) ? $sent : []];

            return match (true) {
                'POST' === $method && 'orders' === $path => new MockResponse(json_encode(self::order('pending') + ['payment_url' => 'https://shop.example/checkout/order-pay/1042/?pay_for_order=true&key=wc_order_abc'])),
                'GET' === $method && 'orders/1042' === $path => new MockResponse(json_encode(self::order('processing'))),
                'GET' === $method && 'orders/1043' === $path => new MockResponse(json_encode(self::order('failed'))),
                'POST' === $method && 'orders/1042/refunds' === $path => new MockResponse(json_encode(['id' => 77, 'amount' => '5.00', 'reason' => $sent['reason'] ?? ''])),
                'GET' === $method && 'payment_gateways' === $path => new MockResponse(json_encode([['id' => 'stripe', 'title' => 'Credit card', 'enabled' => true], ['id' => 'bacs', 'title' => 'Bank transfer', 'enabled' => true], ['id' => 'cheque', 'title' => 'Cheque', 'enabled' => false]])),
                default => new MockResponse(json_encode(['code' => 'woocommerce_rest_shop_order_invalid_id', 'message' => 'Invalid ID.']), ['http_code' => 404]),
            };
        });

        return (new WooCommerceGatewayFactory($http))->create(['url' => 'https://shop.example/', 'consumer_key' => 'ck_x', 'consumer_secret' => 'cs_x', 'webhook_secret' => self::SECRET]);
    }

    public function testAPurchaseIsAnOrderPaidOnTheShopsCheckout(): void
    {
        $gateway = $this->gateway();
        $transaction = $gateway->purchase(Fixtures::payment());

        self::assertTrue($transaction->isRedirect());
        self::assertSame('1042', $transaction->reference);
        self::assertStringContainsString('order-pay/1042', $transaction->redirectUrl);
        [, , $sent] = $this->calls[0];
        self::assertSame('pending', $sent['status']);
        self::assertFalse($sent['set_paid']);
        self::assertSame('12.50', $sent['line_items'][0]['total']);
        self::assertSame([['key' => 'omnitrade_reference', 'value' => 'ORDER-1042']], $sent['meta_data']);
        self::assertSame('camille@example.org', $sent['billing']['email']);
        self::assertSame('Durand', $sent['billing']['last_name']);
        self::assertFalse($gateway->supports(Authorize::class));
    }

    public function testAnOrderIsFetchedAsItsStatusMeans(): void
    {
        $gateway = $this->gateway();
        self::assertTrue($gateway->fetch('1042')->isPaid());
        self::assertSame(Status::REFUSED, $gateway->fetch('1043')->status);
        $order = $gateway->fetchOrder('1042');
        self::assertSame('#1042', $order->number);
        self::assertSame(1250, $order->total->amount);
        self::assertSame('Camille Durand', $order->customer->name);
        self::assertSame('55', $order->lines[0]->reference);
    }

    public function testARefundAndThePaymentMethods(): void
    {
        $gateway = $this->gateway();
        $refund = $gateway->refund('1042', Money::of(500, 'EUR'), null, 'Trop perçu');
        self::assertSame('77', $refund->reference);
        self::assertSame(500, $refund->amount->amount);
        $post = array_values(array_filter($this->calls, fn ($c) => str_ends_with($c[1], '/refunds')))[0];
        self::assertSame('5.00', $post[2]['amount']);
        self::assertTrue($post[2]['api_refund'], 'through the shop\'s gateway');

        self::assertSame(['stripe', 'bacs'], array_map(fn ($m) => $m->code, $gateway->paymentMethods()), 'the enabled ones');
    }

    public function testANotificationIsCheckedThenRead(): void
    {
        $gateway = $this->gateway();
        $body = json_encode(self::order('processing'));
        $headers = ['X-WC-Webhook-Signature' => Api::sign($body, self::SECRET), 'X-WC-Webhook-Topic' => 'order.updated', 'X-WC-Webhook-Delivery-ID' => 'd-1'];

        $notification = $gateway->notify($body, $headers);
        self::assertSame('order.updated', $notification->event);
        self::assertSame('1042', $notification->reference);
        self::assertSame(Status::PAID, $notification->status);
        self::assertSame('d-1', $notification->id);

        $this->expectException(InvalidNotificationException::class);
        $gateway->notify($body, ['X-WC-Webhook-Signature' => 'nope', 'X-WC-Webhook-Topic' => 'order.updated']);
    }

    private static function order(string $status): array
    {
        return ['id' => 1042, 'number' => '1042', 'status' => $status, 'currency' => 'EUR', 'total' => '12.50', 'payment_method' => 'stripe', 'order_key' => 'wc_order_abc', 'date_created_gmt' => '2026-10-01T10:00:00',
            'billing' => ['first_name' => 'Camille', 'last_name' => 'Durand', 'email' => 'camille@example.org', 'country' => 'FR', 'city' => 'Strasbourg', 'postcode' => '67000', 'address_1' => '1 rue du Test'],
            'shipping' => [], 'line_items' => [['name' => 'Dix heures', 'quantity' => 1, 'price' => 12.5, 'total' => '12.50', 'product_id' => 55, 'sku' => '']], 'refunds' => []];
    }
}
