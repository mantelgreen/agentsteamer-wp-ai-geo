=== AgentSteamer AI SEO & GEO ===
Contributors: mantelgreen
Tags: seo, geo, llms-txt, schema, ai
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A self-contained AI SEO & GEO toolkit for WordPress: on-page SEO, structured data, llms.txt / Markdown, and one-click AI writing with a review queue.

== Description ==

A self-contained SEO and Generative Engine Optimization (GEO) toolkit for any content site (blog, docs, or knowledge base). It was hardened on the AgentSteamer website first, then extracted into a general-purpose plugin that installs on any WordPress site. It does not depend on any external platform — the AI provider is configured by the site owner.

Official site: [https://www.agentsteamer.com](https://www.agentsteamer.com)
Official blog: [https://blog.agentsteamer.com](https://blog.agentsteamer.com)

= Features =

**On-page SEO**
* SEO title / meta description (templating), canonical, robots meta
* Open Graph / Twitter cards, breadcrumbs, XML sitemap
* Bulk edit from the post list; in-editor search and social previews

**Structured data (JSON-LD)**
* @graph: Organization / WebSite / Article (BlogPosting) / BreadcrumbList / Person / WebPage
* Per article: word count, section, keywords, Speakable; FAQPage / HowTo / custom JSON-LD
* Global JSON-LD template; AI extracts FAQ / HowTo from the content

**GEO**
* /llms.txt and /llms-full.txt
* /{slug}.md — a clean Markdown version for AI agents
* AI crawler governance in robots.txt (training / search index / user fetch)

**AI automation**
* Configure any provider: OpenAI, DeepSeek, Qwen (DashScope), OpenRouter, Azure OpenAI, Anthropic Claude, Google Gemini, or a custom OpenAI-compatible endpoint
* One-click article generation with live streaming output, saved as a draft
* One-click SEO title / description / focus keyword generation
* In-place content optimization, queued as a review draft
* Image alt text, internal link suggestions, auto-fill of blank SEO fields
* AI tag extraction: suggest relevant tags from the content and apply them in one click
* Language-aware output: FAQ / HowTo and tags are generated in the article's language (works with AgentSteamer WP AI Lang, Polylang or WPML)

**Human in the loop**
* Every AI change goes to a review queue: before/after diff, apply / reject / roll back

**Technical SEO & indexing**
* Redirect manager (301/302/307/308/410/451), automatic redirect on slug change, CSV import/export
* 404 monitor with one-click 301
* Indexing submission: IndexNow (Bing / Yandex, etc.) + Baidu, submitted synchronously on publish

**Audit & analysis**
* Site audit (13 checks with a score), topic clustering & content gaps, AI topic suggestions, overview dashboard

**Developer interfaces**
* WP-CLI: wp agentsteamer-ai audit|optimize|autofill|alt|links|indexnow|llms
* REST / Abilities: agentsteamer-ai/v1/abilities
* Hooks: agentsteamer_ai_*

= Requirements =

* WordPress 5.8 or higher
* PHP 7.4 or higher
* An AI provider (an API key, or a self-hosted OpenAI-compatible endpoint)

== Installation ==

1. Upload the `agentsteamer-wp-ai-geo` folder to `/wp-content/plugins/`.
2. Activate the plugin on the Plugins screen.
3. Go to **AgentSteamer AI → Settings** to enter your site info and AI provider.
4. Go to **AgentSteamer AI → Generate Article** to start.

== Frequently Asked Questions ==

= Which AI models are supported? =

Any OpenAI-compatible endpoint: OpenAI, DeepSeek, Qwen (DashScope), OpenRouter, Azure OpenAI, or a self-hosted OpenAI-compatible service. Anthropic Claude and Google Gemini are also supported. Configure it under Settings → AI Provider, and use “Test connection” to verify.

= Does it depend on the AgentSteamer platform or any external service? =

No. The plugin is fully self-contained and only uses the AI provider you configure. No telemetry by default.

= Will AI publish content automatically? =

No. All AI output and changes go to a review queue, and nothing is written to a post until you approve it. Changes can be rolled back.

= Which search engines does indexing submission support? =

IndexNow (Bing, Yandex, Naver, etc.) and Baidu. Google has no real-time submission for regular pages; it discovers content via your sitemap and normal crawling. Submission happens when a post is published or updated.

= Will it conflict with other SEO plugins? =

Run one SEO plugin at a time. This plugin, like Yoast / Rank Math / AIOSEO, outputs metadata and a sitemap; if you already run one, choose a single plugin.

= How is the API key stored? =

Enter it under Settings → AI Provider (the field only echoes a mask), or define it in wp-config.php to avoid storing it in the database. The key is never exposed to the front end.

== Screenshots ==

1. Overview dashboard — SEO / GEO status, audit score, and pending reviews at a glance.
2. One-click article generation with live streaming output; FAQ / HowTo are extracted automatically.
3. Editor sidebar — on-page SEO / GEO score, checks, fields, AI tools, internal links, and previews.
4. Review queue — before/after diff with apply, reject, and roll back.
5. Site audit — 13 technical and content checks with a score.

== Changelog ==

= 0.1.3 =
* Multilingual: all article-level AI tasks — FAQ / HowTo extraction, SEO title / description generation, AI content optimization (review draft), tag extraction and image alt text — now output in the article's language (detected via the AgentSteamer WP AI Lang plugin, Polylang or WPML). An English article no longer produces Chinese output.
* New: AI tag extraction — the editor sidebar ("文章标签" panel) can suggest relevant tags from the content, in the article's language, and apply them as WordPress tags.
* Adds helpers `agentsteamer_ai_content_language_label()` and the `tags` prompt (customizable under Settings → Prompts).
* More reliable structured extraction: FAQ / HowTo (and tags) now use a higher output ceiling and retry once, with a corrective instruction, when the model returns non-JSON — fixing the "无法解析模型返回的结构化数据" error.
* Internal links: the insert heading now follows the article's language ("延伸阅读" for Chinese, "Further Reading" otherwise), and related-article suggestions are kept within the same language.

= 0.1.2 =
* Auto-updates: the plugin now checks GitHub Releases and updates itself in place from the Plugins / Updates screen (no need to delete and re-upload).
* Indexing settings: clarified that Baidu 普通收录 / 快速收录 share one push API (the token selects the channel), corrected the auto-submit help text (now async), and the log now reports the Baidu success count and remaining quota; HTTP-200 responses that contain an error are now treated as failures.
* Indexing: actionable hints for common Baidu errors (e.g. "site init fail" → check the verified site/token), and the submission page shows the exact site and (masked) token being sent.
* Baidu push: configure it by pasting the full API URL from 百度搜索资源平台 (it already contains site + token), so the site always matches the verified domain — no more www / protocol mismatches.

= 0.1.1 =
* Structured data: smarter AI extraction — a HowTo is generated whenever the article contains actionable information (steps, checklists, paths, evaluation criteria); howto is now always returned as a proper object.
* Editor sidebar: clearer feedback when an extraction returns nothing.
* Site audit "AI-fill all content" reworked: runs immediately in batches (no longer depends on WP-Cron) with a live progress bar and per-item results (filled / already present / failed), an optional FAQ / HowTo scope, no 2000-item cap, and the audit cache is refreshed automatically.
* Settings: the max_tokens field now accepts up to 200000 with preset suggestions and inline guidance, and it raises the output ceiling for article generation and content optimization (never lowering the built-in defaults).
* Indexing submission: publishing/updating now queues submissions in the background (no longer blocks the editor), the IndexNow key-file rule is scoped to the configured key, and "submit all" is no longer capped at 2000 URLs.
* Sitemap / llms.txt: new "extra sites" setting — the sitemap becomes a sitemap index covering your own site plus other sites (e.g. a blog subdomain), and llms.txt gains an "other sites" section; per-type caps (2000 / 200) removed and sitemap lastmod values corrected.

= 0.1.0 =
* On-page SEO, structured data (FAQ / HowTo / Speakable), GEO (llms.txt / Markdown / AI crawlers).
* One-click article generation (streaming), metadata generation, in-place content optimization (review), image alt, internal links, topic clustering.
* Review queue (with rollback), redirects, 404 monitor, indexing submission (IndexNow / Baidu), site audit, dashboard.
* WP-CLI and the Abilities API.

== Upgrade Notice ==

= 0.1.3 =
Language-aware FAQ / HowTo extraction (multilingual support) and AI tag extraction.

= 0.1.2 =
Clarified Baidu 普通 / 快速 push, clearer submission logs, and HTTP-200 error responses are now reported as failures.

= 0.1.1 =
Smarter FAQ / HowTo extraction and a faster, more reliable bulk AI fill.

= 0.1.0 =
Initial release.
