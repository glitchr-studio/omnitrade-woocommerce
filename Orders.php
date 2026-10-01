<?php

namespace Omnitrade\WooCommerce;

use Omnitrade\Model\Customer;
use Omnitrade\Model\Line;
use Omnitrade\Model\Money;
use Omnitrade\Model\Payment;
use Omnitrade\Model\PlatformOrder;
use Omnitrade\Model\Status;
use Omnitrade\Model\Transaction;

/** A WooCommerce order, as the REST API answers it, read as a Transaction or a PlatformOrder; and a Payment written as one. */
final class Orders
{
    public const REFERENCE_KEY = 'omnitrade_reference';

    public static function status(array $order): Status
    {
        $refunded = 0;
        foreach ($order['refunds'] ?? [] as $refund) {
            $refunded += (int) round(abs((float) ($refund['total'] ?? 0)) * 100);
        }
        $total = (int) round((float) ($order['total'] ?? 0) * 100);

        return match ((string) ($order['status'] ?? '')) {
            'processing', 'completed' => $refunded > 0 ? ($refunded >= $total ? Status::REFUNDED : Status::PARTIALLY_REFUNDED) : Status::PAID,
            'refunded' => Status::REFUNDED,
            'cancelled' => Status::CANCELLED,
            'failed' => Status::REFUSED,
            default => Status::PENDING, // pending, on-hold, checkout-draft
        };
    }

    /** @param array<string, mixed> $order */
    public static function transaction(array $order): Transaction
    {
        $currency = strtoupper((string) ($order['currency'] ?? 'EUR'));
        $status = self::status($order);
        $refunded = 0;
        foreach ($order['refunds'] ?? [] as $refund) {
            $refunded += Money::fromDecimal((string) abs((float) ($refund['total'] ?? 0)), $currency)->amount;
        }

        return new Transaction(
            provider: 'woocommerce',
            reference: (string) $order['id'],
            status: $status,
            amount: isset($order['total']) ? Money::fromDecimal((string) $order['total'], $currency) : null,
            redirectUrl: Status::PENDING === $status ? ($order['payment_url'] ?? null) : null,
            message: $order['status'] ?? null,
            method: ($order['payment_method'] ?? null) ?: null,
            refunded: $refunded > 0 ? Money::of($refunded, $currency) : null,
            metadata: array_filter(['number' => $order['number'] ?? null, 'order_key' => $order['order_key'] ?? null, 'transaction_id' => $order['transaction_id'] ?? null]),
            createdAt: isset($order['date_created_gmt']) ? new \DateTimeImmutable($order['date_created_gmt'].'Z') : null,
            raw: $order,
        );
    }

    /** @param array<string, mixed> $order */
    public static function platformOrder(array $order): PlatformOrder
    {
        $currency = strtoupper((string) ($order['currency'] ?? 'EUR'));
        $billing = $order['billing'] ?? [];
        $shipping = $order['shipping'] ?? [];
        $lines = [];
        foreach ($order['line_items'] ?? [] as $item) {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $lines[] = new Line(
                (string) ($item['name'] ?? ''),
                Money::fromDecimal((string) ($item['price'] ?? ((float) ($item['total'] ?? 0)) / $quantity), $currency),
                $quantity,
                ($item['sku'] ?? null) ?: null,
                reference: isset($item['product_id']) ? (string) $item['product_id'] : null,
            );
        }

        return new PlatformOrder(
            provider: 'woocommerce',
            reference: (string) $order['id'],
            number: isset($order['number']) ? '#'.$order['number'] : null,
            status: self::status($order),
            total: Money::fromDecimal((string) ($order['total'] ?? '0'), $currency),
            customer: new Customer(
                email: ($billing['email'] ?? null) ?: null,
                name: trim(($billing['first_name'] ?? '').' '.($billing['last_name'] ?? '')) ?: null,
                reference: isset($order['customer_id']) && $order['customer_id'] ? (string) $order['customer_id'] : null,
                phone: ($billing['phone'] ?? null) ?: null,
                street: array_values(array_filter([$shipping['address_1'] ?? $billing['address_1'] ?? null, $shipping['address_2'] ?? $billing['address_2'] ?? null])),
                postalCode: ($shipping['postcode'] ?? $billing['postcode'] ?? null) ?: null,
                city: ($shipping['city'] ?? $billing['city'] ?? null) ?: null,
                country: ($shipping['country'] ?? $billing['country'] ?? null) ?: null,
            ),
            lines: $lines,
            financialStatus: $order['status'] ?? null,
            fulfillmentStatus: 'completed' === ($order['status'] ?? null) ? 'completed' : null,
            createdAt: isset($order['date_created_gmt']) ? new \DateTimeImmutable($order['date_created_gmt'].'Z') : null,
            raw: $order,
        );
    }

    /** @return array<string, mixed> an order to create, unpaid: the buyer pays it on the shop's checkout */
    public static function create(Payment $payment): array
    {
        $currency = $payment->amount->currency;
        $items = [];
        $fees = [];
        if ($payment->linesAddUp()) {
            foreach ($payment->lines as $line) {
                $item = ['name' => $line->label, 'quantity' => $line->quantity, 'total' => $line->total()->decimal()];
                if ($line->reference && ctype_digit($line->reference)) {
                    $item['product_id'] = (int) $line->reference;
                }
                $items[] = $item;
            }
            if ($payment->discount && $payment->discount->amount > 0) {
                $fees[] = ['name' => 'Discount', 'total' => '-'.$payment->discount->decimal()];
            }
        } else {
            $items[] = ['name' => $payment->description ?? $payment->reference, 'quantity' => 1, 'total' => $payment->amount->decimal()];
        }
        $customer = $payment->customer;
        $name = $customer?->name ? explode(' ', $customer->name, 2) : [];
        $meta = [['key' => self::REFERENCE_KEY, 'value' => $payment->reference]];
        foreach ($payment->metadata as $key => $value) {
            $meta[] = ['key' => (string) $key, 'value' => (string) $value];
        }
        $order = array_filter([
            'status' => 'pending',
            'currency' => $currency,
            'set_paid' => false,
            'line_items' => $items,
            'fee_lines' => $fees ?: null,
            'shipping_lines' => $payment->linesAddUp() && $payment->shipping && $payment->shipping->amount > 0
                ? [['method_id' => 'flat_rate', 'method_title' => 'Shipping', 'total' => $payment->shipping->decimal()]] : null,
            'customer_note' => $payment->notice,
            'meta_data' => $meta,
            'billing' => $customer ? array_filter([
                'email' => $customer->email,
                'first_name' => $name[0] ?? null,
                'last_name' => $name[1] ?? null,
                'phone' => $customer->phone,
                'address_1' => $customer->street[0] ?? null,
                'address_2' => $customer->street[1] ?? null,
                'postcode' => $customer->postalCode,
                'city' => $customer->city,
                'country' => $customer->country,
            ]) : null,
        ], static fn ($value) => null !== $value);
        $order['set_paid'] = false;

        return $order;
    }
}
