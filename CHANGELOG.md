# Changelog: zerofront/module-zero-adyen

## 1.3.6

- Returns and webhooks settle through Zero Checkout's new flow, under the order's lock.
- A payment for an order already cancelled is flagged, never booked; while such a payment is still in flight the webhook is not acknowledged, so Adyen sends it again.
- Pending orders can be rechecked by the recheck cron (off by default); the link is expired and the order cancelled only when nobody paid.
- Amounts follow Adyen's own currency table (CLP and ISK with two decimals, IDR and CVE none).
- A webhook with array-valued fields fails its signature check instead of causing a 500.

Earlier releases (1.3.5 and before): see the Git tags.
