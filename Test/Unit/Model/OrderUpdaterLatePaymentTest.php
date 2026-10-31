<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Adyen\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment as OrderPaymentInfo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zero\Adyen\Model\Api;
use Zero\Adyen\Model\OrderUpdater;
use Zero\Adyen\Model\Payment;
use Zero\Checkout\Model\RedirectPayment\OrderPayment;
use Zero\Checkout\Model\RedirectPayment\ReturnResult;

/**
 * A cancelled order's payment link is only asked about (one GET): completed
 * is reported in the link's currency, an active link (nobody paid) is null,
 * and a paymentPending link is not acknowledged (R8-7), so Adyen sends the
 * event again. An active link never cancels the order from update().
 */
class OrderUpdaterLatePaymentTest extends TestCase
{
    private Api&MockObject $api;
    private OrderPayment&MockObject $orderPayment;
    private OrderRepositoryInterface&MockObject $orders;
    private OrderUpdater $updater;
    /** @var list<array{0: string, 1: string, 2: mixed}> method, path, body of every Adyen call */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->sent = [];
        $this->api = $this->createMock(Api::class);
        $this->orderPayment = $this->createMock(OrderPayment::class);
        $this->orders = $this->createMock(OrderRepositoryInterface::class);
        // Asking about a cancelled order never saves it.
        $this->orders->expects($this->never())->method('save');
        $this->updater = new OrderUpdater($this->api, $this->orderPayment, $this->orders);
    }

    private function answer(array $link): void
    {
        $this->api->expects($this->once())->method('send')->willReturnCallback(
            function (string $method, string $path, $body) use ($link): array {
                $this->sent[] = [$method, $path, $body];

                return $link;
            }
        );
    }

    private function order(
        string $method = Payment::CODE,
        string $linkId = 'PL61C53A8B97E6915A',
        string $psp = '',
        ?OrderPaymentInfo $payment = null
    ): Order {
        $payment ??= $this->createStub(OrderPaymentInfo::class);
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getAdditionalInformation')->willReturnMap([
            [Payment::LINK_ID, $linkId],
            [Payment::PSP_REFERENCE, $psp],
        ]);
        $order = $this->createStub(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('000000123');
        $order->method('getState')->willReturn(Order::STATE_CANCELED);

        return $order;
    }

    #[DataProvider('completed')]
    public function testPaidIsReported(array $amount, string $expected): void
    {
        $this->answer(['status' => 'completed', 'amount' => $amount]);
        $this->orderPayment->expects($this->never())->method($this->anything());

        $this->assertSame(
            ['id' => 'PL61C53A8B97E6915A', 'amount' => $expected, 'currency' => $amount['currency']],
            $this->updater->latePayment($this->order())
        );
        $this->assertSame([['GET', '/paymentLinks/PL61C53A8B97E6915A', null]], $this->sent);
    }

    /** @return array<string, array{array{value: int, currency: string}, string}> */
    public static function completed(): array
    {
        // Adyen's exponents: CLP and ISK in hundredths, IDR whole, KWD in thousandths.
        return [
            'EUR' => [['value' => 1099, 'currency' => 'EUR'], '10.99'],
            'CLP' => [['value' => 100000, 'currency' => 'CLP'], '1000.00'],
            'IDR' => [['value' => 15000, 'currency' => 'IDR'], '15000'],
            'KWD' => [['value' => 1000, 'currency' => 'KWD'], '1.000'],
        ];
    }

    public function testThePspReferenceIsTheIdWhenKnown(): void
    {
        $this->answer(['status' => 'completed', 'amount' => ['value' => 1099, 'currency' => 'EUR']]);
        $this->orderPayment->expects($this->never())->method($this->anything());

        $this->assertSame('8815', $this->updater->latePayment($this->order(Payment::CODE, 'PL61C53A8B97E6915A', '8815'))['id']);
    }

    public function testAnActiveLinkIsNull(): void
    {
        $this->answer(['status' => 'active']);
        $this->orderPayment->expects($this->never())->method($this->anything());

        $this->assertNull($this->updater->latePayment($this->order()));
    }

    public function testAnExpiredLinkIsNull(): void
    {
        $this->answer(['status' => 'expired']);
        $this->orderPayment->expects($this->never())->method($this->anything());

        $this->assertNull($this->updater->latePayment($this->order()));
    }

    public function testAPaymentPendingLinkOfACancelledOrderIsAskedAgainLater(): void
    {
        $this->answer(['status' => 'paymentPending']);
        $this->orderPayment->expects($this->never())->method($this->anything());

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Adyen has not settled the payment link of cancelled order #000000123 yet.');
        $this->updater->latePayment($this->order());
    }

    public function testNeverChangesTheOrder(): void
    {
        $payment = $this->createMock(OrderPaymentInfo::class);
        $payment->expects($this->never())->method('setAdditionalInformation');
        $this->answer(['status' => 'completed', 'amount' => ['value' => 1099, 'currency' => 'EUR']]);
        $this->orderPayment->expects($this->never())->method($this->anything());

        $this->assertNotNull($this->updater->latePayment($this->order(Payment::CODE, 'PL61C53A8B97E6915A', '', $payment)));
        // Only the GET: the link is not expired (PATCH) for a cancelled order.
        $this->assertSame('GET', $this->sent[0][0]);
    }

    public function testAnotherMethodOrNoLinkIsNullWithoutAsking(): void
    {
        $this->api->expects($this->never())->method('send');
        $this->orderPayment->expects($this->never())->method($this->anything());

        $this->assertNull($this->updater->latePayment($this->order('zero_mollie')));
        $this->assertNull($this->updater->latePayment($this->order(Payment::CODE, '')));
    }

    public function testAnActiveLinkNeverCancelsTheOrder(): void
    {
        $this->api->expects($this->exactly(2))->method('send')
            ->willReturnOnConsecutiveCalls(['status' => 'active'], ['status' => 'paymentPending']);
        $this->orderPayment->method('settled')->willReturn(null);
        $this->orderPayment->expects($this->never())->method($this->logicalOr('cancel', 'capture', 'flag'));

        $this->assertSame(ReturnResult::NOT_STARTED, $this->updater->update($this->order()));
        $this->assertSame(ReturnResult::PENDING, $this->updater->update($this->order()));
    }
}
