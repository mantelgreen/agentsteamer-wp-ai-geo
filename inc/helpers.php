<?php
/**
 * Helper functions and shared constants.
 *
 * @package AgentSteamer_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default plugin settings.
 *
 * @return array
 */
function agentsteamer_ai_default_settings() {
	return array(
		// General.
		'enabled'            => 1,
		'title_separator'    => '—',
		'home_title'         => '',
		'home_desc'          => '',
		'default_desc'       => '',
		'org_name'           => '',
		'org_logo'           => '',
		'org_type'           => 'Organization',
		'social_profiles'    => '',
		'author_knows_about' => '',
		'auto_meta'          => 1,
		'noindex_search'     => 1,
		'noindex_author'     => 0,
		'noindex_date'       => 1,

		// GEO.
		'sitemap_enabled'    => 1,
		'sitemap_extra'      => '',
		'llms_txt_enabled'   => 1,
		'llms_txt_intro'     => '',
		'llms_full_enabled'  => 0,
		'llms_extra'         => '',
		'markdown_enabled'   => 1,
		'crawler_policy'     => array(),

		// Search engine indexing submission.
		'indexnow_enabled'   => 0,
		'indexnow_key'       => '',
		'indexnow_auto'      => 1,
		'baidu_enabled'      => 0,
		'baidu_endpoint'     => '',
		'baidu_token'        => '',
		'baidu_site'         => '',

		// Technical: redirects / 404.
		'redirect_slug_change' => 1,
		'notfound_log'         => 1,

		// Schema.
		'schema_global_jsonld' => '',

		// AI provider.
		'ai_enabled'         => 1,
		'auto_fill_blank'    => 1,
		'provider'           => 'openai',
		'base_url'           => 'https://api.openai.com/v1',
		'api_key'            => '',
		'model'              => 'gpt-4o-mini',
		'api_version'        => '2024-06-01',
		'thinking_mode'      => 'auto',
		'temperature'        => 0.7,
		'max_tokens'         => 8192,
		'timeout'            => 60,
	);
}

/**
 * Effective output-token ceiling for the generative tasks (article / optimize).
 *
 * The configured max_tokens raises the ceiling but never lowers a task below
 * its built-in default, so small values can't accidentally truncate output.
 *
 * @param int $default Built-in ceiling for the task.
 * @return int
 */
function agentsteamer_ai_output_ceiling( $default ) {
	$configured = (int) agentsteamer_ai_get_option( 'max_tokens', 8192 );
	return max( (int) $default, $configured );
}

/**
 * Get all settings merged with defaults.
 *
 * @return array
 */
function agentsteamer_ai_get_settings() {
	$stored = get_option( 'agentsteamer_ai_settings', array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	return wp_parse_args( $stored, agentsteamer_ai_default_settings() );
}

/**
 * Get a single setting.
 *
 * @param string $key     Setting key.
 * @param mixed  $default Fallback value.
 * @return mixed
 */
function agentsteamer_ai_get_option( $key, $default = null ) {
	$settings = agentsteamer_ai_get_settings();
	if ( array_key_exists( $key, $settings ) ) {
		return $settings[ $key ];
	}
	return $default;
}

/**
 * Whether the plugin is globally enabled.
 *
 * @return bool
 */
function agentsteamer_ai_is_enabled() {
	return (bool) agentsteamer_ai_get_option( 'enabled', 1 );
}

/**
 * Post-meta key for a field.
 *
 * @param string $field Field name.
 * @return string
 */
function agentsteamer_ai_meta_key( $field ) {
	return '_asi_' . $field;
}

/**
 * Read a plugin post-meta value.
 *
 * @param int    $post_id Post ID.
 * @param string $field   Field name.
 * @param mixed  $default Default value.
 * @return mixed
 */
function agentsteamer_ai_get_post_meta( $post_id, $field, $default = '' ) {
	$value = get_post_meta( $post_id, agentsteamer_ai_meta_key( $field ), true );
	if ( '' === $value || null === $value ) {
		return $default;
	}
	return $value;
}

/**
 * Catalog of known AI crawlers, grouped by purpose.
 *
 * @return array
 */
function agentsteamer_ai_crawler_catalog() {
	return array(
		array(
			'ua'      => 'GPTBot',
			'owner'   => 'OpenAI',
			'purpose' => 'training',
			'note'    => 'OpenAI 模型训练抓取',
		),
		array(
			'ua'      => 'OAI-SearchBot',
			'owner'   => 'OpenAI',
			'purpose' => 'search',
			'note'    => 'ChatGPT 搜索索引（影响被引用）',
		),
		array(
			'ua'      => 'ChatGPT-User',
			'owner'   => 'OpenAI',
			'purpose' => 'user',
			'note'    => '用户触发的实时抓取',
		),
		array(
			'ua'      => 'Googlebot',
			'owner'   => 'Google',
			'purpose' => 'search',
			'note'    => 'Google 搜索（AI Overviews 依赖）',
		),
		array(
			'ua'      => 'Google-Extended',
			'owner'   => 'Google',
			'purpose' => 'training',
			'note'    => 'Gemini / Vertex AI 训练与落地',
		),
		array(
			'ua'      => 'GoogleOther',
			'owner'   => 'Google',
			'purpose' => 'training',
			'note'    => 'Google 研究/一次性抓取',
		),
		array(
			'ua'      => 'ClaudeBot',
			'owner'   => 'Anthropic',
			'purpose' => 'training',
			'note'    => 'Anthropic 模型训练',
		),
		array(
			'ua'      => 'Claude-SearchBot',
			'owner'   => 'Anthropic',
			'purpose' => 'search',
			'note'    => 'Claude 搜索索引',
		),
		array(
			'ua'      => 'Claude-User',
			'owner'   => 'Anthropic',
			'purpose' => 'user',
			'note'    => '用户触发的实时抓取',
		),
		array(
			'ua'      => 'PerplexityBot',
			'owner'   => 'Perplexity',
			'purpose' => 'search',
			'note'    => 'Perplexity 搜索索引',
		),
		array(
			'ua'      => 'Perplexity-User',
			'owner'   => 'Perplexity',
			'purpose' => 'user',
			'note'    => '用户触发的实时抓取',
		),
		array(
			'ua'      => 'Bingbot',
			'owner'   => 'Microsoft',
			'purpose' => 'search',
			'note'    => 'Bing / Copilot 索引',
		),
		array(
			'ua'      => 'CCBot',
			'owner'   => 'Common Crawl',
			'purpose' => 'training',
			'note'    => '公开语料（常用于训练）',
		),
		array(
			'ua'      => 'Applebot',
			'owner'   => 'Apple',
			'purpose' => 'search',
			'note'    => 'Apple 搜索',
		),
		array(
			'ua'      => 'Applebot-Extended',
			'owner'   => 'Apple',
			'purpose' => 'training',
			'note'    => 'Apple AI 训练',
		),
		array(
			'ua'      => 'Bytespider',
			'owner'   => 'ByteDance',
			'purpose' => 'training',
			'note'    => '字节跳动训练抓取',
		),
		array(
			'ua'      => 'Amazonbot',
			'owner'   => 'Amazon',
			'purpose' => 'training',
			'note'    => 'Amazon 索引/训练',
		),
		array(
			'ua'      => 'Meta-ExternalAgent',
			'owner'   => 'Meta',
			'purpose' => 'training',
			'note'    => 'Meta AI 训练',
		),
		array(
			'ua'      => 'DuckAssistBot',
			'owner'   => 'DuckDuckGo',
			'purpose' => 'user',
			'note'    => 'DuckDuckGo AI 实时抓取',
		),
	);
}

/**
 * Crawler purpose labels.
 *
 * @return array
 */
function agentsteamer_ai_crawler_purposes() {
	return array(
		'training' => '训练类',
		'search'   => '搜索索引类',
		'user'     => '用户实时抓取类',
	);
}

/**
 * Supported content types for SEO fields.
 *
 * @return string[]
 */
function agentsteamer_ai_supported_post_types() {
	$types  = get_post_types( array( 'public' => true ), 'names' );
	$exclude = array( 'attachment' );
	return array_values( array_diff( $types, $exclude ) );
}

/**
 * Trim text to a maximum number of characters (multibyte safe).
 *
 * @param string $text   Input text.
 * @param int    $length Max length.
 * @return string
 */
function agentsteamer_ai_trim( $text, $length ) {
	$text = wp_strip_all_tags( (string) $text );
	$text = preg_replace( '/\s+/', ' ', $text );
	$text = trim( $text );
	if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $length ) {
		$text = mb_substr( $text, 0, $length - 1 ) . '…';
	} elseif ( strlen( $text ) > $length ) {
		$text = substr( $text, 0, $length - 1 ) . '…';
	}
	return $text;
}

/**
 * Plain-text version of a post's content.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function agentsteamer_ai_plain_content( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return '';
	}
	$content = $post->post_content;
	$content = strip_shortcodes( $content );
	$content = excerpt_remove_blocks( $content );
	$content = wp_strip_all_tags( $content );
	$content = preg_replace( '/\s+/', ' ', $content );
	return trim( $content );
}

/**
 * Turn a permalink into its Markdown (.md) variant.
 *
 * @param string $permalink Permalink.
 * @return string
 */
function agentsteamer_ai_markdown_url( $permalink ) {
	$permalink = (string) $permalink;
	$base      = preg_split( '/[?#]/', $permalink, 2 );
	$base      = rtrim( $base[0], '/' );
	return $base . '.md';
}

/**
 * Parse newline-delimited "A || B" pairs into arrays.
 *
 * @param string $raw Raw text.
 * @return array List of [ a, b ] pairs.
 */
function agentsteamer_ai_parse_pairs( $raw ) {
	$out  = array();
	$rows = preg_split( '/\r\n|\r|\n/', (string) $raw );
	foreach ( (array) $rows as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		$parts = array_map( 'trim', explode( '||', $line, 2 ) );
		$a     = isset( $parts[0] ) ? $parts[0] : '';
		$b     = isset( $parts[1] ) ? $parts[1] : '';
		if ( '' !== $a ) {
			$out[] = array( $a, $b );
		}
	}
	return $out;
}

/**
 * Minimal, dependency-free HTML to Markdown conversion.
 *
 * @param string $html HTML.
 * @return string
 */
function agentsteamer_ai_html_to_markdown( $html ) {
	$html = (string) $html;
	$html = preg_replace( '/<!--.*?-->/s', '', $html );

	// Fenced code blocks first.
	$html = preg_replace_callback(
		'#<pre[^>]*>(.*?)</pre>#is',
		function ( $m ) {
			return "\n\n```\n" . trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) ) . "\n```\n\n";
		},
		$html
	);

	// Headings.
	for ( $i = 6; $i >= 1; $i-- ) {
		$hashes = str_repeat( '#', $i );
		$html   = preg_replace( '#<h' . $i . '[^>]*>(.*?)</h' . $i . '>#is', "\n\n{$hashes} $1\n\n", $html );
	}

	// Emphasis.
	$html = preg_replace( '#<(strong|b)[^>]*>(.*?)</\1>#is', '**$2**', $html );
	$html = preg_replace( '#<(em|i)[^>]*>(.*?)</\1>#is', '*$2*', $html );
	$html = preg_replace( '#<code[^>]*>(.*?)</code>#is', '`$1`', $html );

	// Links and images.
	$html = preg_replace_callback(
		'#<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is',
		function ( $m ) {
			return '[' . wp_strip_all_tags( $m[2] ) . '](' . $m[1] . ')';
		},
		$html
	);
	$html = preg_replace_callback(
		'#<img[^>]*>#is',
		function ( $m ) {
			if ( preg_match( '#src=["\']([^"\']+)["\']#i', $m[0], $src ) ) {
				$alt = '';
				if ( preg_match( '#alt=["\']([^"\']*)["\']#i', $m[0], $alt_match ) ) {
					$alt = $alt_match[1];
				}
				return '![' . $alt . '](' . $src[1] . ')';
			}
			return '';
		},
		$html
	);

	// Lists.
	$html = preg_replace( '#<li[^>]*>(.*?)</li>#is', "- $1\n", $html );
	$html = preg_replace( '#</?(ul|ol)[^>]*>#is', "\n", $html );
	$html = preg_replace( '#<blockquote[^>]*>(.*?)</blockquote>#is', "\n> $1\n", $html );

	// Paragraphs and breaks.
	$html = preg_replace( '#<br\s*/?>#is', "\n", $html );
	$html = preg_replace( '#</p>#is', "\n\n", $html );
	$html = preg_replace( '#<p[^>]*>#is', '', $html );

	// Strip remaining markup and decode entities.
	$html = wp_strip_all_tags( $html );
	$html = html_entity_decode( $html, ENT_QUOTES, 'UTF-8' );

	$html = preg_replace( '/[ \t]+/', ' ', $html );
	$html = preg_replace( '/\n{3,}/', "\n\n", $html );

	return trim( $html );
}

/**
 * Built-in default system prompts, keyed by task.
 *
 * @return array
 */
function agentsteamer_ai_prompt_defaults() {
	return array(
		'article'  => implode(
			"\n",
			array(
				'你是资深的中文 SEO / GEO 内容编辑。',
				'请撰写一篇结构清晰、可被搜索引擎与 AI 引用的文章，只输出正文 HTML（使用 <p><h2><h3><ul><ol><li><strong><a><blockquote> 等标签），不要输出标题、JSON、Markdown 代码围栏或任何解释性文字。',
				'写法要求：',
				'1. 开头用 1–2 句直接回答问题。',
				'2. 使用清晰的 H2/H3 小标题。',
				'3. 至少一个 <ul> 或 <ol> 列表。',
				'4. 至少一处数据/统计（如“约 60%”）；没有依据时用“多项行业调研显示”等通用表述，不要编造具体数字。',
				'5. 至少一处引述或来源说明（“据……报告”或 <blockquote>），不要编造机构名称或链接。',
				'6. 自然融入目标关键词，避免堆砌，语言流畅。',
				'7. 使用用户消息中指定的「语言」写作（该语言为中文时才用中文）。',
			)
		),
		'finalize' => implode(
			"\n",
			array(
				'根据文章正文生成元数据与结构化数据，只输出一个 JSON 对象，不要推理或解释。',
				'JSON 结构：{"title":"文章标题","excerpt":"一句话摘要","meta_title":"SEO标题(30-60字符,含关键词)","meta_description":"Meta描述(写足字数:中文120-150字/英文120-160字符,含关键词)","focus_keyword":"焦点关键词(3-12字)","tags":["标签1","标签2"],"faq":[{"q":"问题","a":"答案"}],"howto":{"name":"教程标题","steps":[{"name":"步骤名","text":"步骤说明"}]}}',
				'要求：',
				'1. 所有字符串（title/excerpt/meta_title/meta_description/focus_keyword/tags/faq/howto）必须使用**与正文相同的语言**（英文正文全部输出英文，中文正文输出中文）。',
				'2. faq 提取 3–5 组正文中真实出现的问答；若正文没有问答，返回空数组 []。',
				'3. howto：只要正文包含任何可执行信息，就整理成 HowTo（name + 2–8 步）。可执行信息包括：操作步骤、流程、方法、操作清单、注意事项、落地路径、评估维度、选型或实施建议、最佳实践等。即使正文偏概念或观点，只要给出了做法、要点或路径，也要用 name 概括（例如“如何…”“…的落地步骤”），并把正文中每条要点、维度或路径整理成一个有序步骤；步骤名简洁、说明具体，内容必须来自正文本身，可重排顺序，但不要编造正文之外的事实。仅当正文通篇没有任何可执行信息时，才返回 {"name":"","steps":[]}。',
				'4. howto 必须是对象（含 name 字符串与 steps 数组）；没有内容时写成 {"name":"","steps":[]}，不要返回 [] 或 null。',
				'不要编造正文之外的内容。',
			)
		),
		'optimize' => implode(
			"\n",
			array(
				'你是资深中文 SEO / GEO 内容编辑。请在不改变核心观点、不编造事实的前提下，重写并增强给定文章，使其更易被搜索引擎与 AI 引用。',
				'要求：',
				'1. 开头用 1–2 句直接回答问题。',
				'2. 使用清晰的 H2/H3 小标题。',
				'3. 至少一个 <ul> 或 <ol> 列表。',
				'4. 至少一处数据/统计（如“约 60%”“2024 年”）；原文没有时用“多项行业调研显示”等通用表述，不要编造具体数字。',
				'5. 至少一处引述或来源说明（“据……报告”或 <blockquote>），但不要编造机构名称、报告名或年份。',
				'6. 保留原文已有链接，并可添加指向本站的链接；不要编造外部链接。',
				'7. 自然融入焦点关键词，避免堆砌。',
				'8. 保留原文全部有效信息，不要删减主要观点。',
				'9. 使用与原文相同的语言输出（英文原文输出英文，中文原文输出中文），不要改变原文语言。',
				'严禁编造可被核实的虚假事实、机构、数据或链接。',
				'只输出一个 JSON 对象，不要推理：{"content_html":"增强后的正文HTML","summary":"一句话说明改动","changes":["改动1","改动2"]}',
			)
		),
		'meta'     => implode(
			"\n",
			array(
				'你是资深 SEO 与 GEO 专家。请根据文章内容生成 SEO 元数据，只输出一个 JSON 对象，不要输出任何推理过程或解释。',
				'JSON 结构：{"keyword":"焦点关键词","title":"SEO标题","description":"Meta描述"}',
				'硬性要求：',
				'1. keyword：优先 3–12 个字/词，必须是文章正文中真实出现过的核心词，并使用与正文相同的语言。',
				'2. title：30–60 个字符，必须完整包含 keyword，使用正文语言。',
				'3. description：写足字数（中文约 120–150 字；英文约 120–160 字符），必须完整包含 keyword，语句通顺、有吸引力、能概括全文，使用正文语言。',
				'4. 所有输出（keyword/title/description）必须使用**与文章正文相同的语言**；即使本提示词是中文，也只在正文为中文时才输出中文。',
				'请严格满足以上长度与关键词要求；description 务必写足字数。',
			)
		),
		'alt'      => '你是无障碍（a11y）与图片 SEO 专家。请为给定的图片生成一条简洁、准确、描述性的 alt 文本（约 20–60 字/词），使用**与所在文章相同的语言**（无上下文时用站点语言）。结合图片标题、文件名、说明、站点名称与所在文章上下文进行描述；信息有限时也要给出有意义的描述，不要只重复文件名。只输出 alt 文本本身，不要引号、不要解释、不要编造无法从信息推断的内容。',
		'schema'   => implode(
			"\n",
			array(
				'你是结构化数据（schema.org）专家。请从文章内容中提取可用的结构化信息，只输出一个 JSON 对象，不要输出推理或解释。',
				'JSON 结构：{"faq":[{"q":"问题","a":"答案"}],"howto":{"name":"教程标题","steps":[{"name":"步骤名","text":"步骤说明"}]}}',
				'要求：',
				'1. faq：提取 3–5 组文章中真实存在的问答；若文章不含问答，返回空数组 []。',
				'2. howto：只要文章包含任何可执行信息，就整理成 HowTo（name + 2–8 步）。可执行信息包括：操作步骤、流程、方法、操作清单、注意事项、落地路径、评估维度、选型或实施建议、最佳实践等。即使文章偏概念或观点，只要文中给出了做法、要点或路径，也要用 name 概括（例如“如何…”“…的落地步骤”“…的实施步骤”），并把文中每条要点、维度或路径整理成一个有序步骤；步骤名简洁、说明具体，内容必须来自文章本身，可重排顺序，但不要编造文章之外的事实。只有当文章通篇没有任何可执行信息时，才返回 {"name":"","steps":[]}。',
				'3. howto 必须是对象（含 name 字符串与 steps 数组）；没有内容时写成 {"name":"","steps":[]}，不要返回 [] 或 null。',
				'4. 所有文本（q/a、howto.name、steps 的 name/text）必须使用**与文章正文相同的语言**；即使本提示词是中文，也只在正文为中文时才输出中文。',
				'不要编造内容。',
			)
		),
		'tags'     => implode(
			"\n",
			array(
				'你是 SEO 内容编辑。请从文章正文中提取 5–10 个最相关的标签（关键词）。只输出一个 JSON 对象，不要推理或解释。',
				'JSON 结构：{"tags":["标签1","标签2"]}',
				'要求：',
				'1. 标签须与正文主题高度相关、简短（2–8 个字/词），不要重复、不要编造。',
				'2. 使用**与文章正文相同的语言**（英文正文输出英文标签，中文正文输出中文标签）。',
			)
		),
		'topics'   => '你是内容策略与 SEO 专家。根据站点现有主题与描述，给出可写作的选题建议，包含支柱页（pillar）与长尾文章（article）。只输出一个 JSON 对象，不要推理：{"topics":[{"title":"选题标题","type":"pillar|article","reason":"一句话理由"}]}',
	);
}

/**
 * Human-readable language label of a post, for AI prompts.
 *
 * Uses the AgentSteamer multilingual plugin when present, then Polylang / WPML,
 * falling back to the site locale.
 *
 * @param int $post_id Post id.
 * @return string e.g. "English (en)".
 */
function agentsteamer_ai_content_language_label( $post_id = 0 ) {
	$code = '';
	$name = '';

	if ( function_exists( 'agentsteamer_lang_post_lang' ) && $post_id ) {
		$code = (string) agentsteamer_lang_post_lang( $post_id );
		if ( function_exists( 'agentsteamer_lang_post_language_name' ) ) {
			$name = (string) agentsteamer_lang_post_language_name( $post_id );
		}
	}
	if ( '' === $code && $post_id && function_exists( 'pll_get_post_language' ) ) {
		$code = (string) pll_get_post_language( $post_id );
	}
	if ( '' === $code && $post_id && function_exists( 'wpml_get_language_information' ) ) {
		$info = wpml_get_language_information( null, $post_id );
		if ( is_array( $info ) && ! empty( $info['language_code'] ) ) {
			$code = (string) $info['language_code'];
		}
	}
	if ( '' === $code ) {
		$code = (string) get_bloginfo( 'language' );
	}
	if ( '' === $name ) {
		$name = $code;
	}
	return $name . ' (' . $code . ')';
}

/**
 * Base language code of a post (e.g. en / zh / ja), for choosing UI strings.
 *
 * @param int $post_id Post id.
 * @return string
 */
function agentsteamer_ai_content_language_code( $post_id = 0 ) {
	$code = '';
	if ( function_exists( 'agentsteamer_lang_post_lang' ) && $post_id ) {
		$code = (string) agentsteamer_lang_post_lang( $post_id );
	}
	if ( '' === $code && $post_id && function_exists( 'pll_get_post_language' ) ) {
		$code = (string) pll_get_post_language( $post_id );
	}
	if ( '' === $code && $post_id && function_exists( 'wpml_get_language_information' ) ) {
		$info = wpml_get_language_information( null, $post_id );
		if ( is_array( $info ) && ! empty( $info['language_code'] ) ) {
			$code = (string) $info['language_code'];
		}
	}
	if ( '' === $code ) {
		$code = (string) get_bloginfo( 'language' );
	}
	return strtolower( preg_replace( '/[-_].*$/', '', $code ) );
}

/**
 * Resolve a system prompt: use the site override when set, otherwise the default.
 *
 * @param string $key Prompt key.
 * @return string
 */
function agentsteamer_ai_prompt( $key ) {
	$defaults = agentsteamer_ai_prompt_defaults();
	$default  = isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';
	$override = agentsteamer_ai_get_option( 'prompt_' . $key, '' );
	if ( is_string( $override ) && '' !== trim( $override ) ) {
		return $override;
	}
	return $default;
}
