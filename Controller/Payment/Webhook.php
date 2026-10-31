<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Adyen\Controller\Payment;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\OrderFactory;
use Psr\Log\LoggerInterface;
use Zero\Adyen\Model\Api;
use Zero\Adyen\Model\Notification;
use Zero\Adyen\Model\Payment;
use Zero\Checkout\Model\RedirectPayment\Webhooks;

/**
 * Adyen's standard webhook (zero_adyen/payment/webhook, HMAC): each item's
 * signature is checked with the order's store key; a successful AUTHORISATION
 * marks the order paid (OrderUpdater, now or from the queue: Zero Checkout >
 * Payment Webhooks). Answers "[accepted]", or 401 on a bad signature.
 */
class Webhook implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RawFactory $rawFactory,
        private readonly OrderFactory $orderFactory,
        private readonly Api $api,
        private readonly Webhooks $webhooks,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return Raw
     */
    public function execute()
    {
        $result = $this->rawFactory->create();
        /** @var Http $request */
        $request = $this->request;
        $body = json_decode((string) $request->getContent(), true);
        foreach ((array) ($body['notificationItems'] ?? []) as $wrapper) {
            $item = (array) ($wrapper['NotificationRequestItem'] ?? []);
            // An array ("merchantReference":[…]) counts as missing: not ours, never a 500.
            $reference = is_scalar($item['merchantReference'] ?? null) ? (string) $item['merchantReference'] : '';
            $order = $reference === '' ? null : $this->orderFactory->create()->loadByIncrementId($reference);
            if (!$order || !$order->getId() || $order->getPayment()?->getMethod() !== Payment::CODE) {
                continue;
            }
            if (!Notification::isValid($item, $this->api->hmacKey((int) $order->getStoreId()))) {
                $this->logger->warning('Zero Adyen: webhook with a bad signature for order ' . $reference);

                return $result->setHttpResponseCode(401)->setContents('');
            }
            try {
                $this->webhooks->handle($order, $item);
            } catch (LocalizedException) {
                return $result->setHttpResponseCode(503)->setContents('');
            }
        }

        return $result->setHttpResponseCode(200)->setContents('[accepted]');
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Signed by Adyen instead (HMAC).
     *
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
