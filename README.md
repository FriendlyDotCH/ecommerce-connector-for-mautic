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

* Mautic 7.0+
* PHP 8.2+

## GDPR and privacy

This plugin stores order and revenue data linked to Mautic contacts. That data may be personal data under the GDPR and similar privacy laws.

Cookies are not the threshold. The signed webhook is server-to-server and does not set cookies, but it still processes personal data. Page-hit tracking uses Mautic's existing visitor identification, which typically involves cookies.

Page-hit orders are read from the `mtc.js` POST to `/mtc/event` (and from `/mtracking.gif`). Those orders can be stored even when Mautic does not save a page hit, including when the browser sends Do Not Track (DNT) or Global Privacy Control (GPC). Page hits may include a contact email used only to link an existing Mautic contact.

**Before you enable tracking, update your privacy policy** so it discloses that order, purchase, and revenue information may be sent to and stored in Mautic for marketing analytics, email attribution, segmentation, and contact profiling. Do not rely on this plugin’s settings alone; your public-facing privacy policy (and any other required notices) must reflect this processing.

You are also responsible for:

* Having a lawful basis for processing (for example consent or legitimate interests, as applicable in your jurisdiction)
* Obtaining consent where required before enabling order tracking
* Handling data subject requests (access, erasure, and related rights) for ecommerce data stored in Mautic
* Configuring retention and security in line with your policies and legal obligations

### Data this plugin may store

When orders are tracked, Mautic may store and display:

* Order identifiers, totals, currency, source, location, and order date
* Line items (product/item ID, name, quantity, category), when provided
* Optional contact email from page hits or the webhook, used only to link an existing Mautic contact (contacts are never created from these requests)
* Links between orders and contacts, and optional attribution to Mautic emails
* Contact commerce fields (lifetime value, order count, last order date, last order total)
* Contact timeline entries and report data derived from those orders

### Access and erasure

* **Contact export** includes order history (order ID, totals, currency, source, location, date, attributed email, and line items) so you can fulfill access requests.
* **Deleting a contact** also deletes that contact's ecommerce orders and line items. Commerce fields on the contact are removed with the contact. Email revenue totals for those orders will drop after deletion.
* Merging contacts moves ecommerce orders to the remaining contact.

You are solely responsible for any data protection measures required for your use of this integration.

## Installation

### 1. Back up before install

Take a backup of your Mautic database and files before you install. The plugin adds database tables and contact fields. A backup lets you restore Mautic if something goes wrong.

### 2. Add the plugin files

Copy the `EcommerceConnectorBundle` folder into your Mautic `plugins/` directory.

### 3. Install the plugin (Settings → Plugins)

1. Log in to Mautic as an administrator.
2. Open **Settings** (gear icon) → **Plugins**.
3. Click **Install/Upgrade Plugins** in the top toolbar.
   * Mautic scans the `plugins/` directory and installs any new plugins.

You should see **Ecommerce Connector** appear in the plugin grid after installation.

### 4. Configure the integration

Click the **Ecommerce Connector** plugin tile to open the integration settings modal. Configuration is split across six tabs: General, Validation, Formatting, Webhook, Installation, and Support.

#### General

This tab controls whether the plugin is active and how orders are attributed.

1. Set **Active** to **Yes**.
   * Webhook requests are rejected while the integration is inactive.
   * Before enabling, update your privacy policy and confirm you have a lawful basis for processing. See [GDPR and privacy](#gdpr-and-privacy).
2. Optionally enable **Track orders from page hits** to record orders from `mtc.js` pageviews (POST fields or thank-you page query parameters). Recommended for simple setups.
3. Leave **Attribute revenue to last email sent** off unless you accept possible misattribution. When no email is provided, this links the order to the most recently sent email.

#### Validation

| Setting | Recommended | Description |
|---------|-------------|-------------|
| Allowed order sources | `WooCommerce` for Sync Mautic | Comma-separated list (e.g. `web,WooCommerce,shopify`). For Sync Mautic, set this to `WooCommerce`. Blank allows any source. |
| Maximum order total | `1000000` | Orders above this amount are rejected |

#### Formatting

| Setting | Recommended | Description |
|---------|-------------|-------------|
| Default currency | `USD` | Used when `order_currency` is not sent in the payload |

#### Webhook

This tab holds your webhook credentials. The signed webhook is server-to-server and does not set cookies, but it still processes personal data.

1. Enter a **Webhook secret**.
   * Use a long, random string. Your store uses this to sign webhook requests.
   * This field is required for server-side order tracking.
2. Note the **Webhook URL** shown in the info box below the form.
   * Example: `https://your-mautic-site.com/ecommerce/order`
   * Use this URL in your eCommerce platform or custom integration.

#### Installation

Step-by-step setup instructions for connecting your store:

* **WordPress / WooCommerce:** use the [Sync Mautic](https://wordpress.org/plugins/sync-mautic/) plugin with WP Mautic and this connector. Set **Allowed order sources** on the Validation tab to `WooCommerce`.
* **Custom stores:** signed webhook integration guide with example PHP and cURL requests

#### Support

Contact information if you need help or a custom integration built for you.

Click **Save** or **Save & Close** when finished, or save once after configuring every tab you need.

### 5. Connect your store

Configure your eCommerce platform to send signed `POST` requests to the webhook URL from step 4. See [Webhook tracking](#webhook-tracking) below for payload and signing details.

For a quick test without a store integration, enable **Track orders from page hits** and send visitors to a thank-you page with order fields on the pageview or in the URL. See [Page hit tracking](#page-hit-tracking).

## Upgrade

Use these steps when replacing an older Ecommerce Connector version with a newer one. Back up first, then use either [Replace the plugin files](#2-replace-the-plugin-files) or [Update with Composer](#3-update-with-composer), then run [Install/Upgrade Plugins](#4-run-installupgrade-plugins).

### 1. Back up before upgrade

Take a backup of your Mautic database and files before you upgrade. Upgrades can run database migrations. A backup lets you restore Mautic if something goes wrong.

### 2. Replace the plugin files

Replace the existing `plugins/EcommerceConnectorBundle/` folder with the new plugin files. Keep the folder name `EcommerceConnectorBundle`.

Do not delete the plugin from **Settings → Plugins** first. That is an uninstall, not an upgrade.

### 3. Update with Composer

If you installed the plugin with Composer, update it from your Mautic root directory:

```bash
composer update dogbytemarketing/ecommerce-connector-for-mautic
```

Composer installs the plugin as `plugins/EcommerceConnectorBundle/`. After Composer finishes, continue with the step below.

### 4. Run Install/Upgrade Plugins

1. Log in to Mautic as an administrator.
2. Open **Settings** (gear icon) → **Plugins**.
3. Click **Install/Upgrade Plugins** in the top toolbar.

Mautic compares the version in the plugin files with the installed version and runs database migrations automatically when needed. Your webhook secret and other integration settings are kept.

When upgrading from a version before 1.3.0, the integration is turned off automatically so you can update your privacy policy. Admins see a dashboard notice and a Mautic notification. Dismissing the notice does not turn tracking back on. After you update your privacy policy, open the plugin tile, set **Active** to **Yes**, and save.

After the upgrade, confirm **Ecommerce Connector** still appears in the plugin grid, then open the plugin tile if you need to review settings. If you use Sync Mautic, open the Validation tab and set **Allowed order sources** to `WooCommerce`.

## Webhook tracking

Send a signed `POST` request to:

```
/ecommerce/order
```

The previous path `/mtc/ecommerce` is still accepted as a legacy alias to the same handler.

### Parameters

| Parameter | Required | Description |
|-----------|----------|-------------|
| `order_id` | Yes | Unique order ID from your store |
| `order_total` | No | Order total. Missing or empty values default to `0`. Must be zero or greater and within the configured maximum. |
| `order_source` | No | Source identifier (default: `web`). Sync Mautic sends `WooCommerce`. |
| `order_currency` | No | 3-letter ISO 4217 code (default: configured default currency) |
| `email` | No | Contact email. The order is linked only if this email already exists in Mautic. Contacts are never created from the webhook. |
| `email_id` | No | Mautic email ID to attribute revenue to |
| `ct` | No | Mautic clickthrough token for email attribution |
| `location` | No | Store location label stored on the order |
| `items` | No | Line items as a JSON array (or JSON string) with `item_id`, `item_name`, `qty`, and optional `category` |

### Signing requests

**JSON body (`Content-Type: application/json`):** HMAC-SHA256 the exact raw request body using your webhook secret.

```php
$body = '{"order_id":"12345","order_total":"99.99","order_source":"woocommerce"}';
$signature = hash_hmac('sha256', $body, 'YOUR_WEBHOOK_SECRET');
```

**Form body (`application/x-www-form-urlencoded`):** Sort parameters alphabetically, build a query string, then sign.

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

Send the result in the `X-Mautic-Ecommerce-Signature` header.

### Example (JSON)

```bash
BODY='{"order_id":"12345","order_total":"99.99","order_source":"woocommerce","order_currency":"USD"}'
SIG=$(php -r "echo hash_hmac('sha256', getenv('BODY'), 'YOUR_WEBHOOK_SECRET');")

curl -X POST "https://your-mautic-site.com/ecommerce/order" \
  -H "Content-Type: application/json" \
  -H "X-Mautic-Ecommerce-Signature: $SIG" \
  -d "$BODY"
```

### Example (form)

```bash
curl -X POST "https://your-mautic-site.com/ecommerce/order" \
  -H "X-Mautic-Ecommerce-Signature: YOUR_SIGNATURE" \
  -d "order_id=12345" \
  -d "order_total=99.99" \
  -d "order_source=woocommerce" \
  -d "order_currency=USD"
```

## Page hit tracking

When **Track orders from page hits** is enabled, orders can be recorded from Mautic tracking requests on a thank-you page:

* Query parameters on a tracked landing page URL
* Fields posted by `mtc.js` to `/mtc/event` (or `/mtracking.gif`)

The same order fields are accepted as the webhook (`order_id`, `order_total`, `order_source`, `order_currency`, `email`, `items`, and related attribution fields). `email` links the order only if that contact already exists in Mautic. Contacts are never created from page hits.

Orders from these tracking requests can be stored even when Mautic does not save a page hit, including when the visitor is a logged-in Mautic user or the browser sends DNT or GPC.

Server-side webhooks are recommended for production stores.

Example thank-you page URL:

```
https://your-mautic-site.com/thank-you?order_id=12345&order_total=99.99&order_source=web
```

Example `mtc.js` pageview fields:

```
mt('send', 'pageview', {
  order_id: '12345',
  order_total: '99.99',
  order_source: 'web',
  email: 'existing@example.com'
});
```

## Features

* Revenue badges on the email list and email detail views (currency symbols such as `$0.00`, grouped by currency when multiple currencies exist)
* Contact timeline entries for recorded orders
* Report builder data source: **Ecommerce orders**
* Duplicate order protection via unique constraint on `order_id` + `order_source`
* Contact profile commerce fields (lifetime value, order count, last order date, last order total)
* Segment filters for ecommerce activity and commerce field values
* Automatic database migrations on plugin update
* Contact export includes ecommerce order history
* Deleting a contact also deletes related ecommerce orders and line items

## Development

Run plugin tests:

```bash
ddev exec php bin/phpunit -c app/phpunit.xml.dist plugins/EcommerceConnectorBundle/Tests
```

## Disclaimer

This software is provided **as is**, without warranty of any kind, express or implied, including but not limited to the warranties of merchantability, fitness for a particular purpose, and noninfringement. In no event shall the authors or copyright holders be liable for any claim, damages, or other liability arising from use of this software.

You are solely responsible for how you deploy and use this plugin, including compliance with applicable laws and data protection obligations.

## Changelog

### 1.3.0
```
* Added: Line items on tracked orders (item ID, name, qty, category).
* Added: Location on tracked orders.
* Added: Contact eCommerce tab columns for location.
* Added: Line items shown under each order on the contact eCommerce tab.
* Added: Segment filters for purchased product ID, name, and category.
* Added: EMPTY and NOT_EMPTY operators on eCommerce text segment filters.
* Added: Support for zero and missing order totals on webhook payloads.
* Added: Order details modal on the contact eCommerce tab (click a row to view details and line items)
* Added: Order details modal from the contact timeline
* Added: Total revenue column on the contacts list
* Added: JSON webhook body support with HMAC signing of the raw request body
* Added: Optional webhook email field to link orders to an existing contact only (never creates contacts)
* Added: Upgrades from versions before 1.3.0 disable the integration and notify admins so the privacy policy can be reviewed before tracking is turned back on.
* Added: Dismissable admin notice listing ecommerce data stored for contacts.
* Added: Webhook tab notice that server-to-server tracking still processes personal data.
* Added: Order history (including line items) to contact export.
* Added: Contact deletion now removes related ecommerce orders and line items.
* Added: Backup steps before install and upgrade.
* Updated: Upgrade backup notice to mention database migrations.
* Updated: Installation and upgrade docs, Installation tab, and Allowed order sources tooltip to set the source to WooCommerce for Sync Mautic.
* Updated: Webhook URL from /mtc/ecommerce to /ecommerce/order
* Updated: Contact eCommerce tab table to a simpler order summary (details moved into the modal)
* Updated: Location labels display with cleaned formatting
* Updated: Plugin upgrades now apply schema via migrations instead of reinstalling schema on update.
* Updated: GDPR documentation with cookies vs webhook processing, access, and erasure.
* Updated: GDPR documentation for page-hit billing email and orders recorded from /mtc/event when Mautic does not save a page hit.
* Updated: Page hit tracking docs to cover mtc.js POST fields, optional email, and DNT/GPC.
* Updated: Plugin GDPR notice, page-hit tooltip, installation copy, and upgrade notice data list for page-hit email matching and orders recorded when Mautic does not save a page hit.
* Bugfix: Non-existent service error from 7.2.
```

### 1.2.0
```
* Added: Contact profile eCommerce fields (lifetime value, order count, last order date, last order total)
* Added: Automatic eCommerce field updates when orders are recorded
* Added: Contact profile eCommerce tab with commerce stats and order history
* Added: Segment filters for ecommerce orders (has order, order total, order date, order source)
* Updated: Settings organization into General, Validation, Formatting, Webhook, Installation, and Support tabs
```

### 1.1.1
```
* Bugfix: composer.json
```

### 1.1.0

```
* Added: Webhook HMAC signature validation (X-Mautic-Ecommerce-Signature)
* Added: Plugin integration settings (webhook secret, page hit tracking, allowed sources, default currency, max order total)
* Added: Shared order payload parser with amount, currency, and source validation
* Added: Revenue display grouped by currency on email stats using currency symbols (for example $0.00)
* Added: Contact timeline entries for orders
* Added: Report builder data source for ecommerce orders
* Added: Installation tab with WordPress Sync Mautic and custom signed-webhook setup guides
* Added: Plugin config notices for webhook URL and GDPR compliance
* Added: Duplicate order race condition handling
* Added: Unit and functional tests
* Added: Translations for plugin UI strings
* Added: Verified order-to-contact tracking via Mautic clickthrough token (ct)
* Updated: order_total storage from float to decimal (19,4)
* Updated: Last-email attribution disabled by default (configurable)
* Updated: Revenue badge to show $0.00 (or configured default currency symbol) when active with no orders yet
* Updated: Integration settings UI with compact stacked notice boxes
```

### 1.0.0

```
* Added: Initial release
* Added: Track orders via webhook endpoint (POST /mtc/ecommerce)
* Added: Track orders from page hit query parameters
* Added: Store orders linked to contacts and emails
* Added: Display revenue on email list stats
* Added: Plugin schema install on first install
```
