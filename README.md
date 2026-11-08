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
* Line items (product/item ID, name, quantity, category, unit price), when provided
* Optional contact email from page hits or the webhook, used only to link an existing Mautic contact (contacts are never created from these requests)
* Links between orders and contacts, and optional attribution to Mautic emails
* Contact commerce fields (lifetime value, order count, last order date, last order total)
* Behaviour fields you define, such as spend on a category in the last 90 days
* Contact timeline entries and report data derived from those orders

### Access and erasure

* **Contact export** includes order history (order ID, totals, currency, source, location, date, attributed email, and line items) so you can fulfill access requests.
* **Deleting a contact** also deletes that contact's ecommerce orders and line items by default. Commerce fields on the contact are removed with the contact. Email revenue totals for those orders will drop after deletion.
* Alternatively, set **When a contact is deleted** on the General tab to **Keep their orders, anonymized**. Orders and line items then stay for revenue reporting without the link to the contact. The store's order ID is kept, so the store could still link an order back to a customer.
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
4. Choose what happens **When a contact is deleted**: delete their orders (default) or keep them anonymized. See [Access and erasure](#access-and-erasure).

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

When upgrading to 1.4.0, existing orders get an order date equal to the time they were recorded. Add the behaviours cron job described in [Behaviours](#behaviours) if you create behaviours.

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
| `order_date` | No | When the order was placed: ISO 8601 (for example `2026-03-01T10:15:00+01:00`) or a Unix timestamp. Defaults to the time Mautic receives it. Rejected if more than a day in the future. Orders placed more than a day before they arrive never trigger campaigns, so historical imports send nothing. |
| `status` | No | `paid` (default), `partially_refunded`, `refunded` or `cancelled`. Common store names are accepted too: `completed`, `processing` and `fulfilled` mean paid, `partially-refunded` and `partly_refunded` mean partly refunded, `canceled` and `voided` mean cancelled. Unknown values reject the order. |
| `refunded_total` | No | Amount refunded so far. Decides between partly and fully refunded; `refunded` without an amount refunds the full total. |
| `items` | No | Line items as a JSON array (or JSON string) with `item_id`, `item_name`, `qty`, and optional `category`, `unit_price` (or `price`) and `refunded_qty`. Without a unit price, the item counts as 0 in category and product spend. |

### Refunds and cancellations

Send the same order again, with the same `order_id` and `order_source`, when it is refunded or cancelled. Mautic then updates the existing order's status, refunded total and per-item refunded quantities (matched by `item_id`); everything else stays as first recorded. A plain repeat without status or refund changes is ignored. Updates never trigger campaigns.

All numbers are net of refunds: lifetime value, last order total, email revenue, behaviours and the purchase history condition. Cancelled and fully refunded orders do not count as orders and do not trigger **Places an order**. Reports show the original total next to the status, refunded total and net total.

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

### Testing the connection

Store connectors can check URL and secret with a signed `POST /ecommerce/ping` (any JSON body, for example `{"ping":true}`). A correct signature gets `200` with `{"ok":true,"version":"1.7.0","storefront_events":false}`. A wrong or missing signature, an unset secret or an unpublished plugin get `204` like every other endpoint, with the reason in Mautic's log.

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

## Base currency

Set **Money format locale** on the Formatting tab (for example `hu_HU` for "4 990 Ft", `de_DE` for "19,90 €") so amounts look right in emails, which are sent from cron without a language; empty uses Mautic's language. Forint, yen and similar currencies are shown without decimals.

Set **Base currency** on the plugin's Formatting tab (for example `HUF`; empty uses the default currency). Lifetime value, last order total, behaviours, the purchase history condition, lifecycle and RFM, and the dashboard widgets are then in base currency. Email revenue badges still show each currency separately.

Each order is converted at the rate of its order date, which is stored with the order. Rates come from:

1. **Manual exchange rates**, for example `EUR=395, USD=360` (base currency units per unit), which take precedence, or
2. **ECB reference rates**, when **Use ECB reference rates** is on: the European Central Bank's daily euro rates, fetched from ecb.europa.eu.

Orders without a known rate count at face value. Run daily, before the recalculation:

```bash
php bin/console mautic:ecommerce:exchange-rates
```

It fetches ECB rates (when enabled) and converts orders that have no rate yet. Use `--history` once to load ECB rates back to 1999 for older orders. After changing the base currency or the manual rates, run `--rebase` to convert every order again, then `mautic:ecommerce:recalculate`.

## Revenue reporting

Orders attributed to an email are also attributed to the campaign that sent it, taken from the email's send record (the click token's tracking hash when present, otherwise the contact's latest send of that email). Revenue keeps its campaign and email IDs if those are later deleted.

Dashboard widgets (**Dashboard → Add widget → Ecommerce**), for the dashboard's date range, by order date and net of refunds:

* **Ecommerce revenue over time**: revenue and number of orders
* **Ecommerce key figures**: revenue, orders, customers, average order value, repeat purchase rate (customers in the period with more than one order to date), average lifetime value of those customers, and revenue attributed to emails
* **Ecommerce revenue by campaign** and **Ecommerce revenue by email**: top campaigns and emails by revenue

The **Ecommerce orders** and **Ecommerce order line items** report sources include the campaign name.

## Campaigns

* **Decision: Places an order.** Runs when the contact places an order. Optional filters: minimum order total, categories, products (ID or name) and order sources, each comma-separated and case-insensitive. An order matches a category or product filter when at least one line item matches. It runs for the order's contact even for webhook and API orders without a browser visit. Orders placed more than a day before they reach Mautic do not trigger it.
* **Condition: Purchase history.** Compares the contact's spend, quantity bought or number of orders, all time or in the last N days by order date, optionally limited to categories, products or order sources.

Totals are compared as sent; amounts in different currencies are summed as-is. Refunds are not netted yet.

## Timing fields

Three contact fields describe each customer's buying rhythm, from orders that count (not cancelled or fully refunded):

* **Days since last order**
* **Average days between orders**, once a contact has orders on at least two different days
* **Expected next order**, the last order date plus that average

Use them for replenishment and "due to reorder" campaigns, for example a segment on **Expected next order** in the last 7 days and **Days since last order** greater than **Average days between orders**. They update on every order change and in the daily recalculation below. The eCommerce tab on the contact shows them.

## Lifecycle stage and RFM

**Lifecycle stage** is a select field with one of these values, checked in this order:

| Stage | Rule (defaults) |
|-------|-----------------|
| Lapsed | No order for more than 365 days |
| At risk | Overdue: days since the last order exceed 1.5 times the contact's average days between orders (and at least 30 days); with a single order, that order is older than 90 days |
| VIP | Net lifetime value in the top 10% of customers (from 10 customers), or at least 5 orders |
| New | One order |
| Active | Everyone else with orders |

Change the thresholds under **Lifecycle stages** on the plugin's Validation tab.

**RFM score** combines recency, frequency and monetary value, each scored 1 (lowest) to 5 (highest) by quintile across all customers, for example `545`. The single scores are also stored as **RFM recency**, **RFM frequency** and **RFM monetary**. The quintile cutoffs and the VIP spend cutoff are refreshed by the daily recalculation and reused when a single contact is rescored after an order.

Cancelled and fully refunded orders do not count; amounts are net of refunds. Contacts without counting orders have no stage or score.

## Behaviours

**Settings → Ecommerce behaviours** (requires the permission to manage custom fields) defines rolling purchase measures. Each behaviour keeps its own number contact field, for example `ecb_candle_spend_90d`, which you can use in segments, campaign conditions and email tokens like any contact field.

A behaviour measures spend, quantity bought or number of orders; optionally only for some categories, products or order sources; over all time or the last N days.

Values update for the contact on every new order, when contacts are merged, and for everyone when you save or recalculate a behaviour. For rolling windows and **Days since last order** to move on without new orders, run the recalculation daily from cron:

```bash
php bin/console mautic:ecommerce:recalculate
```

It updates lifetime value, order count, last order, the timing fields, lifecycle stages and RFM scores, and all published behaviours. A typical cron line:

```
15 3 * * * www-data php /path/to/mautic/bin/console mautic:ecommerce:exchange-rates && php /path/to/mautic/bin/console mautic:ecommerce:recalculate
``` `mautic:ecommerce:behaviours:update` (optionally with `--id=<behaviour ID>`) updates behaviours only. Deleting a behaviour deletes its contact field; this is refused while a segment uses the field.

## REST API

Under Mautic's API (`/api`, Basic Auth or OAuth, API enabled in the configuration). Reading needs the contacts "view others" permission, writing needs "edit others". Errors use Mautic's usual `{"errors": [...]}` format.

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/ecommerce/orders` | List orders, newest order date first. Query: `contact_id`, `source`, `since` (date), `start`, `limit` (max 200). Returns `total` and `orders`. |
| `GET` | `/api/ecommerce/orders/{id}` | One order with line items |
| `POST` | `/api/ecommerce/orders/new` | Create an order. Same fields as the webhook, plus `contact_id`. `email` links an existing contact only; contacts are never created. Returns `201`, or `409` if `order_id` + `order_source` already exists. Triggers campaigns like the webhook. |
| `PATCH` / `PUT` | `/api/ecommerce/orders/{id}/edit` | Change total, currency, location, order date, attributed email, contact (`contact_id` or `email`) and items. `PATCH` keeps fields you do not send; `PUT` replaces them. Order ID and source cannot change. Does not trigger campaigns. |
| `DELETE` | `/api/ecommerce/orders/{id}/delete` | Delete an order |
| `GET` | `/api/contacts/{id}/ecommerce/orders` | Orders of one contact (same query parameters for paging) |

Edits and deletes update the commerce fields and behaviours of the contacts involved.

## Product catalog

Mautic keeps a catalog of the store's products: ID, SKU, name, link, image, prices, categories, variation IDs and stock. Order lines sent without a category take the category of their product, matched by product ID (`items[].product_id`), SKU (`item_id`) or variation ID. Lines recorded before their product arrived are filled in when it does, and the contacts concerned are recalculated, so category behaviours and filters stay correct.

Store connectors push products signed like orders, to `POST /ecommerce/product`: one product, `{"store": "...", "products": [...]}` with up to 250, and deletions as `{"store": "...", "deleted": ["id", ...]}` or `"deleted": true` on a product.

| Field | Description |
|-------|-------------|
| `store` | Required. The same value the store sends as `order_source`, for example `woocommerce`, so order lines find their products. Must be allowed if **Allowed order sources** is set. |
| `id` | Required. The store's product ID (for variable products, the parent) |
| `name` | Required |
| `sku` | Optional |
| `categories` | List of names (or objects with `name`), or a comma-separated string |
| `category` | Optional main category; default the first of `categories` |
| `variation_ids` | IDs of the product's variations, so variation order lines find the product |
| `url`, `image_url` | http(s) links |
| `price`, `regular_price`, `sale_price`, `currency` | Prices; currency defaults to the default currency |
| `stock_status`, `stock_quantity` | `instock`, `outofstock` or `onbackorder`; quantity |
| `status`, `updated_at` | The store's product status; last change (ISO 8601 or Unix timestamp) |

```json
{"store":"woocommerce","products":[{"id":160,"sku":"DIF-REED","name":"Reed Diffuser","categories":["Diffusers","Gifts"],"variation_ids":[164,165],"url":"https://shop.example/product/reed-diffuser/","image_url":"https://shop.example/img/diffuser.jpg","price":"12990","stock_status":"instock"}]}
```

Shops with native webhooks can feed the catalog without a connector; see below.

## WooCommerce without a plugin

Orders and refunds can come straight from WooCommerce's built-in webhooks:

1. In WordPress, open **WooCommerce → Settings → Advanced → Webhooks → Add webhook**.
2. **Status**: Active. **Topic**: Order updated. **Delivery URL**: `https://your-mautic-site.com/ecommerce/webhook/woocommerce` (`/ecommerce/woocommerce` works too). **Secret**: the webhook secret from this plugin's settings. **API version**: WP REST API Integration v3.
3. Save. WooCommerce sends a ping, which Mautic accepts.

Add webhooks with the topics **Product created**, **Product updated** and **Product deleted** and the same URL and secret to keep the product catalog in sync; product categories then fill in order lines. Products enter the catalog when they are created or changed; a store connector can push the whole catalog at once.

Optionally add a webhook with the topic **Order created**; it changes nothing for paid orders, which always arrive through Order updated as well.

How WooCommerce orders map:

* Orders are recorded when they become **processing** or **completed**, with the paid date as order date; **pending**, **on-hold**, **failed** and drafts are skipped until paid.
* **refunded** and **cancelled** update the order's status; the `refunds` list sets the refunded total, so partial refunds are netted.
* Order number, total, currency, billing email (existing contacts only), shipping city as location, and line items (SKU, or the variation or product ID; product ID; name; quantity; unit price including tax) are taken over. Native webhooks cannot say which lines a refund covered; the store connector sends that. WooCommerce order webhooks carry no product categories; they come from the catalog.
* Order source is `woocommerce`. If **Allowed order sources** is set, include it.

The signature (`X-WC-Webhook-Signature`) is checked against the same webhook secret. Storefront events (cart, checkout, product views) are not available as WooCommerce webhooks; send them with mtc.js or the store connector.

## Storefront events

Viewed product, added to cart and started checkout, for **identified contacts only**. Switch on **Record storefront events** in the plugin settings first, and only when your store collects tracking consent. Events of unknown or anonymous visitors are ignored; contacts are never created. Events older than **Keep events for (days)** (default 90) are deleted by `mautic:maintenance:cleanup`, and all of a contact's events are deleted with the contact.

Send them signed, like orders, to `POST /ecommerce/event`: one event, or `{"events": [...]}` with up to 100.

| Field | Description |
|-------|-------------|
| `event` | `viewed_product`, `added_to_cart` or `started_checkout` (also accepted: `product_viewed`, `view_item`, `add_to_cart`, `cart_updated`, `checkout_started`, `begin_checkout`, `initiate_checkout`) |
| `email` | An existing contact's email |
| `stat` or `ct` | Instead of `email`, for guests who arrived from a Mautic email: the `ct` parameter of the email link as received (or just its `stat` tracking hash). The contact is the email's recipient; the contact ID inside `ct` is never used, as it can be changed. |
| `occurred_at` | Optional; ISO 8601 or Unix timestamp, default now |
| `source` | Optional, default `web` |
| `product` | For viewed_product: `id`, `name`, `category`, `price`, `url`, `image_url` (or flat `product_id`, `product_name`, ...) |
| `cart` | For cart and checkout events: `id`, `total`, `currency`, `url` (a link that restores the cart), and `items` with `item_id`, `name`, `qty`, `price`, `category`, `url`, `image_url` |
| `currency` | Optional 3-letter code |

```json
{"event":"added_to_cart","email":"jane@example.com","cart":{"id":"c-81","url":"https://shop.example/cart?restore=c-81","currency":"HUF","items":[{"item_id":"CND-LAV-200","name":"Lavender Candle 200g","qty":2,"price":"5990","url":"https://shop.example/p/lavender","image_url":"https://shop.example/img/lavender.jpg"}]}}
```

The same events can come from `POST /api/ecommerce/events/new` (with `contact_id` or `email`; `events` for a batch), or from `mtc.js` on store pages when page hit tracking is on and the visitor is an identified contact. Browser events are skipped when the browser sends Do Not Track or Global Privacy Control:

```js
mt('send', 'pageview', {
  ecommerce_event: 'viewed_product',
  product: JSON.stringify({id: 'CND-LAV-200', name: 'Lavender Candle 200g', category: 'Candles', price: '5990', url: location.href})
});
```

`GET /api/contacts/{id}/ecommerce/events` lists a contact's events.

### Abandonment campaigns

Campaign decisions for storefront events, each with optional filters for a minimum value (cart total, or product price for views), categories and products:

* **Views a product**, **Adds to cart**, **Starts checkout**: fire as the event arrives (not for events more than a day old).
* **Abandons cart**: the contact's last cart event is older than **Cart and checkout abandoned after** (default 60 minutes), with no checkout, cart change or order since.
* **Abandons checkout**: a started checkout older than that delay, with no cart change or order since.
* **Abandons browsing**: a product view older than **Browsing abandoned after** (default 120 minutes), with no other storefront activity or order since.

Abandonments fire once per event and need the detector in cron, every 5 to 15 minutes:

```
*/5 * * * * www-data php /path/to/mautic/bin/console mautic:ecommerce:abandonment
```

Events older than the delay plus 48 hours are skipped, so a first run sends nothing about old carts.

The condition **No order within** (N hours) stops a follow-up once the contact has bought, for example: Abandons cart → wait 1 day → No order within 24 hours → send the reminder.

Email tokens, from the contact's latest storefront events: `{ecommerce_cart}` (items with image, link, quantity and price, plus total), `{ecommerce_cart_total}`, `{ecommerce_cart_url}` (the store's restore-cart link), `{ecommerce_viewed_product}`, `{ecommerce_viewed_product_url}` and `{ecommerce_viewed_product_image}`.

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
* Report builder data sources: **Ecommerce orders** and **Ecommerce order line items** (with line totals)
* Campaign decision **Places an order** and condition **Purchase history**
* Behaviours: rolling spend, quantity or order count per category, product or source as contact fields
* REST API for orders
* Duplicate order protection via unique constraint on `order_id` + `order_source`
* Contact profile commerce fields (lifetime value, order count, last order date, last order total)
* Segment filters for ecommerce activity and commerce field values
* Automatic database migrations on plugin update
* Contact export includes ecommerce order history
* Deleting a contact deletes related ecommerce orders and line items, or keeps them anonymized

## Development

Roadmap status, code layout, conventions and next steps: [DEVELOPMENT.md](DEVELOPMENT.md).

Run plugin tests:

```bash
ddev exec php bin/phpunit -c app/phpunit.xml.dist plugins/EcommerceConnectorBundle/Tests
```

Or, from `docroot/app` of a Composer-based install with Mautic's dev dependencies, using the bundle's own configuration:

```bash
../../vendor/bin/phpunit -c ../plugins/EcommerceConnectorBundle/phpunit.xml.dist
```

That configuration forces `DB_NAME=mautic_test`. Functional tests reset the database, so never point them at a database you need. Put `DB_HOST`, `DB_PORT`, `DB_USER` and `DB_PASSWD` for the test database in the project root's `.env.test.local`. Clear `var/cache/test` after changing services or configuration.

## Disclaimer

This software is provided **as is**, without warranty of any kind, express or implied, including but not limited to the warranties of merchantability, fitness for a particular purpose, and noninfringement. In no event shall the authors or copyright holders be liable for any claim, damages, or other liability arising from use of this software.

You are solely responsible for how you deploy and use this plugin, including compliance with applicable laws and data protection obligations.

## Changelog

### 1.7.0
```
* Added: Product catalog (ID, SKU, name, link, image, prices, categories, variations, stock).
* Added: Signed POST /ecommerce/product for store connectors (single, batch, deletions).
* Added: WooCommerce product webhooks keep the catalog in sync.
* Added: Order lines store the product ID and take missing categories from the catalog, also for earlier orders.
* Added: POST /ecommerce/webhook/{platform} for native shop webhooks; /ecommerce/woocommerce kept.
* Added: Money format locale setting; whole-number currencies (HUF, JPY, …) without decimals.
* Added: Signed POST /ecommerce/ping for connection tests.
* Added: Storefront events identify guests from a Mautic email by the link's tracking hash (`ct` or `stat`).
* Changed: Supports Mautic 7.2; a failing campaign run is logged and no longer fails the webhook.
* Changed: An order's `ct` is attributed by its tracking hash (email and campaign of that send); a malformed `ct` no longer fails the order.
```

### 1.6.0
```
* Added: Storefront events (viewed product, added to cart, started checkout) for identified contacts, switched off by default.
* Added: Signed POST /ecommerce/event webhook, POST /api/ecommerce/events/new and mtc.js pageview events.
* Added: GET /api/contacts/{id}/ecommerce/events.
* Added: Event retention (default 90 days) through mautic:maintenance:cleanup; events are deleted with the contact and moved on merge.
* Added: Campaign decisions Views a product, Adds to cart, Starts checkout, Abandons cart, Abandons checkout and Abandons browsing.
* Added: mautic:ecommerce:abandonment, the detector for abandonment decisions.
* Added: Campaign condition No order within.
* Added: Email tokens for cart contents, cart total, restore-cart link and last viewed product.
* Added: POST /ecommerce/woocommerce for native WooCommerce order webhooks (orders, refunds, cancellations).
```

### 1.5.0
```
* Added: Order status (paid, partially_refunded, refunded, cancelled), refunded total and net total.
* Added: Refunded quantity per line item.
* Added: Re-sending an order with a status or refunds updates the existing order.
* Updated: Lifetime value, order count, last order, email revenue, behaviours and purchase history use net totals and skip cancelled and fully refunded orders.
* Updated: "Places an order" ignores orders that arrive cancelled or refunded, and compares the minimum against the net total.
* Added: Status and refunds on the contact tab, order details, timeline, export, API and reports.
* Added: Timing fields: days since last order, average days between orders, expected next order.
* Added: Lifecycle stage (new, active, VIP, at risk, lapsed) with configurable thresholds.
* Added: RFM score and its recency, frequency and monetary parts, by quintile.
* Added: mautic:ecommerce:recalculate, the daily job for timing, lifecycle, RFM and behaviours.
* Added: Campaign attribution for orders, from the attributed email's send; existing orders are attributed on upgrade.
* Added: Dashboard widgets: revenue over time, key figures, revenue by campaign, revenue by email.
* Added: Campaign column in the order and line item reports.
* Added: Base currency with manual or ECB exchange rates; orders store the rate of their order date.
* Added: mautic:ecommerce:exchange-rates to fetch rates and convert orders (--history, --rebase).
* Updated: The daily recalculation also refreshes lifetime value, order count and last order.
```

### 1.4.0
```
* Added: order_date on orders (ISO 8601 or Unix timestamp); existing orders use the time they were recorded.
* Added: unit_price on line items (unit_price or price).
* Added: Campaign decision "Places an order" with minimum total, category, product and source filters.
* Added: Campaign condition "Purchase history" (spend, quantity or orders, all time or last N days).
* Added: Orders placed more than a day before they reach Mautic never trigger campaigns.
* Added: Behaviours: rolling purchase measures kept in contact fields, with an admin page and the mautic:ecommerce:behaviours:update command.
* Added: REST API to list, get, create, edit and delete orders, and to list a contact's orders.
* Added: Report data source for order line items, with line totals.
* Added: Option to keep orders anonymized when a contact is deleted.
* Updated: Last order date, the contact tab, timeline and export use the order date.
* Fixed: Upgrades failed because the plugin's update hook ran before Mautic applied the plugin's migrations.
* Fixed: Commerce fields could not drop to zero or be cleared after orders were moved or deleted.
* Fixed: Contact field installer depended on the MAUTIC_TABLE_PREFIX environment variable.
```

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
