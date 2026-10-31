<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Adyen\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Zero\Checkout\Model\Money\MinorUnits;
use Zero\Checkout\Model\RedirectPayment\LatePaymentInterface;
use Zero\Checkout\Model\RedirectPayment\OrderPayment;
use Zero\Checkout\Model\RedirectPayment\OrderUpdaterInterface;
use Zero\Checkout\Model\RedirectPayment\ReturnResult;
use Zero\Checkout\Model\RedirectPayment\WebhookHandlerInterface;

/**
 * Brings an order in line with Adyen, from two sides:
 * - update(): the payment link's status, asked from Adyen when the customer
 *   comes back: completed (paid), paymentPending, expired, active (nobody
 *   paid yet: NOT_STARTED);
 * - authorised(): the signed AUTHORISATION webhook, which also brings the
 *   payment's pspReference (refunds need it).
 * Whichever comes first marks the order paid; the other changes nothing.
 * update() cancels only on an expired link, never on "active", so the
 * recheck cron may ask it (RedirectPayment\Updaters). A cancelled order
 * paid anyway is reported by latePayment(), read-only.
 */
class OrderUpdater implements WebhookHandlerInterface, OrderUpdaterInterface, LatePaymentInterface
{
    public function __construct(
        private readonly Api $api,
        private readonly OrderPayment $orderPayment,
        private readonly OrderRepositoryInterface $orderRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function update(Order $order, array $params = []): string
    {
        $linkId = (string) $order->getPayment()?->getAdditionalInformation(Payment::LINK_ID);
        if ($order->getPayment()?->getMethod() !== Payment::CODE || $linkId === '') {
            return ReturnResult::PENDING;
        }
        $settled = $this->orderPayment->settled($order);
        if ($settled !== null) {
            return $settled;
        }
        $link = $this->api->send('GET', '/paymentLinks/' . rawurlencode($linkId), null, (int) $order->getStoreId());

        return match ((string) ($link['status'] ?? '')) {
            'completed' => $this->paid($order, (array) ($link['amount'] ?? []), $linkId),
            'paymentPending' => ReturnResult::PENDING,
            'expired' => $this->failed($order, 'expired'),
            'active' => ReturnResult::NOT_STARTED,
            default => ReturnResult::PENDING,
        };
    }

    /**
     * A signed AUTHORISATION webhook with success "true" for this order.
     */
    public function authorised(Order $order, array $item): void
    {
        $pspReference = is_scalar($item['pspReference'] ?? null) ? (string) $item['pspReference'] : '';
        $order->getPayment()->setAdditionalInformation(Payment::PSP_REFERENCE, $pspReference);
        if ($this->orderPayment->settled($order) === null) {
            $this->paid($order, (array) ($item['amount'] ?? []), $pspReference);
        } else {
            $this->orderRepository->save($order);
        }
    }

    /**
     * The verified webhook item (Controller\Payment\Webhook, now or from
     * the queue): a successful AUTHORISATION marks the order paid.
     *
     * @inheritDoc
     */
    public function webhook(Order $order, array $data): void
    {
        if (($data['eventCode'] ?? '') === 'AUTHORISATION' && ($data['success'] ?? '') === 'true') {
            $this->authorised($order, $data);
        }
    }

    /**
     * A cancelled order's payment link, asked from Adyen: completed (paid)
     * or not. Nothing on the order is changed. Webhooks::settle asks this
     * instead of running webhook() for a cancelled order, so a late
     * AUTHORISATION is flagged, never booked. While the link of a cancelled
     * order is paymentPending (an asynchronous payment in flight), the event
     * is not acknowledged (LocalizedException, the controller answers 503) so
     * that Adyen sends it again; an active link means nobody paid: null.
     *
     * @inheritDoc
     * @throws LocalizedException When Adyen doesn't answer, or the cancelled order's link is paymentPending
     */
    public function latePayment(Order $order): ?array
    {
        $linkId = (string) $order->getPayment()?->getAdditionalInformation(Payment::LINK_ID);
        if ($order->getPayment()?->getMethod() !== Payment::CODE || $linkId === '') {
            return null;
        }
        $link = $this->api->send('GET', '/paymentLinks/' . rawurlencode($linkId), null, (int) $order->getStoreId());
        $status = (string) ($link['status'] ?? '');
        if ($order->getState() === Order::STATE_CANCELED && $status === 'paymentPending') {
            throw new LocalizedException(
                __('Adyen has not settled the payment link of cancelled order #%1 yet.', $order->getIncrementId())
            );
        }
        if ($status !== 'completed') {
            return null;
        }
        $currency = (string) ($link['amount']['currency'] ?? '');

        return [
            'id' => (string) ($order->getPayment()->getAdditionalInformation(Payment::PSP_REFERENCE) ?: $linkId),
            'amount' => MinorUnits::format(
                MinorUnits::fromMinor((int) ($link['amount']['value'] ?? 0), $currency, MinorUnits::ADYEN),
                $currency,
                MinorUnits::ADYEN
            ),
            'currency' => $currency,
        ];
    }

    /**
     * Nobody paid: the link is expired (so it can't be paid later) and the
     * order cancelled.
     */
    public function abandon(Order $order): void
    {
        $linkId = (string) $order->getPayment()->getAdditionalInformation(Payment::LINK_ID);
        try {
            $this->api->send('PATCH', '/paymentLinks/' . rawurlencode($linkId), ['status' => 'expired'], (int) $order->getStoreId());
        } catch (LocalizedException) {
            // Already over: it expires on its own.
        }
        $this->failed($order, (string) __('the customer came back without paying'));
    }

    private function paid(Order $order, array $amount, string $transactionId): string
    {
        $expected = Payment::amount($order, (float) $order->getGrandTotal());
        if ((int) ($amount['value'] ?? -1) !== $expected['value'] || ($amount['currency'] ?? '') !== $expected['currency']) {
            $this->orderPayment->flag(
                $order,
                (string) __('Adyen reports a payment of a different amount; check it in the Adyen Customer Area.'),
                sprintf(
                    'Adyen: %s %s, expected %d %s',
                    $amount['value'] ?? '?',
                    $amount['currency'] ?? '?',
                    $expected['value'],
                    $expected['currency']
                )
            );

            return ReturnResult::PENDING;
        }
        $this->orderPayment->capture($order, $transactionId);

        return ReturnResult::PAID;
    }

    private function failed(Order $order, string $reason): string
    {
        $this->orderPayment->cancel($order, (string) __('Adyen payment did not go through: %1', $reason));

        return ReturnResult::FAILED;
    }
}
