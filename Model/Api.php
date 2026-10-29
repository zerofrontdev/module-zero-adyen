<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Adyen\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Zero\Core\Model\Http\JsonClient;

/**
 * Adyen's Checkout API v71 (payment links, refunds): the API key in
 * X-API-Key, JSON both ways, amounts in minor units. The test host, or the
 * live host with the account's live URL prefix.
 */
class Api
{
    private const TEST = 'https://checkout-test.adyen.com/v71';
    private const LIVE = 'https://%s-checkout-live.adyenpayments.com/checkout/v71';

    /** Currencies whose minor unit isn't a hundredth. */
    private const EXPONENTS = ['JPY' => 0, 'KRW' => 0, 'ISK' => 0, 'CLP' => 0, 'VND' => 0,
        'BHD' => 3, 'JOD' => 3, 'KWD' => 3, 'OMR' => 3, 'TND' => 3];

    public function __construct(
        private readonly JsonClient $client,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly LoggerInterface $logger,
        private readonly int $timeout = 20
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function send(string $method, string $path, ?array $body, ?int $storeId): array
    {
        $key = $this->secret('api_key', $storeId);
        if ($key === '' || $this->merchantAccount($storeId) === '') {
            throw new LocalizedException(__('Adyen is not set up: the API key or merchant account is missing.'));
        }
        try {
            ['status' => $status, 'body' => $answer] = $this->client->request(
                $method,
                $this->base($storeId) . $path,
                $body,
                ['X-API-Key' => $key],
                $this->timeout
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Zero Adyen: ' . $path . ': ' . $e->getMessage());
            throw new LocalizedException(__('Adyen is not answering. Please try again in a minute.'), $e);
        }
        if ($status < 200 || $status >= 300 || $answer === null) {
            $message = is_array($answer) ? (string) ($answer['message'] ?? '') : '';
            $this->logger->warning(sprintf('Zero Adyen: %s: HTTP %d %s', $path, $status, $message));
            throw new LocalizedException(__('Adyen refused the request: %1', $message !== '' ? $message : 'HTTP ' . $status));
        }

        return $answer;
    }

    public function isConfigured(?int $storeId): bool
    {
        return $this->secret('api_key', $storeId) !== '' && $this->merchantAccount($storeId) !== '';
    }

    public function merchantAccount(?int $storeId): string
    {
        return trim((string) $this->scopeConfig->getValue('payment/zero_adyen/merchant_account', ScopeInterface::SCOPE_STORE, $storeId));
    }

    /**
     * The webhook's HMAC key (hex), as set in the Customer Area.
     */
    public function hmacKey(?int $storeId): string
    {
        return $this->secret('hmac_key', $storeId);
    }

    /**
     * An amount as Adyen takes it.
     *
     * @return array{value: int, currency: string}
     */
    public static function amount(float $amount, string $currency): array
    {
        return ['value' => (int) round($amount * 10 ** (self::EXPONENTS[$currency] ?? 2)), 'currency' => $currency];
    }

    private function base(?int $storeId): string
    {
        if ($this->scopeConfig->isSetFlag('payment/zero_adyen/test_mode', ScopeInterface::SCOPE_STORE, $storeId)) {
            return self::TEST;
        }
        $prefix = trim((string) $this->scopeConfig->getValue('payment/zero_adyen/live_prefix', ScopeInterface::SCOPE_STORE, $storeId));

        return sprintf(self::LIVE, $prefix);
    }

    private function secret(string $field, ?int $storeId): string
    {
        $value = trim((string) $this->scopeConfig->getValue('payment/zero_adyen/' . $field, ScopeInterface::SCOPE_STORE, $storeId));

        // Read through the Encrypted backend model its config.xml default names, the
        // value comes decrypted; one stored some other way may still be encrypted.
        return preg_match('/^\d+:\d+:/', $value) ? trim((string) $this->encryptor->decrypt($value)) : $value;
    }
}
