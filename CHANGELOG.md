# Changelog

## 2.0.0 - 2026-11-20

- Free and open source under the MIT licence.
- Removed agentic commerce (ACP product feed, UCP checkout, their settings, cron jobs and console
  command). Existing installs keep the table `magelan_ai_ucp_session`; it is no longer used and
  can be dropped.
- Page review: links under `/cdn-cgi/` (Cloudflare e-mail protection) are no longer reported as
  broken on every page.
- README: what the module can not do, measured on a live shop.

## 1.2.6 - 2026-10-23

- Page review and audit no longer write pages into the built-in full page cache (they still read
  it). The nightly review of every store view left a copy of each page in a file cache that never
  removes expired entries: 5.8 GB in eleven days on a live shop, and a full hosting quota.

## 1.2.5 - 2026-10-15

- Release notes corrected.

## 1.2.4 - 2026-10-15

- Release notes for the module page (release-notes.json).

## 1.2.3 - 2026-10-12

- Page review: *HTTP Login for a Protected Shop* (encrypted, user:password) for a staging site behind
  basic authentication. Before, every page of such a shop was reported as HTTP 401. The credentials
  go only to the shop's own hosts.
- robots.txt answered 500 on a shop that had never saved custom robots.txt instructions: Magento then
  returns null, the plugin required a string. Found on a live shop.

## 1.2.2 - 2026-10-12

- Page review: product facts are also read from schema.org microdata (itemprop), which Luma and
  Hyvä print on the product page. A price, availability or rating that is only in microdata is no
  longer reported as missing (before, products with reviews showed "Product data without a price").
- Weekly e-mail: a page that does not exist shows how many of its visits came from bots and AI
  assistants. Slovenian: settings path "Trgovine > Nastavitve".

## 1.2.1 - 2026-10-11

- Page review: when products have no price in the structured data and Magelan Rich Snippets is
  installed with its product JSON-LD switched off, the advice says so and links to the setting.

## 1.2.0 - 2026-10-11

Page review: SEO and GEO in one place.

- **Page review** (Marketing > AI Visibility > Page Review): every night, from the chosen hour, the
  shop reads all its pages - home, enabled and visible products, active categories, active CMS
  pages - like a search engine, one page a second, and lists 20 kinds of problems with advice and
  a "Fix" link to the product, category or CMS form. Problems across pages too: repeated titles and
  meta descriptions, links to pages that do not exist or redirect. Score per page and for the shop,
  trend of the last reviews; "Review now" runs it while the page is open. Settings: pages per
  night (1,000), hour, pause between pages, store views.
- When a CDN challenges the server's own requests, the review reads the web server directly.
- **404 log**: storefront "not found" answers counted per path, also from the built-in page cache;
  one click creates a 301 URL rewrite to the suggested most similar page, or ignores the address.
- **Weekly e-mail** on Monday with the score per store view, its change and the biggest problems.
- `bin/magento magelan:ai:page-review --store=<code> [--limit] [--delay]`.
- Page reader: also lists the internal links of a page and the redirects followed.

## 1.1.2 - 2026-10-11

- Admin convention: Menu label "menu" is now "AI Visibility"; the menu item that opens the configuration is called "Settings" and comes last; section "magelan_aivisibility" ordered in the marketing band (sortOrder 404). No data or permission changes.

## 1.1.1 - 2026-10-02

ACP product feed: the fields of `schema.feed.json` (ACP spec 2026-04-17) that Magento has data for,
under the schema's own names.

- `description.plain` on each product: the description (or short description) as plain text,
  without HTML, Page Builder markup or widget directives, at most 5,000 characters
- `barcodes` (`type` GTIN) on each variant from a product attribute chosen in the new setting
  *Agentic Commerce: Advanced > GTIN Attribute* (empty by default = no barcodes); only valid
  GTIN-8/12/13/14 with a correct check digit are published
- `variant_options` (name and value, e.g. Color: Red) on the variants of configurable products
- `categories` (most specific active category paths, taxonomy `merchant`), `seller` (store name,
  terms and privacy links) and `media[].alt_text`
- No `brand`: the ACP feed schema has no such field and rejects unknown keys
- README: `/ucp` as the address to give an agent where the server refuses `/.well-known/` paths;
  `bin/magento magelan:ai:acp-feed` to rebuild the feed at once

## 1.1.0 - 2026-10-02

Agentic commerce, ported from the Magento 1 module Degriz_AgentCommerce 0.3.4 (in production on
degriz.net). One switch: *Agentic Commerce > Enabled*.

- ACP product feed (OpenAI Agentic Commerce Protocol) per store view at `/acp/feed.jsonl`: prices
  in ISO 4217 minor units on the tax basis the shop displays, configurable products with their
  variants; built by cron every three hours and by `bin/magento magelan:ai:acp-feed`
- UCP checkout (Google Universal Commerce Protocol): `/.well-known/ucp` and the five
  `/ucp/checkout-sessions` operations on real guest quotes; sessions end in `requires_escalation`
  with a `continue_url` into the shop's own checkout
- `/ucp/cart?sku=...&qty=...` cart link for assistants that can only show a link
- Rate limit per address and a daily ceiling on carts written by agents
- Changes against the Magento 1 module: a canceled session can no longer be updated or completed
  (409), `expires_at` is RFC 3339, currencies without cents (JPY) or with three decimals (KWD) get
  the right minor units, a variant SKU in a cart goes in as its configurable parent with the
  options chosen, an agent's basket is merged into a cart that already holds items instead of
  replacing it, and the terms and privacy links are found by page name instead of fixed paths

## 1.0.1 - 2026-10-01

- Orders placed over REST (one-step checkouts, Hyvä Checkout, headless) are attributed to their AI source too; the observer only ran in the frontend area.

## 1.0.0 - 2026-10-01

First release.

- llms.txt and llms-full.txt per store view
- AI crawler rules in robots.txt (26 crawlers, three groups, overrides, private paths)
- AI crawler visit log, including pages from Magento's page cache
- Visits, orders and revenue from eleven AI assistants
- Overview page, page audit as an AI crawler, catalogue readiness report
