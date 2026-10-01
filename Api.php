<?php

namespace Omnitrade\WooCommerce;

use Omnitrade\Exception\InvalidNotificationException;
use Omnitrade\Exception\ProviderException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * WooCommerce's REST API v3, with a consumer key and secret (HTTP basic
 * authentication over HTTPS). Webhooks are signed with the webhook's own
 * secret: base64 of the HMAC-SHA256 of the raw body, in X-WC-Webhook-Signature.
 */
final class Api
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $url,
        private readonly string $consumerKey,
        private readonly string $consumerSecret,
        private readonly ?string $webhookSecret = null,
        private readonly int $timeout = 15,
    ) {
    }

    public function url(): string
    {
        return rtrim($this->url, '/');
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<mixed> the JSON answer
     *
     * @throws ProviderException
     */
    public function call(string $method, string $path, ?array $body = null, array $query = []): array
    {
        try {
            $response = $this->http->request($method, $this->url().'/wp-json/wc/v3/'.ltrim($path, '/'), [
                'auth_basic' => [$this->consumerKey, $this->consumerSecret],
                'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
                'query' => $query,
                'body' => null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR),
                'timeout' => $this->timeout,
            ]);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (HttpExceptionInterface|\JsonException $e) {
            throw new ProviderException('woocommerce', 'WooCommerce request failed: '.$e->getMessage(), null, $e);
        }
        $data = '' === $content ? [] : json_decode($content, true);
        if (!\is_array($data)) {
            throw new ProviderException('woocommerce', sprintf('WooCommerce answered HTTP %d with a body that is not JSON.', $status));
        }
        if ($status >= 400) {
            throw new ProviderException('woocommerce', (string) ($data['message'] ?? sprintf('HTTP %d', $status)), isset($data['code']) ? (string) $data['code'] : null);
        }

        return $data;
    }

    public function canVerify(): bool
    {
        return null !== $this->webhookSecret && '' !== $this->webhookSecret;
    }

    /** @throws InvalidNotificationException */
    public function verify(string $body, ?string $signature): void
    {
        if (!$this->canVerify()) {
            throw new InvalidNotificationException('woocommerce', 'No webhook secret: the notification cannot be checked.');
        }
        if (null === $signature || '' === $signature || !hash_equals(self::sign($body, (string) $this->webhookSecret), $signature)) {
            throw new InvalidNotificationException('woocommerce', 'The notification\'s signature does not match.');
        }
    }

    /** The header WooCommerce would send for this body - used by the tests. */
    public static function sign(string $body, string $secret): string
    {
        return base64_encode(hash_hmac('sha256', $body, $secret, true));
    }
}
