=== AgentSteamer AI SEO & GEO ===
Contributors: agentsteamer
Tags: seo, geo, llms.txt, schema, ai
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress 原生 AI SEO / GEO 优化插件：基础 SEO、结构化数据、llms.txt、Markdown 与 AI 一键成文。自包含，不依赖任何外部平台。

== Description ==

AgentSteamer AI SEO & GEO adds a complete, self-contained SEO / GEO toolkit for content sites:

* **基础 SEO**：SEO 标题、Meta 描述、Canonical、robots、Open Graph / Twitter 卡片、面包屑、XML Sitemap、批量编辑。
* **结构化数据（JSON-LD）**：Organization / WebSite / Article(BlogPosting) / BreadcrumbList / Person / WebPage + SEO 标题/描述/关键词/字数/Speakable；文章级 FAQPage / HowTo / 自定义 JSON-LD；全局 JSON-LD 模板；AI 从正文提取 FAQ/HowTo。
* **GEO**：`/llms.txt`、`/{slug}.md` Markdown 输出、AI 爬虫（robots.txt）治理。
* **AI 自动化**：可视化配置大模型接口（OpenAI / DeepSeek / Qwen / OpenRouter / Azure / Anthropic / Gemini / 自定义 OpenAI 兼容；支持关闭思考加速）；一键生成文章；一键生成 SEO 标题/描述/焦点关键词；内容就地优化（生成审阅草案）；图片 Alt；内部链接建议；空白字段自动补全。
* **人机协同**：所有 AI 改动进入**审阅队列**，支持修改前后对比、应用 / 拒绝 / 回滚。
* **技术 SEO**：重定向管理（301/302/307/308/410/451，slug 变更自动重定向，CSV 导入导出）、404 监控与一键转 301。
* **收录提交**：IndexNow（Bing/Yandex 等）+ 百度快速收录，发布自动提交。
* **审计与聚类**：站点审计（13 项检查 + 评分）、主题聚类与内容缺口、AI 选题建议。
* **总览仪表盘** 与 **WP-CLI**（`wp agentsteamer-ai audit|optimize|autofill|alt|links|indexnow|llms`）。
* **Agent 接口**：`agentsteamer-ai/v1/abilities` 列出并执行能力，供外部 AI Agent 调用。

插件完全自包含，仅依赖 WordPress 与你自行配置的大模型接口，不调用任何外部平台。

== Installation ==

1. 上传 `agentsteamer-wp-ai-geo` 目录到 `/wp-content/plugins/`。
2. 在「插件」页面启用。
3. 前往「AgentSteamer AI → 设置」配置站点信息与大模型接口。
4. 前往「AgentSteamer AI → 一键生成文章」开始使用。

== Changelog ==

= 0.1.0 =
* 基础 SEO、结构化数据（含 FAQ/HowTo/Speakable）、GEO（llms.txt / Markdown / AI 爬虫）。
* AI 一键成文、元数据生成、内容优化（审阅）、图片 Alt、内部链接、主题聚类。
* 审阅队列（含回滚）、重定向、404 监控、收录提交（IndexNow / 百度）、站点审计、仪表盘。
* WP-CLI 与 Abilities API。
