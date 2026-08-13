# Mautic bundle for Ecommerce Connector plugin

Track eCommerce orders from your store and attribute revenue to Mautic emails.

## Screenshots

### Email revenue

Revenue attributed to each email appears on the email list and detail views.

![Email revenue badges on the Mautic email list](https://dogbytemarketing.com/mautic/email-revenue.jpg)

### Contact profile

The contact profile includes eCommerce stats and an eCommerce tab with order history, including attributed email names.

![Contact profile commerce stats and eCommerce order tab](https://dogbytemarketing.com/mautic/user-profile.jpg)

### Segment filters

Segment contacts by ecommerce activity and commerce field values (order count, lifetime value, last order date, and more).

![Ecommerce segment filters in the segment builder](https://dogbytemarketing.com/mautic/segment-filters.jpg)

## Requirements

- Mautic 7.0+
- PHP 8.2+

## GDPR and privacy

This plugin stores order and revenue data linked to Mautic contacts. That data may be personal data under the GDPR and similar privacy laws.

**Before you enable tracking, update your privacy policy** so it discloses that order, purchase, and revenue information may be sent to and stored in Mautic for marketing analytics, email attribution, segmentation, and contact profiling. Do not rely on this plugin’s settings alone; your public-facing privacy policy (and any other required notices) must reflect this processing.

You are also responsible for:

- Having a lawful basis for processing (for example consent or legitimate interests, as applicable in your jurisdiction)
- Obtaining consent where required before enabling order tracking
- Handling data subject requests (access, erasure, and related rights) for ecommerce data stored in Mautic
- Configuring retention and security in line with your policies and legal obligations

### Data this plugin may store

When orders are tracked, Mautic may store and display:

- Order identifiers, totals, currency, source, merchant, location, and order date
- Line items (product/item ID, name, quantity, category), when provided
- Links between orders and contacts, and optional attribution to Mautic emails
- Contact commerce fields (for example lifetime value, order count, last order date, last order total)
- Contact timeline entries and report data derived from those orders

A GDPR notice also appears in the plugin settings when you configure the integration.

## Installation

### 1. Add the plugin files

Copy the `EcommerceConnectorBundle` folder into your Mautic `plugins/` directory.

### 2. Install or upgrade the plugin (Settings → Plugins)

1. Log in to Mautic as an administrator.
2. Open **Settings** (gear icon) → **Plugins**.
3. Click **Install/Upgrade Plugins** in the top toolbar.
   - Mautic scans the `plugins/` directory and installs any new or updated plugins.
   - When upgrading from an older version, this also triggers database migrations automatically.

You should see **Ecommerce Connector** appear in the plugin grid after installation.

### 3. Configure the integration (three settings tabs)

Click the **Ecommerce Connector** plugin tile to open the integration settings modal. Configuration is split across three tabs.

#### Tab 1: Enabled/Auth

This tab controls whether the plugin is active and holds your webhook credentials.

1. Set **Active** to **Yes**.
   - Webhook requests are rejected while the integration is inactive.
   - Before enabling, update your privacy policy and confirm you have a lawful basis for processing. See [GDPR and privacy](#gdpr-and-privacy).
2. Enter a **Webhook secret**.
   - Use a long, random string. Your store uses this to sign webhook requests.
   - This field is required for server-side order tracking.
3. Note the **Webhook URL** shown in the info box below the form.
   - Example: `https://your-mautic-site.com/mtc/ecommerce`
   - Use this URL in your eCommerce platform or custom integration.

Click **Save** or **Save & Close** before continuing, or configure the Features tab first and save once at the end.

#### Tab 2: Features

This tab controls how orders are validated and attributed.

| Setting | Recommended | Description |
|---------|-------------|-------------|
| Track orders from page hits | Yes (for simple setups) | Record orders from thank-you page URL query parameters |
| Attribute revenue to last email sent | No | When no email is provided, link the order to the most recently sent email. Can misattribute revenue. |
| Allowed order sources | Leave blank | Comma-separated list (e.g. `web,woocommerce,shopify`). Blank allows any source. |
| Default currency | `USD` | Used when `order_currency` is not sent in the payload |
| Maximum order total | `1000000` | Orders above this amount are rejected |

Click **Save & Close** when finished.

#### Tab 3: Installation

Step-by-step setup instructions for connecting your store:

- **WordPress / WooCommerce:** use the [Sync Mautic](https://wordpress.org/plugins/sync-mautic/) plugin with WP Mautic and this connector
- **Custom stores:** signed webhook integration guide with example PHP and cURL requests
- Contact information if you need a custom integration built for you

### 4. Connect your store

Configure your eCommerce platform to send signed `POST` requests to the webhook URL from step 3. See [Webhook tracking](#webhook-tracking) below for payload and signing details.

For a quick test without a store integration, enable **Track orders from page hits** and send visitors to a thank-you page with order query parameters. See [Page hit tracking](#page-hit-tracking).

## Upgrading

When deploying a new plugin version:

1. Replace the files in `plugins/EcommerceConnectorBundle/`.
2. Open **Settings → Plugins**.
3. Click **Install/Upgrade Plugins**.

Mautic compares the version in `Config/config.php` with the installed version and runs database migrations automatically when needed.

## Webhook tracking

Send a signed `POST` request to:

```
/mtc/ecommerce
```

### Parameters

| Parameter | Required | Description |
|-----------|----------|-------------|
| `order_id` | Yes | Unique order ID from your store |
| `order_total` | Yes | Order total (must be greater than zero) |
| `order_source` | No | Source identifier (default: `web`) |
| `order_currency` | No | 3-letter ISO 4217 code (default: configured default currency) |
| `email_id` | No | Mautic email ID to attribute revenue to |
| `ct` | No | Mautic clickthrough token for contact and email attribution |

### Signing requests

Sort parameters alphabetically, build a query string, then sign with HMAC SHA-256 using your webhook secret. Send the result in the `X-Mautic-Ecommerce-Signature` header.

```php
$params = [
    'order_id'       => '12345',
    'order_total'    => '99.99',
    'order_source'   => 'woocommerce',
    'order_currency' => 'USD',
];
ksort($params);
$signature = hash_hmac('sha256', http_build_query($params), 'YOUR_WEBHOOK_SECRET');
```

### Example (cURL)

```bash
curl -X POST "https://your-mautic-site.com/mtc/ecommerce" \
  -H "X-Mautic-Ecommerce-Signature: YOUR_SIGNATURE" \
  -d "order_id=12345" \
  -d "order_total=99.99" \
  -d "order_source=woocommerce" \
  -d "order_currency=USD"
```

## Page hit tracking

When **Track orders from page hits** is enabled, orders can also be recorded from landing page query parameters (for example on a thank-you page). Server-side webhooks are recommended for production stores.

Example thank-you page URL:

```
https://your-mautic-site.com/thank-you?order_id=12345&order_total=99.99&order_source=web
```

## Features

- Revenue badges on the email list and email detail views (currency symbols such as `$0.00`, grouped by currency when multiple currencies exist)
- Contact timeline entries for recorded orders
- Report builder data source: **Ecommerce orders**
- Duplicate order protection via unique constraint on `order_id` + `order_source`
- Contact profile commerce fields (lifetime value, order count, last order date, last order total)
- Segment filters for ecommerce activity and commerce field values
- Automatic database migrations on plugin update

## Development

Run plugin tests:

```bash
ddev exec php bin/phpunit -c app/phpunit.xml.dist plugins/EcommerceConnectorBundle/Tests
```

## Changelog

### 1.3.0
```
- Added: Line items on tracked orders (item ID, name, qty, category).
- Added: Merchant and location on tracked orders.
- Added: Contact eCommerce tab columns for merchant and location.
- Added: Line items shown under each order on the contact eCommerce tab.
- Added: Segment filters for purchased product ID, name, and category.
- Added: EMPTY and NOT_EMPTY operators on eCommerce text segment filters.
- Added: Support for zero and missing order totals on webhook payloads.
- Updated: Plugin upgrades now apply schema via migrations instead of reinstalling schema on update.
```

### 1.2.0
```
- Added: Contact profile eCommerce fields (lifetime value, order count, last order date, last order total)
- Added: Automatic eCommerce field updates when orders are recorded
- Added: Contact profile eCommerce tab with commerce stats and order history
- Added: Segment filters for ecommerce orders (has order, order total, order date, order source)
- Updated: Settings organization into General, Validation, Formatting, Webhook, Installation, and Support tabs
```

### 1.1.1
```
- Bugfix: composer.json
```

### 1.1.0

```
- Added: Webhook HMAC signature validation (X-Mautic-Ecommerce-Signature)
- Added: Plugin integration settings (webhook secret, page hit tracking, allowed sources, default currency, max order total)
- Added: Shared order payload parser with amount, currency, and source validation
- Added: Revenue display grouped by currency on email stats using currency symbols (for example $0.00)
- Added: Contact timeline entries for orders
- Added: Report builder data source for ecommerce orders
- Added: Installation tab with WordPress Sync Mautic and custom signed-webhook setup guides
- Added: Plugin config notices for webhook URL and GDPR compliance
- Added: Duplicate order race condition handling
- Added: Unit and functional tests
- Added: Translations for plugin UI strings
- Added: Verified order-to-contact tracking via Mautic clickthrough token (ct)
- Updated: order_total storage from float to decimal (19,4)
- Updated: Last-email attribution disabled by default (configurable)
- Updated: Revenue badge to show $0.00 (or configured default currency symbol) when active with no orders yet
- Updated: Integration settings UI with compact stacked notice boxes
```

### 1.0.0

```
- Added: Initial release
- Added: Track orders via webhook endpoint (POST /mtc/ecommerce)
- Added: Track orders from page hit query parameters
- Added: Store orders linked to contacts and emails
- Added: Display revenue on email list stats
- Added: Plugin schema install on first install
```
