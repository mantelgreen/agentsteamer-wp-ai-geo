=== AgentSteamer AI SEO & GEO ===
Contributors: mantelgreen
Tags: seo, geo, llms-txt, schema, ai
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress 原生、自包含的 AI SEO / GEO 优化插件：基础 SEO、结构化数据、llms.txt / Markdown、AI 一键成文与审阅队列。

== Description ==

用于内容型 WordPress 站点（文章 + 知识库）的 SEO 与生成式引擎优化（GEO）工具。**自包含**，不依赖任何外部平台；大模型接口由站点管理员自行配置。

官网：[https://www.agentsteamer.com](https://www.agentsteamer.com)　·　官方 Blog：[https://blog.agentsteamer.com](https://blog.agentsteamer.com)

= 功能 =

**基础 SEO**
* SEO 标题 / Meta 描述（支持模板变量）、Canonical、robots
* Open Graph / Twitter 卡片、面包屑、XML Sitemap
* 列表页批量编辑；编辑器内搜索与社交预览

**结构化数据（JSON-LD）**
* @graph：Organization / WebSite / Article(BlogPosting) / BreadcrumbList / Person / WebPage
* 文章级：字数、栏目、关键词、Speakable；FAQPage / HowTo / 自定义 JSON-LD
* 全局 JSON-LD 模板；AI 从正文自动提取 FAQ / HowTo

**GEO**
* /llms.txt、/llms-full.txt
* /{slug}.md —— 供 AI 消费的 Markdown 版本
* AI 爬虫治理（robots.txt，区分「训练 / 搜索索引 / 用户抓取」）

**AI 自动化**
* 可视化配置大模型接口：OpenAI / DeepSeek / 通义千问 / OpenRouter / Azure OpenAI / Anthropic Claude / Google Gemini / 自定义 OpenAI 兼容
* 一键生成文章：流式输出，完成后保存为草稿
* 一键生成 SEO 标题 / 描述 / 焦点关键词
* 内容就地优化（生成审阅草案）
* 图片 Alt、内部链接建议、空白字段自动补全

**人机协同**
* 所有 AI 改动进入审阅队列：修改前后对比、应用 / 拒绝 / 回滚

**技术 SEO 与收录**
* 重定向管理（301/302/307/308/410/451）；slug 变更自动重定向；CSV 导入导出
* 404 监控，一键转 301
* 收录提交：IndexNow（Bing / Yandex 等）+ 百度快速收录，发布时同步提交

**审计与分析**
* 站点审计（13 项检查 + 评分）、主题聚类与内容缺口、AI 选题建议、总览仪表盘

**开发者接口**
* WP-CLI：wp agentsteamer-ai audit|optimize|autofill|alt|links|indexnow|llms
* REST / Abilities：agentsteamer-ai/v1/abilities
* Hooks：agentsteamer_ai_*

= 环境要求 =

* WordPress 5.8 或更高
* PHP 7.4 或更高
* 一个可配置的大模型接口（API Key，或自建的 OpenAI 兼容服务）

== Installation ==

1. 将 `agentsteamer-wp-ai-geo` 目录放入 `/wp-content/plugins/`。
2. 在「插件」页面启用。
3. 前往「AgentSteamer AI → 设置」填写站点信息与大模型接口。
4. 前往「AgentSteamer AI → 一键生成文章」开始使用。

== Frequently Asked Questions ==

= 支持哪些大模型？ =

任何 OpenAI 兼容接口均可：OpenAI、DeepSeek、通义千问（DashScope）、OpenRouter、Azure OpenAI，以及自建的 OpenAI 兼容服务；另支持 Anthropic Claude、Google Gemini。在「设置 → 大模型接口」中配置，并用「连接测试」验证。

= 是否依赖模釜平台或其它外部服务？ =

不依赖。插件完全自包含，仅使用你自行配置的大模型接口，默认零遥测。

= AI 会直接发布内容吗？ =

不会。所有 AI 生成与改动都会进入「审阅队列」，经你确认后才写入文章，并支持回滚。

= 收录提交支持哪些搜索引擎？ =

IndexNow（Bing、Yandex、Naver 等）与百度快速收录。Google 的普通页面没有实时推送接口，通过 Sitemap 与正常抓取收录。文章发布或更新时会同步提交。

= 会与其它 SEO 插件冲突吗？ =

建议同一时间只启用一个 SEO 插件。本插件与 Yoast / Rank Math / AIOSEO 均会输出元数据与 Sitemap，如已安装其它插件，请二选一。

= API Key 如何安全保存？ =

可在「设置 → 大模型接口」中填写（表单仅回显掩码），或通过 `wp-config.php` 常量提供以避免明文入库。密钥不会输出到前台。

== Changelog ==

= 0.1.0 =
* 基础 SEO、结构化数据（含 FAQ / HowTo / Speakable）、GEO（llms.txt / Markdown / AI 爬虫）。
* AI 一键成文（流式）、元数据生成、内容优化（审阅）、图片 Alt、内部链接、主题聚类。
* 审阅队列（含回滚）、重定向、404 监控、收录提交（IndexNow / 百度）、站点审计、仪表盘。
* WP-CLI 与 Abilities API。

== Upgrade Notice ==

= 0.1.0 =
首个版本。
