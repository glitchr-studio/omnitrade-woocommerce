<?php

namespace Omnitrade\WooCommerce\Tests;

use Omnitrade\GatewayInterface;
use Omnitrade\Model\Product;
use Omnitrade\Request\FetchInventory;
use Omnitrade\Request\FetchProduct;
use Omnitrade\Request\FetchProducts;
use Omnitrade\WooCommerce\Api;
use Omnitrade\WooCommerce\WooCommerceGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WooCommerceCatalogueTest extends TestCase
{
    private const SECRET = 'wc-secret';

    /** @var list<array{string, string, array<string, string>}> method, path, query */
    private array $calls = [];

    private static function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/Fixtures/'.$name), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function gateway(array $options = []): GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertContains('Authorization: Basic '.base64_encode('ck_x:cs_x'), $options['headers']);
            $path = substr((string) parse_url($url, \PHP_URL_PATH), \strlen('/wp-json/wc/v3/'));
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $this->calls[] = [$method, $path, $query];
            $simple = self::fixture('product.simple.json');
            $variable = self::fixture('product.variable.json');
            $variations = self::fixture('variations.json');
            $json = static fn (mixed $data, array $headers = []) => new MockResponse(json_encode($data), ['response_headers' => $headers]);
            $notFound = new MockResponse(json_encode(['code' => 'woocommerce_rest_product_invalid_id', 'message' => 'Invalid ID.', 'data' => ['status' => 404]]), ['http_code' => 404]);

            return match (true) {
                'products' === $path && isset($query['slug']) => $json('margaux' === $query['slug'] ? [$variable] : []),
                'products' === $path && isset($query['include']) => $json(array_values(array_filter([$simple, $variable], static fn ($p) => \in_array((string) $p['id'], explode(',', $query['include']), true)))),
                'products' === $path => $json('2' === ($query['page'] ?? '1') ? [$variable] : [$simple], ['X-WP-Total' => '2', 'X-WP-TotalPages' => '2']),
                'products/501' === $path => $json($simple),
                'products/600' === $path => $json($variable),
                'products/602' === $path => $json($variations[1] + ['parent_id' => 600]),
                'products/600/variations' === $path => $json($variations, ['X-WP-TotalPages' => '1']),
                default => $notFound,
            };
        });

        return (new WooCommerceGatewayFactory($http))->create($options + ['url' => 'https://shop.example/', 'consumer_key' => 'ck_x', 'consumer_secret' => 'cs_x', 'webhook_secret' => self::SECRET]);
    }

    public function testTheCatalogueIsReadPageByPage(): void
    {
        $gateway = $this->gateway();
        self::assertTrue($gateway->supports(FetchProducts::class));
        self::assertTrue($gateway->supports(FetchProduct::class));
        self::assertTrue($gateway->supports(FetchInventory::class));

        $first = $gateway->fetchProducts(limit: 1);
        self::assertSame(['per_page' => '1', 'page' => '1', 'status' => 'any', 'orderby' => 'id', 'order' => 'asc'], $this->calls[0][2]);
        self::assertSame('2', $first->next, 'the next page, X-WP-TotalPages saying there is one');
        self::assertSame('Crémant d\'Alsace', $first->products[0]->title);

        $second = $gateway->fetchProducts($first->next, limit: 1);
        self::assertSame('2', $this->calls[1][2]['page']);
        self::assertSame('products/600/variations', $this->calls[2][1], 'a variable product\'s variations read with it');
        self::assertFalse($second->hasMore());
        self::assertCount(2, $second->products[0]->variants);

        $gateway->fetchProducts(updatedSince: new \DateTimeImmutable('2026-10-01 10:00:00', new \DateTimeZone('Europe/Paris')), query: 'margaux');
        $sent = $this->calls[3][2];
        self::assertSame('2026-10-01T08:00:00', $sent['modified_after']);
        self::assertSame('true', $sent['dates_are_gmt']);
        self::assertSame('margaux', $sent['search']);
        self::assertSame('50', $sent['per_page']);
    }

    public function testOnlyThePublishedOnesWhenAsked(): void
    {
        $this->gateway(['product_status' => 'publish'])->fetchProducts();
        self::assertSame('publish', $this->calls[0][2]['status']);
    }

    public function testASimpleProductIsItsOwnOnlyVariant(): void
    {
        $product = $this->gateway()->fetchProduct('501');

        self::assertSame('woocommerce', $product->provider);
        self::assertSame('501', $product->reference);
        self::assertSame('cremant-alsace', $product->handle);
        self::assertSame('Domaine Exemple', $product->brand, 'WooCommerce\'s own brands');
        self::assertSame(['Effervescents', 'Alsace'], $product->categories);
        self::assertSame(['fête'], $product->tags);
        self::assertSame(['Cépage' => ['Pinot blanc', 'Auxerrois'], 'Contenance' => '75 cl'], $product->attributes);
        self::assertSame([], $product->options);
        self::assertSame('https://shop.example/product/cremant-alsace/', $product->url);
        self::assertSame(Product::ACTIVE, $product->status);
        self::assertSame('<p>Fines bulles.</p>', $product->description);
        self::assertEquals(new \DateTimeImmutable('2026-10-02T08:30:00Z'), $product->updatedAt);
        self::assertSame('https://shop.example/wp-content/uploads/cremant.jpg', $product->media[0]->url);
        self::assertSame('La bouteille', $product->media[0]->alt);

        self::assertCount(1, $product->variants);
        $variant = $product->variants[0];
        self::assertSame('501', $variant->reference);
        self::assertNull($variant->title);
        self::assertSame('CRM-BRUT', $variant->sku);
        self::assertSame('3760000000501', $variant->barcode);
        self::assertSame(1490, $variant->price()->amount, 'the sale price');
        self::assertSame(1650, $variant->offer()->compareAt->amount, 'the regular one struck through');
        self::assertSame('EUR', $variant->price()->currency);
        self::assertTrue($variant->offer()->available);
        self::assertSame(36, $variant->stock->quantity);
        self::assertTrue($variant->stock->tracked);
        self::assertSame(1500, $variant->weight, 'kilograms in grams');
    }

    public function testAVariableProductsVariationsAreItsVariants(): void
    {
        $product = $this->gateway(['currency' => 'CHF'])->fetchProduct('https://shop.example/product/margaux/?attribute_pa_millesime=2019');

        self::assertSame('margaux', $this->calls[0][2]['slug'], 'the slug of the page');
        self::assertSame('600', $product->reference);
        self::assertSame(Product::DRAFT, $product->status);
        self::assertSame('Château Exemple', $product->brand, 'a Marque attribute');
        self::assertSame(['Marque' => 'Château Exemple'], $product->attributes);
        self::assertSame('Millésime', $product->options[0]->name);
        self::assertSame(['2019', '2018'], $product->options[0]->values);
        self::assertSame('<p>De garde.</p>', $product->description, 'the short description when there is no other');

        [$v2019, $v2018] = $product->variants;
        self::assertSame('601', $v2019->reference);
        self::assertSame('2019', $v2019->title);
        self::assertSame(['Millésime' => '2019'], $v2019->options);
        self::assertSame(2900, $v2019->price()->amount);
        self::assertSame('CHF', $v2019->price()->currency);
        self::assertNull($v2019->offer()->compareAt);
        self::assertFalse($v2019->offer()->available, 'a draft product is not for sale');
        self::assertFalse($v2019->stock->tracked);
        self::assertNull($v2019->stock->quantity);
        self::assertSame('https://shop.example/wp-content/uploads/margaux-2019.jpg', $v2019->image->url);
        self::assertSame(1300, $v2019->weight);
        self::assertTrue($v2018->stock->tracked, '"parent": the product counts it');
        self::assertSame(7, $v2018->stock->quantity);
        self::assertNull($v2018->image, 'no picture of its own');
        self::assertSame(2400, $product->price()->amount);
    }

    public function testAProductIsFoundByIdSlugVariationOrNotAtAll(): void
    {
        $gateway = $this->gateway();
        self::assertSame('600', $gateway->fetchProduct('margaux')->reference, 'a slug');
        self::assertSame('600', $gateway->fetchProduct('602')->reference, 'a variation answers its product');
        self::assertSame('501', $gateway->fetchProduct('https://shop.example/?post_type=product&p=501')->reference);
        self::assertNull($gateway->fetchProduct('404'));
        self::assertNull($gateway->fetchProduct('https://shop.example/product/nothing/'));
    }

    public function testTheInventoryOfProductsAndVariations(): void
    {
        $gateway = $this->gateway();

        $stocks = $gateway->fetchInventory(['501', '602', '404']);
        self::assertSame('501,602,404', $this->calls[0][2]['include']);
        self::assertSame(['501', '602'], array_map(static fn ($s) => $s->reference, $stocks), 'the variation read by its id, the unknown left out');
        self::assertSame(36, $stocks[0]->quantity);
        self::assertSame('CRM-BRUT', $stocks[0]->sku);
        self::assertSame(7, $stocks[1]->quantity);

        $all = $gateway->fetchInventory();
        self::assertSame(['501', '601', '602'], array_map(static fn ($s) => $s->reference, $all), 'every page, a variable product\'s variations in its place');
        self::assertNull($all[1]->quantity);
        self::assertTrue($all[1]->available());
    }

    public function testTheProductWebhooksCarryTheCatalogue(): void
    {
        $gateway = $this->gateway();
        $headers = static fn (string $body, string $topic) => ['X-WC-Webhook-Signature' => Api::sign($body, self::SECRET), 'X-WC-Webhook-Topic' => $topic, 'X-WC-Webhook-Resource' => 'product', 'X-WC-Webhook-Delivery-ID' => '9'];

        $body = (string) file_get_contents(__DIR__.'/Fixtures/product.simple.json');
        $updated = $gateway->notify($body, $headers($body, 'product.updated'));
        self::assertTrue($updated->isCatalogue());
        self::assertSame('501', $updated->reference);
        self::assertSame('Domaine Exemple', $updated->product->brand);
        self::assertSame(1490, $updated->product->price()->amount);
        self::assertSame([], $this->calls, 'a simple product: nothing to ask');

        $body = (string) file_get_contents(__DIR__.'/Fixtures/product.variable.json');
        $created = $gateway->notify($body, $headers($body, 'product.created'));
        self::assertCount(2, $created->product->variants, 'the variations read along');

        $deleted = $gateway->notify($body = '{"id":501}', $headers($body, 'product.deleted'));
        self::assertTrue($deleted->isCatalogue());
        self::assertNull($deleted->product);
        self::assertSame('501', $deleted->reference);
    }
}
