<?php

namespace Omnitrade\WooCommerce;

use Omnitrade\Model\Media;
use Omnitrade\Model\Money;
use Omnitrade\Model\Offer;
use Omnitrade\Model\Option;
use Omnitrade\Model\Product;
use Omnitrade\Model\ProductVariant;
use Omnitrade\Model\Stock;

/**
 * A WooCommerce product, as REST v3 answers it (and a product webhook posts
 * it), read as a Product: a simple product is its own only variant, a
 * variable product's variations are its variants (fetched apart, from
 * /products/<id>/variations). The attributes used for variations are the
 * options, the others the attributes. Pure: arrays in, models out.
 */
final class Products
{
    /**
     * @param array<string, mixed>       $product    a /products item
     * @param list<array<string, mixed>> $variations its /products/<id>/variations, for a variable product
     * @param string                     $weightUnit the store's (WooCommerce → Settings → Products): kg, g, lbs, oz
     */
    public static function product(array $product, array $variations, string $currency, string $weightUnit = 'kg'): Product
    {
        $url = self::blankToNull($product['permalink'] ?? null);
        $status = self::status($product['status'] ?? null);

        $attributes = [];
        $options = [];
        $brand = null;
        foreach ($product['attributes'] ?? [] as $attribute) {
            $name = trim((string) ($attribute['name'] ?? ''));
            $values = array_values(array_filter(array_map(static fn ($v) => trim((string) $v), (array) ($attribute['options'] ?? [])), static fn (string $v) => '' !== $v));
            if ('' === $name || !$values) {
                continue;
            }
            if (!empty($attribute['variation']) && 'variable' === ($product['type'] ?? null)) {
                $options[] = new Option($name, $values);
                continue;
            }
            $attributes[$name] = 1 === \count($values) ? $values[0] : $values;
            if (null === $brand && self::isBrand($attribute)) {
                $brand = $values[0];
            }
        }
        // WooCommerce 9.6+ has brands of its own, a taxonomy like the categories.
        foreach ($product['brands'] ?? [] as $term) {
            if ('' !== $name = trim((string) ($term['name'] ?? ''))) {
                $brand = $name;
                break;
            }
        }

        $media = [];
        foreach ($product['images'] ?? [] as $image) {
            if (null !== $m = self::image($image)) {
                $media[] = $m;
            }
        }

        if ('variable' === ($product['type'] ?? null)) {
            $variants = array_map(static fn (array $variation) => self::variant($variation, $currency, $weightUnit, $url, $product, true), $variations);
        } else {
            $variants = [self::variant($product, $currency, $weightUnit, $url, $product, false)];
        }

        return new Product(
            provider: 'woocommerce',
            reference: (string) ($product['id'] ?? ''),
            title: html_entity_decode((string) ($product['name'] ?? ''), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'),
            description: self::blankToNull($product['description'] ?? null) ?? self::blankToNull($product['short_description'] ?? null),
            handle: self::blankToNull($product['slug'] ?? null),
            brand: $brand,
            url: $url,
            status: $status,
            tags: self::names($product['tags'] ?? []),
            categories: self::names($product['categories'] ?? []),
            attributes: $attributes,
            options: $options,
            variants: $variants,
            media: $media,
            updatedAt: self::date($product['date_modified_gmt'] ?? null, true) ?? self::date($product['date_modified'] ?? null, false),
            raw: $variations ? $product + ['_variations' => $variations] : $product,
        );
    }

    /**
     * A product's or a variation's stock: stock_quantity when it manages its
     * stock (a variation saying "parent" counts on its product's), else a
     * null quantity.
     *
     * @param array<string, mixed>|null $parent the variation's product, for "parent"
     */
    public static function stock(array $item, ?array $parent = null): Stock
    {
        $manage = $item['manage_stock'] ?? false;
        $quantity = $item['stock_quantity'] ?? null;
        if ('parent' === $manage) {
            $manage = (bool) ($parent['manage_stock'] ?? true);
            $quantity ??= $parent['stock_quantity'] ?? null;
        }
        $tracked = true === $manage || 1 === $manage || '1' === $manage;

        return new Stock(
            (string) ($item['id'] ?? ''),
            $tracked ? (int) ($quantity ?? 0) : null,
            $tracked,
            self::blankToNull($item['sku'] ?? null),
        );
    }

    /** publish → active; draft, pending, private, future → draft; trash → archived. */
    public static function status(mixed $status): string
    {
        return match ((string) $status) {
            'publish', '' => Product::ACTIVE,
            'trash' => Product::ARCHIVED,
            default => Product::DRAFT,
        };
    }

    /** @param array<string, mixed> $product the product the variation is of (itself, for a simple one) */
    private static function variant(array $item, string $currency, string $weightUnit, ?string $productUrl, array $product, bool $isVariation): ProductVariant
    {
        $options = [];
        if ($isVariation) {
            foreach ($item['attributes'] ?? [] as $attribute) {
                $name = trim((string) ($attribute['name'] ?? ''));
                $value = trim((string) ($attribute['option'] ?? ''));
                if ('' !== $name && '' !== $value) { // '' is "any": not a choice of its own
                    $options[$name] = $value;
                }
            }
        }
        $stock = self::stock($item, $isVariation ? $product : null);
        $available = (bool) ($item['purchasable'] ?? true)
            && 'outofstock' !== ($item['stock_status'] ?? 'instock')
            && Product::ACTIVE === self::status($product['status'] ?? null)
            && (!$isVariation || 'publish' === ($item['status'] ?? 'publish'));

        return new ProductVariant(
            reference: (string) ($item['id'] ?? ''),
            title: $options ? implode(' / ', $options) : null,
            sku: self::blankToNull($item['sku'] ?? null),
            barcode: self::blankToNull($item['global_unique_id'] ?? null),
            offers: self::offers($item, $currency, $available, $isVariation ? (self::blankToNull($item['permalink'] ?? null) ?? $productUrl) : $productUrl),
            options: $options,
            stock: $stock,
            image: $isVariation && \is_array($item['image'] ?? null) ? self::image($item['image']) : null,
            weight: self::grams($item['weight'] ?? null, $weightUnit),
            raw: $isVariation ? $item : [],
        );
    }

    /**
     * regular_price, and sale_price when it is on sale: the price is then the
     * sale price, the regular one struck through.
     *
     * @return list<Offer>
     */
    private static function offers(array $item, string $currency, bool $available, ?string $url): array
    {
        $regular = self::blankToNull($item['regular_price'] ?? null);
        $sale = self::blankToNull($item['sale_price'] ?? null);
        $onSale = null !== $sale && ($item['on_sale'] ?? true);
        $price = $onSale ? $sale : ($regular ?? self::blankToNull($item['price'] ?? null));
        if (null === $price) {
            return []; // no price set: not for sale yet
        }
        $price = Money::fromDecimal($price, $currency);
        $compareAt = $onSale && null !== $regular ? Money::fromDecimal($regular, $currency) : null;
        if (null !== $compareAt && $compareAt->amount <= $price->amount) {
            $compareAt = null;
        }

        return [new Offer($price, $compareAt, $available, $url)];
    }

    /** A "Brand" (or "Marque") attribute, global (pa_brand) or the product's own. */
    private static function isBrand(array $attribute): bool
    {
        $slug = strtolower((string) ($attribute['slug'] ?? ''));
        $name = strtolower(trim((string) ($attribute['name'] ?? '')));

        return \in_array($slug, ['pa_brand', 'brand', 'pa_marque', 'marque'], true) || \in_array($name, ['brand', 'pa_brand', 'marque'], true);
    }

    private static function image(array $image): ?Media
    {
        $src = self::blankToNull($image['src'] ?? null);

        return null === $src ? null : new Media($src, self::blankToNull($image['alt'] ?? null) ?? self::blankToNull($image['name'] ?? null), reference: isset($image['id']) && 0 !== (int) $image['id'] ? (string) $image['id'] : null);
    }

    /** @return list<string> the terms' names */
    private static function names(array $terms): array
    {
        $names = array_map(static fn ($t) => html_entity_decode(trim((string) (\is_array($t) ? ($t['name'] ?? '') : $t)), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'), $terms);

        return array_values(array_unique(array_filter($names, static fn (string $n) => '' !== $n)));
    }

    private static function grams(mixed $value, string $unit): ?int
    {
        if (null === $value || '' === $value || !is_numeric($value)) {
            return null;
        }

        return (int) round((float) $value * match (strtolower($unit)) {
            'g' => 1,
            'lbs', 'lb' => 453.59237,
            'oz' => 28.349523125,
            default => 1000,
        });
    }

    private static function date(mixed $value, bool $gmt): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }
        try {
            return $gmt ? new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC')) : new \DateTimeImmutable((string) $value);
        } catch (\Exception) {
            return null;
        }
    }

    private static function blankToNull(mixed $value): ?string
    {
        $value = null === $value || \is_array($value) ? '' : trim((string) $value);

        return '' === $value ? null : $value;
    }
}
