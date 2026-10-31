<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Adyen\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\Adyen\Model\Api;
use Zero\Core\Model\Http\JsonClient;

class ApiTest extends TestCase
{
    private JsonClient&MockObject $client;
    private ScopeConfigInterface&MockObject $scopeConfig;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->client = $this->createMock(JsonClient::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->scopeConfig->method('getValue')->willReturn('k');
        $this->scopeConfig->method('isSetFlag')->willReturn(true);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function api(?ScopeConfigInterface $config = null): Api
    {
        return new Api($this->client, $config ?? $this->scopeConfig, $this->createMock(EncryptorInterface::class), $this->logger, 11);
    }

    public function testARequestGoesOutWithTheModulesUrlBodyAuthAndTimeout(): void
    {
        $this->client->expects(self::once())->method('request')
            ->with('POST', 'https://checkout-test.adyen.com/v71/paymentLinks', ['a' => 1], ['X-API-Key' => 'k'], 11)
            ->willReturn(['status' => 200, 'body' => ['id' => 'pl_1']]);

        self::assertIsArray($this->api()->send('POST', '/paymentLinks', ['a' => 1], 1));
    }

    public function testNothingIsSentWithoutTheKey(): void
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn('');
        $this->client->expects(self::never())->method('request');

        $this->expectException(LocalizedException::class);
        $this->api($config)->send('POST', '/paymentLinks', ['a' => 1], 1);
    }

    public function testARefusalIsLoggedAndCarriesTheProvidersText(): void
    {
        $this->client->method('request')->willReturn(['status' => 400, 'body' => ['message' => 'Nope']]);
        $this->logger->expects(self::once())->method('warning')->with(self::stringContains('HTTP 400 Nope'));

        try {
            $this->api()->send('POST', '/paymentLinks', ['a' => 1], 1);
            self::fail('A refusal must throw');
        } catch (LocalizedException $e) {
            self::assertStringContainsString('Nope', (string) $e->getMessage());
        }
    }

    public function testAnAnswerThatIsNotJsonIsARefusalWithTheStatus(): void
    {
        $this->client->method('request')->willReturn(['status' => 502, 'body' => null]);

        try {
            $this->api()->send('POST', '/paymentLinks', ['a' => 1], 1);
            self::fail('A non-JSON answer must throw');
        } catch (LocalizedException $e) {
            self::assertStringContainsString('HTTP 502', (string) $e->getMessage());
        }
    }

    public function testATransportFailureBecomesAFriendlyError(): void
    {
        $this->client->method('request')->willThrowException(new \RuntimeException('timed out'));
        $this->logger->expects(self::once())->method('warning');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('is not answering');
        $this->api()->send('POST', '/paymentLinks', ['a' => 1], 1);
    }

    /**
     * Adyen's own exponents (A3-19): CLP and ISK in hundredths, IDR, CVE and
     * UGX whole, KWD in thousandths.
     */
    #[DataProvider('exponents')]
    public function testAmountExponents(float $amount, string $currency, int $minor): void
    {
        $this->assertSame(['value' => $minor, 'currency' => $currency], Api::amount($amount, $currency));
    }

    /** @return array<string, array{float, string, int}> */
    public static function exponents(): array
    {
        return [
            'CLP' => [1000.0, 'CLP', 100000],
            'ISK' => [5.0, 'ISK', 500],
            'IDR' => [15000.0, 'IDR', 15000],
            'CVE' => [100.0, 'CVE', 100],
            'UGX' => [3000.0, 'UGX', 3000],
            'JPY' => [1000.0, 'JPY', 1000],
            'KRW' => [5000.0, 'KRW', 5000],
            'KWD' => [1.0, 'KWD', 1000],
            'BHD' => [1.234, 'BHD', 1234],
            'EUR' => [10.99, 'EUR', 1099],
            'EUR half cent' => [0.285, 'EUR', 29],
            'SEK' => [99.5, 'SEK', 9950],
        ];
    }
}
