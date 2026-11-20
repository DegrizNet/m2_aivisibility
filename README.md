# Magelan_AiVisibility

AI visibility for Magento 2 - "GEO", generative engine optimisation. People ask ChatGPT, Claude,
Perplexity and Gemini what to buy. This module makes the shop easy for those assistants to read,
lets the shop decide which AI crawlers may read it, and shows what they read and what they send.

Free and open source (MIT). Read **What this module can not do** below before you expect results:
it measures and checks, it does not make AI assistants recommend your shop.

## What it does

| | |
|---|---|
| **llms.txt** | A plain-text guide to the shop at `/llms.txt` of each store view ([llmstxt.org](https://llmstxt.org)): summary, facts (currency, language, countries, contact), pages about the shop, policy pages, categories with descriptions, best sellers with prices. `/llms-full.txt` adds every visible product. Cached, rebuilt at most every six hours. |
| **AI crawlers in robots.txt** | 26 known AI crawlers in three groups - model training, AI search index, fetches for a user - each group allowed or blocked, single crawlers always blocked or always allowed, private paths. Appended to the shop's own robots.txt; the shop's `User-agent: *` rules are repeated for AI crawlers, because a crawler with its own group ignores `*`. |
| **Crawler log** | Every visit of a known AI crawler counted per day, plus the latest single visits (page, status) - including pages from Magento's own page cache. No IP address is stored. |
| **Visitors and orders from AI** | Visits from ChatGPT, Perplexity, Claude, Gemini, Copilot, DeepSeek, Grok, Le Chat, Meta AI, You.com and Poe, by referrer or `utm_source`. A first-party cookie remembers the source for the attribution window (30 days by default); an order placed in that window is credited to it. |
| **Overview** | Crawler visits per day and per crawler with its robots.txt status, the pages they read most (with errors), visits, orders, conversion and revenue per assistant. |
| **What does a bot see?** | Fetches any page of the shop over the internet as GPTBot, OAI-SearchBot, ChatGPT-User, ClaudeBot or PerplexityBot - so a firewall or CDN rule that blocks the real crawler shows up - and reads it without JavaScript: status, indexability, title, meta description, H1, words of text, canonical, JSON-LD types, price, availability and rating in the markup, breadcrumbs, alt texts. A score and what to fix, and which crawler robots.txt lets in. |
| **Page review** | Every night the shop reads all its pages (home, enabled products, active categories and CMS pages) the way a search engine does, one page a second, and lists what to fix, most serious first: pages that do not open or are hidden by noindex, missing, too long, too short or repeated titles and meta descriptions, missing or several H1, addresses that redirect, links to pages that do not exist or redirect, canonical pointing elsewhere, little text, images without alt text, products without price in the structured data, slow pages. Each problem says what to do; each page has a "Fix" link to its product, category or CMS form. A score per page and for the shop (100 = nothing to fix), its trend, and a weekly e-mail. A bigger shop than one night's limit (1,000 pages) is reviewed in turns. |
| **Pages not found (404)** | Every storefront 404 is counted per path (visitors and crawlers apart, the page that linked to it), also 404 pages from the built-in page cache. One click turns a missing address into a permanent redirect (a normal URL rewrite) to the most similar existing page, which is suggested. |
| **Catalogue readiness** | Products without description, short description, meta description, picture, alt texts or reviews; categories without description; CMS pages without meta description. |

Structured data itself is left to the theme or to Magelan_RichSnippets (product, offer,
breadcrumbs) and Magelan_Reviews (ratings); the audit checks that it is there.

## Installation

```
composer require degriznet/m2_aivisibility
bin/magento module:enable Magelan_AiVisibility
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy
```

**Stores > Configuration > Magelan > AI Visibility**, *Enabled* = Yes, write the summary and pick
the pages. Then **Marketing > Magelan AI Visibility > Overview**.

If the web server serves a physical `pub/robots.txt` or `pub/llms.txt`, Magento never sees the
request: remove those files.

## What this module can not do

Measured over 81 days on our own shop (degriz.net / degriz.eu, November 2026), and checked against
the published research:

- **It does not make AI recommend you.** What is known to matter: AI search crawlers allowed (also
  in the CDN), content that the server sends as HTML, and pages indexed in Google and Bing. Brand
  mentions on other sites and fresh content help less clearly. The module checks the first three;
  it can not do the rest for you.
- **llms.txt is optional.** No AI crawler fetched our llms.txt in 81 days; only SEO tools did.
  Serve it if you like, do not expect traffic from it.
- **Structured data is no shortcut.** The one controlled experiment we found showed no effect on
  AI citations. It still matters for Google rich results.
- **Expect little traffic from AI for now.** On our shop OpenAI's search crawler read about 1,400
  pages and brought about 15 visits from ChatGPT. Perplexity and Claude did not visit at all.
- **The crawler log trusts the user agent.** Security scanners that pretend to be PerplexityBot or
  ClaudeBot are counted as those crawlers. Treat a crawler you never saw in your server log as
  suspicious; IP verification is not built in.
- **The scores (0-100) are indicative.** They count problems found by the page review and the
  "What does a bot see?" check; they are not a measure of how visible you are in AI answers.
- **No shopping through AI agents.** There is no product feed or checkout for ChatGPT or Gemini
  in this module; buying through AI agents is not available in the EU yet.

## Limits

- Requests answered by Varnish or a CDN never reach Magento and are not counted. With Varnish,
  count crawlers in the Varnish log instead; the rules in robots.txt work either way.
- Some assistants send no referrer. Those visits look like direct traffic to any analytics tool,
  this one included.
- The page review reads the shop over the network. When a CDN answers the server's own requests
  with a bot challenge (Cloudflare does), it reads the pages from the web server directly, at the
  address an admin page saw; open *Page Review* in the admin once after installing.
- Behind Varnish only the first request of a cached 404 page reaches the shop; the log then counts
  addresses, not every visit.
- Crawlers can ignore robots.txt. Blocking them for real belongs in the firewall or CDN.

## Privacy

The crawler log holds crawler name, page path, status and time - no IP address, no user agent
string. Referral tracking stores the source name and the landing path in a first-party cookie.
With Magento's cookie restriction mode on, the cookie is set only after the visitor agrees.

The page review stores per page its path, title, meta description, problems and the internal
links on it. The 404 log stores the path, two counters, times and the host and path of the page
that linked to it - no visitor, no IP address.

## Support

Free, without guarantees (see LICENSE.txt). Report a bug as a GitHub issue with steps to
reproduce it on a clean Magento with the Luma theme. Help with AI visibility or SEO for your shop:
[degriz.net](https://degriz.net).
