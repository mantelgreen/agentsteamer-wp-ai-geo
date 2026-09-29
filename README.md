# agentsteamer-wp-ai-geo

> 模釜智能体平台官网自用 AI SEO/GEO 优化 WordPress 插件。

模釜官网：https://www.agentsteamer.com　｜　官方 Blog：https://blog.agentsteamer.com

一个 **WordPress 原生、自包含** 的 SEO 与生成式引擎优化（GEO）工具，用于 [模釜（AgentSteamer）](https://www.agentsteamer.com) 官网内容站（文章 + 知识库）。不依赖任何外部平台，自带大模型接口接入与站点检索/索引能力。

> 说明：本项目**首先用于模釜官网自身内容优化**，并按可独立安装、可复用的插件构建。

## 功能

### 基础 SEO
- SEO 标题 / Meta 描述（支持模板变量）、Canonical、robots meta
- Open Graph / Twitter 卡片、面包屑、XML Sitemap
- 列表页批量编辑；编辑器内搜索与社交预览

### 结构化数据（JSON-LD）
- `@graph`：Organization / WebSite / Article(BlogPosting) / BreadcrumbList / Person / WebPage
- 文章级：字数、栏目、关键词、Speakable；FAQPage / HowTo / 自定义 JSON-LD
- 全局 JSON-LD 模板；AI 从正文自动提取 FAQ / HowTo

### GEO
- `/llms.txt`、`/llms-full.txt`
- `/{slug}.md`：供 AI 消费的 Markdown 版本
- AI 爬虫治理（robots.txt，区分「训练 / 搜索索引 / 用户抓取」）

### AI 自动化
- 可视化配置大模型接口：OpenAI / DeepSeek / 通义千问 / OpenRouter / Azure / Anthropic / Gemini / 自定义 OpenAI 兼容（支持关闭「思考」加速）
- 一键生成文章：**流式输出**，完成后保存为草稿
- 一键生成 SEO 标题 / 描述 / 焦点关键词
- 内容就地优化（生成审阅草案）
- 图片 Alt、内部链接建议、空白字段自动补全

### 人机协同
- 所有 AI 改动进入 **审阅队列**：修改前后对比、应用 / 拒绝 / 回滚

### 技术 SEO 与收录
- 重定向管理（301/302/307/308/410/451；slug 变更自动重定向；CSV 导入导出）
- 404 监控，一键转 301
- 收录提交：IndexNow（Bing / Yandex 等）+ 百度快速收录，**发布时同步提交**

### 审计与分析
- 站点审计（13 项检查 + 评分）
- 主题聚类与内容缺口、AI 选题建议、一键生成草稿
- 总览仪表盘

### 开发者接口
- WP-CLI：`wp agentsteamer-ai audit|optimize|autofill|alt|links|indexnow|llms`
- REST / Abilities：`agentsteamer-ai/v1/abilities`（列出并执行能力，供外部 AI Agent 调用）
- Hooks：`agentsteamer_ai_*`

## 环境要求

| 项 | 要求 |
| --- | --- |
| WordPress | ≥ 5.8 |
| PHP | ≥ 7.4 |
| 其它 | 一个可配置的大模型接口（API Key，或本地 / 兼容服务） |

## 安装

1. 将 `agentsteamer-wp-ai-geo` 目录放入 `/wp-content/plugins/`。
2. 在「插件」页面启用。
3. 前往 **AgentSteamer AI → 设置**，填写站点信息与大模型接口。

## 配置

- **大模型接口** — 服务商、接口地址（Base URL）、API Key、模型、温度、max_tokens、超时；提供「连接测试」。
- **GEO** — Sitemap、llms.txt、Markdown 输出。
- **收录提交** — IndexNow 密钥、百度推送 Token。
- **结构化数据** — 全局 JSON-LD 模板。
- **提示词** — 覆盖各项 AI 任务的系统提示词（留空则使用内置默认）。

> API Key 可通过 `wp-config.php` 常量提供，避免明文入库；插件默认**零遥测**。

## 使用

- **一键生成文章** — AgentSteamer AI → 一键生成文章：左侧配置，右侧实时流式输出，完成后保存为草稿。
- **编辑器侧边栏** — 文章编辑页右侧「AgentSteamer SEO / GEO」：评分与检查、字段编辑、AI 生成、内容优化、内部链接、搜索 / 社交预览。
- **审阅队列** — AgentSteamer AI → 审阅队列：应用 / 拒绝 / 回滚 AI 改动。

## 目录结构

```text
agentsteamer-wp-ai-geo/
├─ agentsteamer-wp-ai-geo.php   插件入口：常量、加载、激活 / 卸载钩子
├─ assets/                      css · js · images
├─ inc/                         模块：base · schema · geo · ai · indexing · audit · review · ...
│  ├─ providers/                大模型 Provider 适配
│  └─ class-plugin.php          模块装配
├─ readme.txt                   WordPress.org 格式说明
└─ uninstall.php                卸载清理
```

## 开发

- 源码即插件目录，采用模块化结构（见 `inc/`）。
- PHP 兼容基线为 **7.4**，未使用 PHP 8 专有语法。
- 开发时可将本目录同步/软链到本地 WordPress 的 `wp-content/plugins/` 下进行调试。

## 设计原则

- **自包含**：不调用模釜平台的 RAG / 向量库 / 私有网关等能力；大模型接口由站点管理员自行配置。
- **人机协同**：AI 只生成「草案」，一切改动经审阅后生效，可回滚。
- **成本与隐私可控**：Token 预算、模型路由、密钥密文存储、支持本地模型、默认零遥测。

## 许可证

GPLv2 或更高版本，见 [LICENSE](LICENSE)。
