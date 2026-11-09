# Development notes

Working notes for continuing development: where the roadmap stands, how the code is organised, how to test, and what to do next. User documentation is in [README.md](README.md).

Last updated: 9 November 2026.

## Where things stand

This is Friendly's fork of DogByteMarketing's Ecommerce Connector (`origin` = `FriendlyDotCH/ecommerce-connector-for-mautic`, `upstream` = `DogByteMarketing/ecommerce-connector-for-mautic`). Development follows the roadmap in the gap analysis "Mautic ecommerce: gap analysis and roadmap" (G1 to G21, five phases).

### Branches

The branches are stacked; each builds on the previous one. All are pushed to `origin` as they are, not squashed (8 November 2026).

| Branch | Version | Contents |
|--------|---------|----------|
| `main` | 1.3.0 | Upstream, unchanged (`origin/main`) |
| `feature/1.4.0` | 1.4.0 | Baseline from the gap analysis: order date, unit price, Places an order / Purchase history, behaviours, orders API, line items report, anonymize on delete, fixes |
| `feature/1.5.0` | 1.5.0 | Phase 1: G1 to G5 |
| `feature/1.6.0` | 1.6.0 | Phase 2: G6, G7, native WooCommerce order webhooks |
| `feature/1.8.0` | 1.8.4 | Unique coupons (G13), cross-sell groups (G12), order items and shopping activity on the contact, combined product alerts |
| `feature/1.9.0` | 1.12.0 | G8/G9: catalog REST API; Products block in the email builder; new arrivals; Shop opportunities page |
| `feature/1.7.0` | 1.7.1 | Phase 3: product catalog and catalog page, platform adapters, signed product endpoint, product alerts (G10), product blocks and tokens (G11) |

Work continues on `feature/1.9.0` (branched from `feature/1.8.0`, itself from `feature/1.7.0`), which runs on Mautic 7.2.2 (see "Mautic 7.2 compatibility" below).

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
| G8 WooCommerce connector | Mostly | Native WooCommerce webhooks (no store plugin), and the store plugin 0.5.1 (see Next steps). |
| G9 Product catalog | Partly (1.7.0) | Catalog table, WooCommerce and Shopify product webhooks, signed `/ecommerce/product`, categories filled in on order lines. Catalog page (Components → Shop catalog) done in 1.7.1. Catalog REST API (`ProductApiController`, batch logic shared with `/ecommerce/product` in `ProductBatchImporter`) done in 1.9.0. Magento off the list for now (decided 8 November 2026). |
| G10 Stock and price triggers | Done (1.7.1) | Back in stock, price drop, low inventory decisions; changes detected on product upsert (`ProductChangeDetector`), queued in `ecommerce_product_changes`, fired by `mautic:ecommerce:product-alerts`; audience per decision (viewed / carted / bought within N days); once per contact, product and alert in 7 days (`ecommerce_product_alerts`) |
| G11 Product content in emails | Done (1.7.1) | `{ecommerce_products source=recommended\|best_sellers\|new_arrivals\|viewed\|last_purchased}` written into the content before link tracking, so links are tracked; alert product tokens. Recommended = best sellers in the contact's categories not bought yet (no co-purchase model; that is G12). `new_arrivals` (1.9.2) sorts by the store's created date (`date_created`), else `date_added` |
| G13 Unique coupons | Done (1.8.0) | Issue coupon action (batch, `PendingEvent`; never passes without a coupon: shop down = retry in 15 min, refusal = retry hourly; Mautic retries without limit), code made in Mautic, created by the connector's signed `/wp-json/mautic-ecommerce/v1/coupon` (opt-in, timestamped); `ecommerce_coupons`; redeemed from the order's `coupons`; Coupon used condition; tokens. Not done: Shopify (needs an app) |
| G12 Cross-sell groups | Done (1.8.1) | `CrossSellCalculator`: category co-purchase by customer (support 5, lift > 1, best confidence); fields `ecommerce_cross_sell_category` / `_from`; model cached from the nightly run, contact rescored after orders; opt-in auto-segments (`CrossSellSegments`, created once, never changed); block source `cross_sell`. Product-level pairs not done (categories are more robust on small shops) |
| Opportunities page | Done (1.10.0) | `Service/OpportunityFinder` (cross-sell, due to reorder, second order, big spenders slipping; all from computed contact fields, each also a segment filter list), `Service/OpportunitySegments` (alias `ecommerce-opportunity-…`, created once), `Controller/OpportunityController` (Components → Shop opportunities; segment creation ajax-only for the CSRF header). 1.11.0 added interested-not-bought (`Service/InterestCalculator`, also run after each storefront event from `BehaviourSubscriber`) and lapsed category (`Service/CategoryLapseCalculator`), each with computed fields. Demo data: 60 contacts / 100 orders / 384 storefront events, see `/var/www/html/mautic/git/demo-data/` on the dev server |
| G14 to G21 | Not started | G14 loyalty and G15 POS need client systems |

Deliberately out of scope (from the gap analysis): checkout and payments, courses and communities, apps, affiliates, reviews, WhatsApp, Instagram capture, AI assistants.

## Next steps

Agreed direction (7 November 2026): Mautic stays platform-neutral. Each shop platform gets a native-webhook adapter in Mautic (`Platform/`, WooCommerce done) for zero-install setups, plus a thin store connector that sends the standard formats to `/ecommerce/order`, `/ecommerce/event` and `/ecommerce/product`. Target platforms: WooCommerce and Shopify; Magento is off the list for now (decided 8 November 2026). The REST import from Mautic (option B) is dropped in favour of the connector pushing the catalog and order history.

1. **WooCommerce connector plugin (G8)**, repository [`jos0405/mautic-ecommerce-woocommerce`](https://github.com/jos0405/mautic-ecommerce-woocommerce), 0.3.0 (pushed 8 November 2026): orders, refunds with per-line refunded quantity, cancellations, products, storefront events for known shoppers with consent (WP Consent API or "always"; DNT/GPC honoured), restore-cart links, WP-CLI sync and test. 0.3.0 added Test connection (uses `/ecommerce/ping`), Sync now in the background, guests identified from Mautic email links (`ct`, resolved by tracking hash here) and integration tests (wp-phpunit with WooCommerce, 27 tests, own database `woo_test`). Orders carry the email link (`ct`) for attribution. 0.4.0 adds a weekly full product sync as a safety net for missed product updates; 0.5.0 the signed coupon endpoint for G13 and coupon codes on orders (36 tests). 0.5.1 sends the product's created date (`created_at`) for the `new_arrivals` block (9 November 2026). Every version is pushed and tagged (`v0.1.0` to `v0.5.1`).
2. **Shopify adapter**: code done (8 November 2026), further Shopify work postponed (decided 9 November 2026): no checks against a real store, no app, until it is picked up again. `Platform/ShopifyPlatform` and `Service/ShopifyOrderMapper`: `X-Shopify-Hmac-Sha256` with its own key (`shopify_webhook_secret`, an optional key field added in `appendToForm('keys')`), orders/create and orders/updated with refunds inside, products, category from product type or taxonomy, `ct` from `landing_site`. Fixtures in `Tests/Fixtures/shopify-*.json` are written from Shopify's documented payloads, not captured from a store: check them against a real development store before release. Open: a Shopify app for storefront events (customer events / web pixels), and an API import of the catalog and past orders (`CatalogImporterInterface`). **Magento** is off the list for now (decided 8 November 2026); it would need an extension (no native webhooks in Open Source).
3. **Phase 3 done** (8 November 2026) apart from G12 (cross-sell groups from co-purchase data), which the roadmap puts in Phase 3 too but rates Medium. Add `mautic:ecommerce:product-alerts` to cron on each install. The visual Products block in the GrapesJS builder is done (1.9.1): `Assets/js/ecommerce-builder.js` registers a GrapesJS plugin through `window.MauticGrapesJsPlugins` (contexts `email-mjml` and `email-html`); the block is an `mj-text` (or HTML text) marked with the css-class `ecommerce-products`, whose only content is the token; settings rewrite the token, and the canvas swaps in example cards from `AjaxController::productBlockPreviewAction` (DOM only, never saved). Categories come from `productCategoriesAction`. Plugin JS is served from `media/js/app.js`: run `mautic:assets:generate` after changing it. Tested in headless Chrome against grapesjs 0.22.4 + grapesjs-mjml 1.0.6, and on 9 November 2026 in the full Mautic 7.2 builder (MJML theme paprika): block listed under E-Commerce, preview cards from the live catalog, settings through the trait panel, token saved in the MJML and HTML (no preview markup), settings restored on reopening, token rendered to product cards at send. A real mouse drag could not be automated in headless Chrome; dragging the block in by hand was confirmed working on 9 November 2026.

## How the code is organised

| Area | Main classes |
|------|--------------|
| Intake | `Controller/PublicController` (signed `/ecommerce/order`, `/ecommerce/event`, `/ecommerce/product`; native `/ecommerce/webhook/{platform}`), `EventSubscriber/TrackingSubscriber` (mtc.js), `Controller/Api/*` |
| Shop platforms | `Platform/StorePlatformInterface` (signing secret, signature, webhook type, order and product mapping; tagged, collected by `PlatformRegistry`), `Platform/WooCommercePlatform`, `Platform/ShopifyPlatform`; `Platform/CatalogImporterInterface` for API pulls (no implementation yet) |
| Catalog | `Entity/EcommerceProduct(Repository)`, `Service/CatalogSync` (upsert, category fill-in, recalculation), `Service/ProductPayloadParser` |
| Parsing | `Service/OrderPayloadParser`, `Service/StorefrontEventParser`, `Service/WooCommerceOrderMapper` |
| Writing orders | `Service/EcommerceOrderTracker` (new orders; known orders go to `OrderManager::applyRefunds`), `Service/OrderManager` (edit, delete, refunds) |
| Computed contact fields | `Service/CommerceRecalculator` is the single entry point. It runs `CommerceMetricsCalculator` (lifetime value, order count, last order, timing), `LifecycleCalculator` (stage, RFM) and `BehaviourCalculator`. All write in bulk through `LeadColumnWriter`. |
| Queries | `Service/PurchaseHistoryQuery` (spend, qty, orders per contact; condition and behaviours), `Service/EcommerceAnalytics` (widgets) |
| Campaigns | `EventSubscriber/CampaignSubscriber` (all decisions and conditions), `Service/AbandonmentDetector` |
| Contact page | `EventSubscriber/ContactProfileTabSubscriber` (eCommerce tab), `EventSubscriber/LeadSubscriber` (history), `Service/ShoppingActivity` (condenses storefront events: one entry per cart session, status open / abandoned / ordered) |
| Currency | `Service/ExchangeRateProvider`, `EcbRateFetcher`, `BaseCurrencyRebaser` |
| Settings | `Integration/Config` (getters with defaults), `Integration/EcommerceConnectorIntegration` (form), `Resources/views/Integration/config_form.html.twig` (renders each field by name: add new fields there) |

Commands: `mautic:ecommerce:recalculate` (daily), `mautic:ecommerce:exchange-rates` (daily, before recalculate), `mautic:ecommerce:abandonment` (every 5 minutes), `mautic:ecommerce:behaviours:update`.

## Conventions

* **Unit prices** are per unit before discounts, including tax (what the shopper sees), from both the WooCommerce connector and native WooCommerce webhooks.
* **Money:** queries sum `EcommerceOrder::baseNetSql()` (net of refunds, in base currency, face value without a rate) and filter on `EcommerceOrder::COUNTED_STATUSES`. Email revenue badges are the exception: per currency, `net_total`.
* **Computed fields** are written with direct SQL and fire no contact events. New ones go into `LeadCommerceFieldDefinition` (installed on plugin update) and one of the calculators.
* **Schema:** one migration class per version in `Migrations/`, idempotent through `isApplicable()`. Mirror every change in `Tests/Functional/InstallsEcommerceSchema.php`. Migrations only run when the plugin version changes.
* **Webhooks** always answer 204, also on rejection; the reason is logged as a warning to Mautic's log. Plugin services log to the `mautic` channel (bound in `Config/services.php`).
* **Contacts are never created** from orders or events; only existing contacts (email or ID) are linked.
* **Campaign triggers** from webhooks, API and cron set the contact as system contact around `RealTimeExecutioner::execute()` and reset it afterwards. Orders and events more than a day old never trigger. A failing campaign run is logged as an error (decision, contact, order or event ID) and never fails the request: the order is already stored, and a 500 would make the shop retry into the duplicate check. Functional test helpers assert the 204.
* **Privacy:** storefront events are off by default, identified contacts only, DNT/GPC honoured for browser events, retention through maintenance cleanup.

## Testing

Tests need Mautic's dev dependencies (PHPUnit, Liip test bundles) and a separate test database. `phpunit.xml.dist` forces `DB_NAME=mautic_test` because functional tests reset the database. Put `DB_HOST`, `DB_PORT`, `DB_USER` and `DB_PASSWD` for the test database in the project root's `.env.test.local`.

From `docroot/app`:

```bash
rm -rf ../../var/cache/test   # the test container is not rebuilt automatically (APP_DEBUG=0)
../../vendor/bin/phpunit -c ../plugins/EcommerceConnectorBundle/phpunit.xml.dist
../../vendor/bin/phpunit -c ../plugins/EcommerceConnectorBundle/phpunit.xml.dist --testsuite Unit
```

Run the whole suite before every deploy. 224 tests passed on `feature/1.9.0` against Mautic 7.2.2 (8 November 2026). The WooCommerce tests use a realistic order fixture in `Tests/Fixtures/woocommerce-order.json`.

## Mautic 7.2 compatibility

Changes in Mautic 7.2 that the plugin had to follow:

* `ContactColumnsDictionary` is `final`. The Total revenue column on the contacts list is added by `EventSubscriber/ContactListColumnsSubscriber` (the `columns` var of `@MauticLead/Lead/*` templates), not by replacing the core service. Do not extend or swap final core classes; use events.
* `ChartQuery::applyFilters()` rejects raw `subquery` filters. Pass an array value (`['status' => [...]]`) for an `IN` filter.
* Campaign event logs are written with a raw upsert. In functional tests that trigger campaigns, call `IpLookupHelper::reset()` in `setUp()`: its static IP cache outlives the database reset and breaks the `ip_id` foreign key.

## Deploying to an install

1. Back up the `ecommerce_*`, `plugins` and `plugin_integration_settings` tables.
2. Replace the plugin files, then clear the cache **immediately**: while service constructors on disk differ from the cached container, requests fail.
3. Run `mautic:plugins:reload` (or Settings → Plugins → Install/Upgrade Plugins). Migrations run only when the version number changes.
4. Clear the cache again; run `mautic:ecommerce:exchange-rates` and `mautic:ecommerce:recalculate` once.
5. Cron: the three commands above, plus Mautic's own segment and campaign jobs.
