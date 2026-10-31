# Zero_Adyen

Payment on Adyen's hosted page for Zero Checkout: a payment link for the
order; the customer pays with a card, a wallet or a local method switched on
in the Adyen account. No libraries: Adyen's Checkout API v71 over Magento's
HTTP client.

## Install

Requires Magento Open Source 2.4.7–2.4.9 and PHP 8.2–8.4 with the GD extension built with WebP (`php -r 'var_dump(function_exists("imagewebp"));'` says `true`; Magento's own PHP requirements cover the rest). Without WebP in GD, Zero skips its WebP image copies and serves the originals.

```bash
composer require zerofront/module-zero-adyen
bin/magento setup:upgrade
bin/magento setup:di:compile           # production mode
bin/magento setup:static-content:deploy # production mode
bin/magento cache:flush
```

Composer installs `zerofront/module-zero-core`, `zerofront/module-zero-checkout` with it.

Part of [`zerofront/zero`](https://zerofront.dev), which installs the Zero theme and every Zero module at once.

## How a payment goes

1. **Placing the order** creates a single-use payment link for the order's
   grand total in the order's currency (minor units by currency), valid for
   an hour. The order waits in **pending payment**; Zero Checkout sends the
   customer to the link.
2. **Adyen's webhook** (`zero_adyen/payment/webhook`, set up in the Customer
   Area as a Standard webhook with an HMAC key): each item's HMAC signature
   is checked. A successful AUTHORISATION for the order's amount and
   currency marks it **paid** and keeps the pspReference. A failed attempt
   changes nothing (the customer may try again on the same link).
3. **Back from Adyen** (`zero_adyen/payment/return`): the link's status is
   asked from Adyen: completed (paid), paymentPending, expired (cancelled),
   active (nobody paid: the link is expired, the order cancelled, the cart
   restored).
   Handled now or from the queue: Zero Checkout > Payment Webhooks
   (the Zero_Checkout README).

The account should **capture automatically** (Adyen's default): an
authorised payment counts as paid. **Refunds:** credit memos refund online
by the pspReference the webhook brought.

**Settlement** (Zero Checkout's redirect-payment flow): the return page, the
webhook and the recheck cron update the order under its lock, on a freshly
read order; while another process holds it the customer sees "processing". A
payment Adyen reports for an order already cancelled is not booked: the order
stays cancelled, with a comment and an admin notice (refund it at Adyen).
While a cancelled order's link is `paymentPending` (an asynchronous payment
in flight), its webhook events get a 503, so Adyen sends them again until the
link settles; an `active` link means nobody paid, and the event is accepted. The
updater is registered for Zero Checkout > Payment Webhooks > Recheck Pending
Payments (off by default), since it cancels only on an `expired` link, never
because nobody has paid yet.

## Settings

Stores > Configuration > Sales > Payment Methods > **Adyen (Zero)**:
Enabled, Title, **Merchant Account**, **API Key** (encrypted; an API
credential with the Checkout API), **Webhook HMAC Key** (encrypted),
**Test Mode** (on by default), **Live URL Prefix** (live only), countries,
sort order.

## Tests

`Test/Unit/Model/NotificationTest.php`: the webhook's HMAC signature, minor
units.

What changed in each release: [CHANGELOG.md](CHANGELOG.md).

## License

OSL-3.0 or AFL-3.0, your choice: see `NOTICE.md`.

---

<a href="https://vilmate.com"><picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/zerofrontdev/zero/main/docs/images/vilmate-logo-dark.svg">
  <img src="https://raw.githubusercontent.com/zerofrontdev/zero/main/docs/images/vilmate-logo.svg" alt="Vilmate" height="34" align="right">
</picture></a>

Zero is made by [Fixstacks](https://fixstacks.com) in partnership with [Vilmate](https://vilmate.com), a software development company.
Documentation: https://zerofront.dev/docs. Demo store: https://demo.zerofront.dev.
