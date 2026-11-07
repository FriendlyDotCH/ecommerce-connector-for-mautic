# Development notes

Working notes for continuing development: where the roadmap stands, how the code is organised, how to test, and what to do next. User documentation is in [README.md](README.md).

Last updated: 7 November 2026.

## Where things stand

This is Friendly's fork of DogByteMarketing's Ecommerce Connector (`origin` = `FriendlyDotCH/ecommerce-connector-for-mautic`, `upstream` = `DogByteMarketing/ecommerce-connector-for-mautic`). Development follows the roadmap in the gap analysis "Mautic ecommerce: gap analysis and roadmap" (G1 to G21, five phases).

### Branches

The branches are stacked; each builds on the previous one. None is pushed yet.

| Branch | Version | Contents |
|--------|---------|----------|
| `main` | 1.3.0 | Upstream, unchanged (`origin/main`) |
| `feature/1.4.0` | 1.4.0 | Baseline from the gap analysis: order date, unit price, Places an order / Purchase history, behaviours, orders API, line items report, anonymize on delete, fixes |
| `feature/1.5.0` | 1.5.0 | Phase 1: G1 to G5 |
| `feature/1.6.0` | 1.6.0 | Phase 2: G6, G7, native WooCommerce order webhooks |
| `feature/1.7.0` | 1.7.0 | Phase 3 so far: product catalog, platform adapters, signed product endpoint |

Work continues on `feature/1.7.0`. Open decision: push the branches to `origin` as they are, or squash per version first.

### Roadmap status

| Gap | Status | Notes |
|-----|--------|-------|
| G1 Order status, refunds | Done (1.5.0) | Status, refunded total, net total, refunded qty. Re-sending an order applies refunds. |
| G2 Timing metrics | Done (1.5.0) | Days since last order, average gap, expected next order |
| G3 Lifecycle stage, RFM | Done (1.5.0) | Defaults agreed: lapsed 365 d, at risk 1.5x gap (min 30 d) or single order > 90 d, VIP top 10% or 5 orders |
| G4 Revenue per campaign, widgets | Done (1.5.0) | Campaign from the email stat; four dashboard widgets |
| G5 Base currency | Done (1.5.0) | Manual or ECB rates, rate fixed at order date |
| G6 Storefront events | Done (1.6.0) | Webhook, API, mtc.js; identified contacts only; off by default |
| G7 Abandonment | Done (1.6.0) | Six decisions, No order within, cart tokens, detector command |
| G8 WooCommerce connector | Partly | Mautic side done: native WooCommerce webhooks for orders, refunds and products (no store plugin), and the standard endpoints a connector uses. The store plugin itself is next. |
| G9 Product catalog | Partly (1.7.0) | Catalog table, WooCommerce product webhooks, signed `/ecommerce/product`, categories filled in on order lines. Not done: catalog page in Mautic, catalog API, Shopify/Magento adapters. |
| G10 to G21 | Not started | Phases 3 to 5 |

Deliberately out of scope (from the gap analysis): checkout and payments, courses and communities, apps, affiliates, reviews, WhatsApp, Instagram capture, AI assistants.

## Next steps

Agreed direction (7 November 2026): Mautic stays platform-neutral. Each shop platform gets a native-webhook adapter in Mautic (`Platform/`, WooCommerce done) for zero-install setups, plus a thin store connector that sends the standard formats to `/ecommerce/order`, `/ecommerce/event` and `/ecommerce/product`. Target platforms: WooCommerce, Shopify, Magento. The REST import from Mautic (option B) is dropped in favour of the connector pushing the catalog and order history.

1. **WooCommerce connector plugin (G8)**, in its own repository (name to be decided): settings for the Mautic URL and secret; orders on paid, refunds, cancellations; products on save and stock change, and a "sync everything" action for the catalog and past orders; storefront events from server-side hooks (`woocommerce_add_to_cart`, cart updates, checkout) for identified customers with consent; a restore-cart link; linking the shopper to the Mautic contact. Needs a WordPress + WooCommerce test install (Stenders staging, or a local one on the dev server).
2. **Shopify adapter** (`X-Shopify-Hmac-Sha256`, orders/refunds/products webhooks; category from product type or taxonomy) and a Shopify app for customer events; **Magento** needs an extension (no native webhooks in Open Source).
3. **Rest of Phase 3:** catalog page in Mautic, back-in-stock / price-drop / low-inventory triggers (G10), product blocks and tokens in emails (G11).

## How the code is organised

| Area | Main classes |
|------|--------------|
| Intake | `Controller/PublicController` (signed `/ecommerce/order`, `/ecommerce/event`, `/ecommerce/product`; native `/ecommerce/webhook/{platform}`), `EventSubscriber/TrackingSubscriber` (mtc.js), `Controller/Api/*` |
| Shop platforms | `Platform/StorePlatformInterface` (signature, webhook type, order and product mapping; tagged, collected by `PlatformRegistry`), `Platform/WooCommercePlatform`; `Platform/CatalogImporterInterface` for API pulls (no implementation yet) |
| Catalog | `Entity/EcommerceProduct(Repository)`, `Service/CatalogSync` (upsert, category fill-in, recalculation), `Service/ProductPayloadParser` |
| Parsing | `Service/OrderPayloadParser`, `Service/StorefrontEventParser`, `Service/WooCommerceOrderMapper` |
| Writing orders | `Service/EcommerceOrderTracker` (new orders; known orders go to `OrderManager::applyRefunds`), `Service/OrderManager` (edit, delete, refunds) |
| Computed contact fields | `Service/CommerceRecalculator` is the single entry point. It runs `CommerceMetricsCalculator` (lifetime value, order count, last order, timing), `LifecycleCalculator` (stage, RFM) and `BehaviourCalculator`. All write in bulk through `LeadColumnWriter`. |
| Queries | `Service/PurchaseHistoryQuery` (spend, qty, orders per contact; condition and behaviours), `Service/EcommerceAnalytics` (widgets) |
| Campaigns | `EventSubscriber/CampaignSubscriber` (all decisions and conditions), `Service/AbandonmentDetector` |
| Currency | `Service/ExchangeRateProvider`, `EcbRateFetcher`, `BaseCurrencyRebaser` |
| Settings | `Integration/Config` (getters with defaults), `Integration/EcommerceConnectorIntegration` (form), `Resources/views/Integration/config_form.html.twig` (renders each field by name: add new fields there) |

Commands: `mautic:ecommerce:recalculate` (daily), `mautic:ecommerce:exchange-rates` (daily, before recalculate), `mautic:ecommerce:abandonment` (every 5 minutes), `mautic:ecommerce:behaviours:update`.

## Conventions

* **Money:** queries sum `EcommerceOrder::baseNetSql()` (net of refunds, in base currency, face value without a rate) and filter on `EcommerceOrder::COUNTED_STATUSES`. Email revenue badges are the exception: per currency, `net_total`.
* **Computed fields** are written with direct SQL and fire no contact events. New ones go into `LeadCommerceFieldDefinition` (installed on plugin update) and one of the calculators.
* **Schema:** one migration class per version in `Migrations/`, idempotent through `isApplicable()`. Mirror every change in `Tests/Functional/InstallsEcommerceSchema.php`. Migrations only run when the plugin version changes.
* **Webhooks** always answer 204, also on rejection; the reason is logged as a warning to Mautic's log. Plugin services log to the `mautic` channel (bound in `Config/services.php`).
* **Contacts are never created** from orders or events; only existing contacts (email or ID) are linked.
* **Campaign triggers** from webhooks, API and cron set the contact as system contact around `RealTimeExecutioner::execute()` and reset it afterwards. Orders and events more than a day old never trigger.
* **Privacy:** storefront events are off by default, identified contacts only, DNT/GPC honoured for browser events, retention through maintenance cleanup.

## Testing

Tests need Mautic's dev dependencies (PHPUnit, Liip test bundles) and a separate test database. `phpunit.xml.dist` forces `DB_NAME=mautic_test` because functional tests reset the database. Put `DB_HOST`, `DB_PORT`, `DB_USER` and `DB_PASSWD` for the test database in the project root's `.env.test.local`.

From `docroot/app`:

```bash
rm -rf ../../var/cache/test   # the test container is not rebuilt automatically (APP_DEBUG=0)
../../vendor/bin/phpunit -c ../plugins/EcommerceConnectorBundle/phpunit.xml.dist
../../vendor/bin/phpunit -c ../plugins/EcommerceConnectorBundle/phpunit.xml.dist --testsuite Unit
```

Run the whole suite before every deploy. 169 tests passed on `feature/1.7.0` (7 November 2026). The WooCommerce tests use a realistic order fixture in `Tests/Fixtures/woocommerce-order.json`.

## Deploying to an install

1. Back up the `ecommerce_*`, `plugins` and `plugin_integration_settings` tables.
2. Replace the plugin files, then clear the cache **immediately**: while service constructors on disk differ from the cached container, requests fail.
3. Run `mautic:plugins:reload` (or Settings → Plugins → Install/Upgrade Plugins). Migrations run only when the version number changes.
4. Clear the cache again; run `mautic:ecommerce:exchange-rates` and `mautic:ecommerce:recalculate` once.
5. Cron: the three commands above, plus Mautic's own segment and campaign jobs.
