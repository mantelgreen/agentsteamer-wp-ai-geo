<?php
/**
 * AI generation service.
 *
 * @package AgentSteamer_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * High level AI operations: article generation, meta generation, connection test.
 */
class AgentSteamer_AI {

	/**
	 * Generate a full article from a prompt payload.
	 *
	 * @param array $params prompt, topic, keywords, audience, tone, length, language, post_type.
	 * @return array|WP_Error
	 */
	public function generate_article( array $params ) {
		if ( ! agentsteamer_ai_get_option( 'ai_enabled', 1 ) ) {
			return new WP_Error( 'agentsteamer_ai_disabled', __( 'AI 功能未启用。', 'agentsteamer-ai' ) );
		}

		$provider = AgentSteamer_AI_Provider_Manager::get_provider();
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$params = wp_parse_args(
			$params,
			array(
				'prompt'    => '',
				'topic'     => '',
				'keywords'  => '',
				'audience'  => '',
				'tone'      => '专业、可信',
				'length'    => '1200',
				'language'  => 'zh-CN',
			)
		);

		$system = $this->article_system_prompt();
		$user   = $this->article_user_prompt( $params );

		$result = $provider->chat(
			array(
				array(
					'role'    => 'system',
					'content' => $system,
				),
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
			array( 'max_tokens' => min( agentsteamer_ai_output_ceiling( 6000 ), max( 1200, (int) $params['length'] * 2 ) ), 'timeout' => 55 )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = AgentSteamer_AI_Provider_Manager::extract_json( $result['content'] );
		if ( ! is_array( $data ) || empty( $data['title'] ) || empty( $data['content_html'] ) ) {
			return new WP_Error( 'agentsteamer_ai_parse', __( '无法解析模型返回的文章结构，请重试或更换模型。', 'agentsteamer-ai' ), array( 'raw' => $result['content'] ) );
		}

		$article = array(
			'title'            => sanitize_text_field( $data['title'] ),
			'excerpt'          => isset( $data['excerpt'] ) ? sanitize_textarea_field( $data['excerpt'] ) : '',
			'content_html'     => wp_kses_post( $data['content_html'] ),
			'meta_title'       => isset( $data['meta_title'] ) ? sanitize_text_field( $data['meta_title'] ) : '',
			'meta_description' => isset( $data['meta_description'] ) ? sanitize_text_field( $data['meta_description'] ) : '',
			'focus_keyword'    => isset( $data['focus_keyword'] ) ? sanitize_text_field( $data['focus_keyword'] ) : '',
			'tags'             => array(),
			'model'            => $this->provider_label() . ' / ' . agentsteamer_ai_get_option( 'model' ),
		);

		if ( ! empty( $data['tags'] ) && is_array( $data['tags'] ) ) {
			foreach ( $data['tags'] as $tag ) {
				$tag = sanitize_text_field( $tag );
				if ( '' !== $tag ) {
					$article['tags'][] = $tag;
				}
			}
		}

		/**
		 * Filter the generated article before returning.
		 *
		 * @param array $article Article data.
		 * @param array $params  Request params.
		 */
		return apply_filters( 'agentsteamer_ai_generated_article', $article, $params );
	}

	/**
	 * Generate an SEO title/description for existing content.
	 *
	 * @param string $content Content.
	 * @param string $keyword Primary keyword.
	 * @param int    $post_id Post id (for language detection).
	 * @return array|WP_Error
	 */
	public function generate_meta( $content, $keyword = '', $post_id = 0 ) {
		$provider = AgentSteamer_AI_Provider_Manager::get_provider();
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$system = agentsteamer_ai_prompt( 'meta' );
		$lang   = agentsteamer_ai_content_language_label( $post_id );

		$user = '文章语言：' . $lang . "\n请使用该语言输出 keyword、title 与 description。\n\n";
		if ( $keyword ) {
			$user .= '指定焦点关键词（必须使用且原样出现于 title 与 description）：' . $keyword . "\n\n";
		}
		$user .= '文章内容：' . "\n" . agentsteamer_ai_trim( $content, 2000 );

		$messages = array(
			array(
				'role'    => 'system',
				'content' => $system,
			),
			array(
				'role'    => 'user',
				'content' => $user,
			),
		);

		$result = $provider->chat(
			$messages,
			array(
				'max_tokens'  => 900,
				'timeout'     => 55,
				'temperature' => 0.3,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$meta = $this->normalize_meta( AgentSteamer_AI_Provider_Manager::extract_json( $result['content'] ) );

		// One corrective retry when the output cannot satisfy the on-page checks.
		if ( ! $this->meta_is_strong( $meta['title'], $meta['description'], $meta['keyword'] ) ) {
			$messages[] = array(
				'role'    => 'assistant',
				'content' => (string) wp_json_encode( $meta, JSON_UNESCAPED_UNICODE ),
			);
			$messages[] = array(
				'role'    => 'user',
				'content' => '上一版不达标：keyword 必须原样出现在 title 与 description 中；description 必须为 130–155 个字符；title 为 30–60 个字符。请重新只输出 JSON，并严格满足全部要求。',
			);

			$retry = $provider->chat(
				$messages,
				array(
					'max_tokens'  => 900,
					'timeout'     => 55,
					'temperature' => 0.2,
				)
			);

			if ( ! is_wp_error( $retry ) ) {
				$retry_meta = $this->normalize_meta( AgentSteamer_AI_Provider_Manager::extract_json( $retry['content'] ) );
				if ( '' !== $retry_meta['title'] || '' !== $retry_meta['description'] ) {
					$meta = $retry_meta;
				}
			}
		}

		if ( '' === $meta['title'] && '' === $meta['description'] ) {
			return new WP_Error( 'agentsteamer_ai_parse', __( '无法解析模型返回的元数据。', 'agentsteamer-ai' ) );
		}

		return $meta;
	}

	/**
	 * Normalise a raw model payload into the meta array.
	 *
	 * @param mixed $data Decoded JSON.
	 * @return array
	 */
	protected function normalize_meta( $data ) {
		if ( ! is_array( $data ) ) {
			return array(
				'title'       => '',
				'description' => '',
				'keyword'     => '',
			);
		}
		return array(
			'title'       => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '',
			'description' => isset( $data['description'] ) ? sanitize_text_field( $data['description'] ) : '',
			'keyword'     => isset( $data['keyword'] ) ? sanitize_text_field( $data['keyword'] ) : '',
		);
	}

	/**
	 * Whether generated meta satisfies the on-page scoring checks.
	 *
	 * @param string $title       SEO title.
	 * @param string $description Meta description.
	 * @param string $keyword     Focus keyword.
	 * @return bool
	 */
	protected function meta_is_strong( $title, $description, $keyword ) {
		$keyword = trim( (string) $keyword );
		if ( '' === $keyword ) {
			return false;
		}
		$title       = (string) $title;
		$description = (string) $description;

		$contains = function_exists( 'mb_stripos' ) ? 'mb_stripos' : 'stripos';
		if ( false === $contains( $title, $keyword ) ) {
			return false;
		}
		if ( false === $contains( $description, $keyword ) ) {
			return false;
		}

		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $description ) : strlen( $description );
		return $len >= 110;
	}

	/**
	 * Extract FAQ / HowTo structured data from content using AI.
	 *
	 * @param string $content Content.
	 * @return array|WP_Error
	 */
	public function extract_schema( $content, $post_id = 0 ) {
		$provider = AgentSteamer_AI_Provider_Manager::get_provider();
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$system = agentsteamer_ai_prompt( 'schema' );
		$lang   = agentsteamer_ai_content_language_label( $post_id );
		$user   = '文章语言：' . $lang . "\n请使用该语言输出 q/a 与 howto 的全部文本。\n\n文章内容：\n" . agentsteamer_ai_trim( $content, 3000 );

		$messages = array(
			array(
				'role'    => 'system',
				'content' => $system,
			),
			array(
				'role'    => 'user',
				'content' => $user,
			),
		);
		$chat_args = array(
			'max_tokens'  => 2000,
			'timeout'     => 55,
			'temperature' => 0.2,
		);

		$result = $provider->chat( $messages, $chat_args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = AgentSteamer_AI_Provider_Manager::extract_json( $result['content'] );

		// One corrective retry when the model returns non-JSON (e.g. truncated output).
		if ( ! is_array( $data ) ) {
			$messages[] = array(
				'role'    => 'assistant',
				'content' => (string) $result['content'],
			);
			$messages[] = array(
				'role'    => 'user',
				'content' => '上一次输出不是合法 JSON。请只输出一个完整、合法的 JSON 对象（不要代码围栏、不要任何解释），结构为 {"faq":[{"q":"","a":""}],"howto":{"name":"","steps":[{"name":"","text":""}]}}。',
			);
			$retry = $provider->chat( $messages, $chat_args );
			if ( ! is_wp_error( $retry ) ) {
				$data = AgentSteamer_AI_Provider_Manager::extract_json( $retry['content'] );
			}
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'agentsteamer_ai_parse', __( '无法解析模型返回的结构化数据。', 'agentsteamer-ai' ) );
		}

		$faq = array();
		if ( ! empty( $data['faq'] ) && is_array( $data['faq'] ) ) {
			foreach ( $data['faq'] as $item ) {
				$q = isset( $item['q'] ) ? sanitize_text_field( $item['q'] ) : '';
				$a = isset( $item['a'] ) ? sanitize_text_field( $item['a'] ) : '';
				if ( '' !== $q && '' !== $a ) {
					$faq[] = array( 'q' => $q, 'a' => $a );
				}
			}
		}

		$howto = array(
			'name'  => '',
			'steps' => array(),
		);
		if ( ! empty( $data['howto'] ) && is_array( $data['howto'] ) ) {
			$howto['name'] = isset( $data['howto']['name'] ) ? sanitize_text_field( $data['howto']['name'] ) : '';
			if ( ! empty( $data['howto']['steps'] ) && is_array( $data['howto']['steps'] ) ) {
				foreach ( $data['howto']['steps'] as $step ) {
					$n = isset( $step['name'] ) ? sanitize_text_field( $step['name'] ) : '';
					$t = isset( $step['text'] ) ? sanitize_text_field( $step['text'] ) : '';
					if ( '' !== $n || '' !== $t ) {
						$howto['steps'][] = array( 'name' => $n, 'text' => $t );
					}
				}
			}
		}

		return array(
			'faq'   => $faq,
			'howto' => $howto,
		);
	}

	/**
	 * Extract relevant tags (keywords) from content using AI, in the article's language.
	 *
	 * @param string $content Content.
	 * @param int    $post_id Post id (for language detection).
	 * @return array|WP_Error List of tag names.
	 */
	public function extract_tags( $content, $post_id = 0 ) {
		$provider = AgentSteamer_AI_Provider_Manager::get_provider();
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$system = agentsteamer_ai_prompt( 'tags' );
		$lang   = agentsteamer_ai_content_language_label( $post_id );
		$user   = '文章语言：' . $lang . "\n\n文章内容：\n" . agentsteamer_ai_trim( $content, 3000 );

		$messages = array(
			array(
				'role'    => 'system',
				'content' => $system,
			),
			array(
				'role'    => 'user',
				'content' => $user,
			),
		);
		$chat_args = array(
			'max_tokens'  => 700,
			'timeout'     => 55,
			'temperature' => 0.3,
		);

		$result = $provider->chat( $messages, $chat_args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = AgentSteamer_AI_Provider_Manager::extract_json( $result['content'] );
		if ( ! is_array( $data ) || empty( $data['tags'] ) || ! is_array( $data['tags'] ) ) {
			$messages[] = array(
				'role'    => 'assistant',
				'content' => (string) $result['content'],
			);
			$messages[] = array(
				'role'    => 'user',
				'content' => '上一次输出不是合法 JSON。请只输出一个完整、合法的 JSON 对象（不要代码围栏、不要任何解释），结构为 {"tags":["标签1","标签2"]}。',
			);
			$retry = $provider->chat( $messages, $chat_args );
			if ( ! is_wp_error( $retry ) ) {
				$data = AgentSteamer_AI_Provider_Manager::extract_json( $retry['content'] );
			}
		}

		if ( ! is_array( $data ) || empty( $data['tags'] ) || ! is_array( $data['tags'] ) ) {
			return new WP_Error( 'agentsteamer_ai_parse', __( '无法解析模型返回的标签。', 'agentsteamer-ai' ) );
		}

		$tags = array();
		foreach ( $data['tags'] as $tag ) {
			$tag = sanitize_text_field( $tag );
			if ( '' !== $tag && ! in_array( $tag, $tags, true ) ) {
				$tags[] = $tag;
			}
		}
		return $tags;
	}

	/**
	 * Stream an article body from the model and finalize its metadata.
	 *
	 * @param array         $params   Generation params.
	 * @param callable|null $on_delta Receives each text delta.
	 * @return array|WP_Error
	 */
	public function stream_article( array $params, $on_delta = null ) {
		if ( ! agentsteamer_ai_get_option( 'ai_enabled', 1 ) ) {
			return new WP_Error( 'agentsteamer_ai_disabled', __( 'AI 功能未启用。', 'agentsteamer-ai' ) );
		}

		$provider = AgentSteamer_AI_Provider_Manager::get_provider();
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$params = wp_parse_args(
			$params,
			array(
				'prompt'   => '',
				'topic'    => '',
				'keywords' => '',
				'audience' => '',
				'tone'     => '专业、可信',
				'length'   => '1200',
				'language' => 'zh-CN',
			)
		);

		$system = agentsteamer_ai_prompt( 'article' );

		$user = $this->article_user_prompt( $params );

		$result = $provider->chat_stream(
			array(
				array(
					'role'    => 'system',
					'content' => $system,
				),
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
			array(
				'max_tokens'  => min( agentsteamer_ai_output_ceiling( 8000 ), max( 2000, (int) $params['length'] * 2 ) ),
				'timeout'     => 120,
				'temperature' => 0.7,
			),
			$on_delta
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$body = $this->clean_body( $result['content'] );
		if ( '' === $body ) {
			return new WP_Error( 'agentsteamer_ai_empty', __( '模型没有返回正文内容。', 'agentsteamer-ai' ) );
		}

		$meta = $this->finalize_article( $body, $params['language'] );
		if ( is_wp_error( $meta ) ) {
			$meta = array(
				'title'            => wp_trim_words( wp_strip_all_tags( $body ), 16, '' ),
				'excerpt'          => '',
				'meta_title'       => '',
				'meta_description' => '',
				'focus_keyword'    => $params['keywords'],
				'tags'             => array(),
				'faq'              => array(),
				'howto'            => array(
					'name'  => '',
					'steps' => array(),
				),
			);
		}

		$meta['content_html'] = wp_kses_post( $body );
		return $meta;
	}

	/**
	 * Derive title / excerpt / meta / keyword / tags from an article body.
	 *
	 * @param string $body_html Body HTML.
	 * @param string $language  Target language code/label (optional).
	 * @return array|WP_Error
	 */
	public function finalize_article( $body_html, $language = '' ) {
		$provider = AgentSteamer_AI_Provider_Manager::get_provider();
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$system = agentsteamer_ai_prompt( 'finalize' );
		$lang   = '' !== trim( (string) $language ) ? $language : get_bloginfo( 'language' );
		$user   = '文章语言：' . $lang . "\n请使用该语言输出全部字段。\n\n正文：" . agentsteamer_ai_trim( wp_strip_all_tags( $body_html ), 4000 );

		$result = $provider->chat(
			array(
				array(
					'role'    => 'system',
					'content' => $system,
				),
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
			array(
				'max_tokens'  => 1500,
				'timeout'     => 55,
				'temperature' => 0.3,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = AgentSteamer_AI_Provider_Manager::extract_json( $result['content'] );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'agentsteamer_ai_parse', __( '无法解析模型返回的元数据。', 'agentsteamer-ai' ) );
		}

		$tags = array();
		if ( ! empty( $data['tags'] ) && is_array( $data['tags'] ) ) {
			foreach ( $data['tags'] as $tag ) {
				$tag = sanitize_text_field( $tag );
				if ( '' !== $tag ) {
					$tags[] = $tag;
				}
			}
		}

		$faq = array();
		if ( ! empty( $data['faq'] ) && is_array( $data['faq'] ) ) {
			foreach ( $data['faq'] as $item ) {
				$q = isset( $item['q'] ) ? sanitize_text_field( $item['q'] ) : '';
				$a = isset( $item['a'] ) ? sanitize_text_field( $item['a'] ) : '';
				if ( '' !== $q && '' !== $a ) {
					$faq[] = array( 'q' => $q, 'a' => $a );
				}
			}
		}

		$howto = array(
			'name'  => '',
			'steps' => array(),
		);
		if ( ! empty( $data['howto'] ) && is_array( $data['howto'] ) ) {
			$howto['name'] = isset( $data['howto']['name'] ) ? sanitize_text_field( $data['howto']['name'] ) : '';
			if ( ! empty( $data['howto']['steps'] ) && is_array( $data['howto']['steps'] ) ) {
				foreach ( $data['howto']['steps'] as $step ) {
					$n = isset( $step['name'] ) ? sanitize_text_field( $step['name'] ) : '';
					$t = isset( $step['text'] ) ? sanitize_text_field( $step['text'] ) : '';
					if ( '' !== $n || '' !== $t ) {
						$howto['steps'][] = array( 'name' => $n, 'text' => $t );
					}
				}
			}
		}

		return array(
			'title'            => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '',
			'excerpt'          => isset( $data['excerpt'] ) ? sanitize_text_field( $data['excerpt'] ) : '',
			'meta_title'       => isset( $data['meta_title'] ) ? sanitize_text_field( $data['meta_title'] ) : '',
			'meta_description' => isset( $data['meta_description'] ) ? sanitize_text_field( $data['meta_description'] ) : '',
			'focus_keyword'    => isset( $data['focus_keyword'] ) ? sanitize_text_field( $data['focus_keyword'] ) : '',
			'tags'             => $tags,
			'faq'              => $faq,
			'howto'            => $howto,
		);
	}

	/**
	 * Normalise a streamed body into clean HTML.
	 *
	 * @param string $html Body.
	 * @return string
	 */
	protected function clean_body( $html ) {
		$html = trim( (string) $html );
		$html = preg_replace( '/^```[a-z]*\s*/i', '', $html );
		$html = preg_replace( '/\s*```\s*$/', '', $html );
		$html = trim( $html );
		if ( '' === $html ) {
			return '';
		}
		if ( false === strpos( $html, '<' ) ) {
			$out = array();
			foreach ( preg_split( '/\n{2,}/', $html ) as $para ) {
				$para = trim( $para );
				if ( '' !== $para ) {
					$out[] = '<p>' . esc_html( $para ) . '</p>';
				}
			}
			return implode( "\n", $out );
		}
		return $html;
	}

	/**
	 * Generate an enhanced version of a post's content (for the review queue).
	 *
	 * @param int $post_id Post ID.
	 * @return array|WP_Error  [ content_html, summary, changes ].
	 */
	public function optimize_content( $post_id ) {
		$provider = AgentSteamer_AI_Provider_Manager::get_provider();
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'agentsteamer_ai_no_post', __( '文章不存在。', 'agentsteamer-ai' ) );
		}

		$plain = agentsteamer_ai_plain_content( $post_id );
		$plain_len = function_exists( 'mb_strlen' ) ? mb_strlen( $plain ) : strlen( $plain );
		if ( $plain_len < 50 ) {
			return new WP_Error( 'agentsteamer_ai_too_short', __( '文章内容过短，无法优化。', 'agentsteamer-ai' ) );
		}

		$keyword = agentsteamer_ai_get_post_meta( $post_id, 'focus_keyword' );

		$system = agentsteamer_ai_prompt( 'optimize' );

		$raw = (string) $post->post_content;
		$max = 8000;
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $raw ) : strlen( $raw );
		if ( $len > $max ) {
			$raw = ( function_exists( 'mb_substr' ) ? mb_substr( $raw, 0, $max ) : substr( $raw, 0, $max ) ) . "\n<!-- 内容已截断 -->";
		}

		$lang = agentsteamer_ai_content_language_label( $post_id );
		$user = '文章语言：' . $lang . "\n请使用该语言输出 content_html、summary 与 changes。\n\n焦点关键词：" . ( $keyword ? $keyword : '（无）' ) . "\n\n原文 HTML：\n" . $raw;

		$result = $provider->chat(
			array(
				array(
					'role'    => 'system',
					'content' => $system,
				),
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
			array(
				'max_tokens'  => agentsteamer_ai_output_ceiling( 6000 ),
				'timeout'     => 55,
				'temperature' => 0.5,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = AgentSteamer_AI_Provider_Manager::extract_json( $result['content'] );
		if ( ! is_array( $data ) || empty( $data['content_html'] ) ) {
			return new WP_Error( 'agentsteamer_ai_parse', __( '无法解析模型返回的优化内容。', 'agentsteamer-ai' ) );
		}

		$changes = array();
		if ( ! empty( $data['changes'] ) && is_array( $data['changes'] ) ) {
			foreach ( $data['changes'] as $change ) {
				$change = sanitize_text_field( $change );
				if ( '' !== $change ) {
					$changes[] = $change;
				}
			}
		}

		return array(
			'content_html' => wp_kses_post( $data['content_html'] ),
			'summary'      => isset( $data['summary'] ) ? sanitize_text_field( $data['summary'] ) : '',
			'changes'      => $changes,
		);
	}

	/**
	 * Generate alt text for an image attachment using AI.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|WP_Error
	 */
	public function generate_alt( $attachment_id ) {
		$provider = AgentSteamer_AI_Provider_Manager::get_provider();
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$attachment = get_post( $attachment_id );
		if ( ! $attachment ) {
			return new WP_Error( 'agentsteamer_ai_no_attachment', __( '图片不存在。', 'agentsteamer-ai' ) );
		}

		$file     = get_attached_file( $attachment_id );
		$filename = $file ? basename( $file ) : '';
		$parent   = (int) $attachment->post_parent;
		$context  = '';
		if ( $parent ) {
			$context = get_the_title( $parent ) . '：' . wp_trim_words( agentsteamer_ai_plain_content( $parent ), 40, '…' );
		}

		$system = agentsteamer_ai_prompt( 'alt' );
		$lang   = agentsteamer_ai_content_language_label( $parent );
		$user   = '语言：' . $lang . "\n站点名称：" . get_bloginfo( 'name' ) . "\n图片标题：" . $attachment->post_title . "\n文件名：" . $filename . "\n图片说明：" . $attachment->post_excerpt . "\n所在文章：" . $context;

		$result = $provider->chat(
			array(
				array(
					'role'    => 'system',
					'content' => $system,
				),
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
			array(
				'max_tokens'  => 160,
				'timeout'     => 45,
				'temperature' => 0.3,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$alt = trim( sanitize_text_field( wp_strip_all_tags( $result['content'] ) ) );
		$alt = trim( $alt, " \t\n\r\0\x0B\"'“”‘’" );
		if ( '' === $alt ) {
			return new WP_Error( 'agentsteamer_ai_empty', __( '模型返回了空的 Alt 文本。', 'agentsteamer-ai' ) );
		}
		return $alt;
	}

	/**
	 * Suggest internal links for a post (taxonomy + keyword overlap; no external calls).
	 *
	 * @param int $post_id Post ID.
	 * @param int $limit   Max suggestions.
	 * @return array
	 */
	public function suggest_links( $post_id, $limit = 6 ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$candidates = get_posts(
			array(
				'post_type'     => agentsteamer_ai_supported_post_types(),
				'post_status'   => 'publish',
				'numberposts'   => 300,
				'post__not_in'  => array( (int) $post_id ),
				'no_found_rows' => true,
			)
		);

		// Keep suggestions within the same language when the multilingual plugin is present.
		$source_lang  = function_exists( 'agentsteamer_lang_post_lang' ) ? (string) agentsteamer_lang_post_lang( $post_id ) : '';
		$can_lang_get = function_exists( 'agentsteamer_lang_post_lang' );

		$scored = array();
		foreach ( $candidates as $candidate ) {
			if ( false !== strpos( (string) $post->post_content, get_permalink( $candidate ) ) ) {
				continue; // already linked.
			}
			if ( '' !== $source_lang && $can_lang_get ) {
				$candidate_lang = (string) agentsteamer_lang_post_lang( $candidate->ID );
				if ( '' !== $candidate_lang && $candidate_lang !== $source_lang ) {
					continue; // different language.
				}
			}
			$score = $this->link_score( $post, $candidate );
			if ( $score < 3 ) {
				continue;
			}
			$candidate_focus = agentsteamer_ai_get_post_meta( $candidate->ID, 'focus_keyword' );
			$anchor          = $candidate->post_title;
			if ( '' !== $candidate_focus && false !== mb_stripos( (string) $post->post_content, $candidate_focus ) ) {
				$anchor = $candidate_focus;
			}
			$scored[] = array(
				'id'     => $candidate->ID,
				'title'  => $candidate->post_title,
				'url'    => get_permalink( $candidate ),
				'anchor' => $anchor,
				'score'  => $score,
			);
		}

		usort(
			$scored,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		return array_slice( $scored, 0, max( 1, (int) $limit ) );
	}

	/**
	 * Relevance score between two posts.
	 *
	 * @param WP_Post $source    Source.
	 * @param WP_Post $candidate Candidate.
	 * @return float
	 */
	protected function link_score( $source, $candidate ) {
		$score = 0.0;

		$s_tags = wp_get_post_tags( $source->ID, array( 'fields' => 'names' ) );
		$c_tags = wp_get_post_tags( $candidate->ID, array( 'fields' => 'names' ) );
		$score += 3 * count( array_intersect( (array) $s_tags, (array) $c_tags ) );

		$s_cats = wp_get_post_categories( $source->ID );
		$c_cats = wp_get_post_categories( $candidate->ID );
		$score += 2 * count( array_intersect( (array) $s_cats, (array) $c_cats ) );

		$focus = agentsteamer_ai_get_post_meta( $source->ID, 'focus_keyword' );
		if ( '' !== $focus ) {
			if ( false !== mb_stripos( (string) $candidate->post_title, $focus ) ) {
				$score += 3;
			}
			if ( false !== mb_stripos( (string) $candidate->post_content, $focus ) ) {
				$score += 1;
			}
		}

		$c_focus  = agentsteamer_ai_get_post_meta( $candidate->ID, 'focus_keyword' );
		$s_joined = $source->post_title . ' ' . $source->post_content;
		if ( '' !== $c_focus && false !== mb_stripos( $s_joined, $c_focus ) ) {
			$score += 2;
		}

		$score += $this->bigram_overlap( $source->post_title, $candidate->post_title );

		return $score;
	}

	/**
	 * Count shared 2-grams between two short strings.
	 *
	 * @param string $a First.
	 * @param string $b Second.
	 * @return int
	 */
	protected function bigram_overlap( $a, $b ) {
		$a = preg_replace( '/[\s\p{P}]+/u', '', (string) $a );
		$b = preg_replace( '/[\s\p{P}]+/u', '', (string) $b );
		$len_a = function_exists( 'mb_strlen' ) ? mb_strlen( $a ) : strlen( $a );
		if ( $len_a < 2 ) {
			return 0;
		}
		$grams = array();
		for ( $i = 0; $i < $len_a - 1; $i++ ) {
			$grams[] = function_exists( 'mb_substr' ) ? mb_substr( $a, $i, 2 ) : substr( $a, $i, 2 );
		}
		$shared = 0;
		foreach ( array_unique( $grams ) as $gram ) {
			if ( false !== mb_stripos( $b, $gram ) ) {
				$shared++;
			}
		}
		return $shared;
	}

	/**
	 * Suggest content topics / clusters using AI.
	 *
	 * @param string $context Context description.
	 * @param int    $count   Number of suggestions.
	 * @return array|WP_Error
	 */
	public function suggest_topics( $context, $count = 8 ) {
		$provider = AgentSteamer_AI_Provider_Manager::get_provider();
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$system = agentsteamer_ai_prompt( 'topics' );
		$user   = $context . "\n\n请给出 " . (int) $count . ' 个选题。';

		$result = $provider->chat(
			array(
				array(
					'role'    => 'system',
					'content' => $system,
				),
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
			array(
				'max_tokens'  => 1200,
				'timeout'     => 55,
				'temperature' => 0.6,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = AgentSteamer_AI_Provider_Manager::extract_json( $result['content'] );
		if ( ! is_array( $data ) || empty( $data['topics'] ) || ! is_array( $data['topics'] ) ) {
			return new WP_Error( 'agentsteamer_ai_parse', __( '无法解析模型返回的选题建议。', 'agentsteamer-ai' ) );
		}

		$topics = array();
		foreach ( $data['topics'] as $topic ) {
			$title = isset( $topic['title'] ) ? sanitize_text_field( $topic['title'] ) : '';
			if ( '' === $title ) {
				continue;
			}
			$topics[] = array(
				'title'  => $title,
				'type'   => isset( $topic['type'] ) ? sanitize_text_field( $topic['type'] ) : 'article',
				'reason' => isset( $topic['reason'] ) ? sanitize_text_field( $topic['reason'] ) : '',
			);
		}
		return $topics;
	}

	/**
	 * Fill blank SEO fields for a post (used by the background autofill task).
	 *
	 * @param int $post_id Post ID.
	 * @return bool Whether anything was written.
	 */
	public function autofill_post( $post_id ) {
		$result = $this->fill_post( $post_id, array( 'seo' => true, 'schema' => false ) );
		return ! empty( $result['filled'] );
	}

	/**
	 * Fill blank SEO fields and (optionally) FAQ / HowTo structured data.
	 *
	 * Only blank fields are written; already-present values are skipped.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $scope   Flags: seo (bool), schema (bool).
	 * @return array {id, title, filled[], skipped[], error}
	 */
	public function fill_post( $post_id, $scope = array() ) {
		$scope = wp_parse_args( $scope, array( 'seo' => true, 'schema' => false ) );
		$out   = array(
			'id'      => (int) $post_id,
			'title'   => '',
			'filled'  => array(),
			'skipped' => array(),
			'error'   => '',
		);

		if ( ! agentsteamer_ai_get_option( 'ai_enabled', 1 ) || ! AgentSteamer_AI_Provider_Manager::is_configured() ) {
			$out['error'] = __( 'AI 未启用或接口未配置。', 'agentsteamer-ai' );
			return $out;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			$out['error'] = __( '内容不存在。', 'agentsteamer-ai' );
			return $out;
		}
		$out['title'] = '' !== (string) $post->post_title ? $post->post_title : ( '#' . $post->ID );

		$content = agentsteamer_ai_plain_content( $post_id );
		if ( '' === trim( $content ) ) {
			$content = (string) $post->post_title;
		}
		if ( '' === trim( $content ) ) {
			$out['error'] = __( '内容为空。', 'agentsteamer-ai' );
			return $out;
		}

		if ( ! empty( $scope['seo'] ) ) {
			$title = agentsteamer_ai_get_post_meta( $post_id, 'title' );
			$desc  = agentsteamer_ai_get_post_meta( $post_id, 'description' );
			$kw    = agentsteamer_ai_get_post_meta( $post_id, 'focus_keyword' );
			if ( $title ) {
				$out['skipped'][] = 'title';
			}
			if ( $desc ) {
				$out['skipped'][] = 'description';
			}
			if ( $kw ) {
				$out['skipped'][] = 'focus_keyword';
			}

			if ( ! $title || ! $desc || ! $kw ) {
				$meta = $this->generate_meta( $content, $kw, $post_id );
				if ( is_wp_error( $meta ) ) {
					$out['error'] = $meta->get_error_message();
				} else {
					if ( ! $title && ! empty( $meta['title'] ) ) {
						update_post_meta( $post_id, agentsteamer_ai_meta_key( 'title' ), $meta['title'] );
						$out['filled'][] = 'title';
					}
					if ( ! $desc && ! empty( $meta['description'] ) ) {
						update_post_meta( $post_id, agentsteamer_ai_meta_key( 'description' ), $meta['description'] );
						$out['filled'][] = 'description';
					}
					if ( ! $kw && ! empty( $meta['keyword'] ) ) {
						update_post_meta( $post_id, agentsteamer_ai_meta_key( 'focus_keyword' ), $meta['keyword'] );
						$out['filled'][] = 'focus_keyword';
					}
				}
			}
		}

		if ( ! empty( $scope['schema'] ) ) {
			$faq_now   = agentsteamer_ai_get_post_meta( $post_id, 'schema_faq' );
			$howto_now = agentsteamer_ai_get_post_meta( $post_id, 'schema_howto' );
			if ( $faq_now ) {
				$out['skipped'][] = 'faq';
			}
			if ( $howto_now ) {
				$out['skipped'][] = 'howto';
			}

			if ( '' === $faq_now && '' === $howto_now ) {
				$schema = $this->extract_schema( $content, $post_id );
				if ( is_wp_error( $schema ) ) {
					if ( '' === $out['error'] ) {
						$out['error'] = $schema->get_error_message();
					}
				} else {
					if ( ! empty( $schema['faq'] ) ) {
						$lines = array();
						foreach ( $schema['faq'] as $item ) {
							$lines[] = $item['q'] . ' || ' . $item['a'];
						}
						update_post_meta( $post_id, agentsteamer_ai_meta_key( 'schema_faq' ), implode( "\n", $lines ) );
						$out['filled'][] = 'faq';
					}
					if ( ! empty( $schema['howto']['steps'] ) ) {
						$lines = array();
						foreach ( $schema['howto']['steps'] as $step ) {
							$lines[] = $step['name'] . ' || ' . $step['text'];
						}
						update_post_meta( $post_id, agentsteamer_ai_meta_key( 'schema_howto' ), implode( "\n", $lines ) );
						if ( ! empty( $schema['howto']['name'] ) ) {
							update_post_meta( $post_id, agentsteamer_ai_meta_key( 'schema_howto_name' ), $schema['howto']['name'] );
						}
						$out['filled'][] = 'howto';
					}
				}
			}
		}

		return $out;
	}

	/**
	 * Create a draft post from generated article data.
	 *
	 * @param array $article Article data.
	 * @param array $args    post_type, status, author.
	 * @return int|WP_Error
	 */
	public function create_draft( array $article, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'post_type'   => 'post',
				'post_status' => 'draft',
				'post_author' => get_current_user_id(),
			)
		);

		$post_id = wp_insert_post(
			array(
				'post_title'   => $article['title'],
				'post_content' => $article['content_html'],
				'post_excerpt' => $article['excerpt'],
				'post_status'  => $args['post_status'],
				'post_type'    => post_type_exists( $args['post_type'] ) ? $args['post_type'] : 'post',
				'post_author'  => $args['post_author'],
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( ! empty( $article['meta_title'] ) ) {
			update_post_meta( $post_id, agentsteamer_ai_meta_key( 'title' ), $article['meta_title'] );
		}
		if ( ! empty( $article['meta_description'] ) ) {
			update_post_meta( $post_id, agentsteamer_ai_meta_key( 'description' ), $article['meta_description'] );
		}
		if ( ! empty( $article['focus_keyword'] ) ) {
			update_post_meta( $post_id, agentsteamer_ai_meta_key( 'focus_keyword' ), $article['focus_keyword'] );
		}
		if ( ! empty( $article['tags'] ) ) {
			wp_set_post_terms( $post_id, $article['tags'], 'post_tag', true );
		}
		if ( ! empty( $article['faq'] ) && is_array( $article['faq'] ) ) {
			$lines = array();
			foreach ( $article['faq'] as $item ) {
				$q = isset( $item['q'] ) ? sanitize_text_field( $item['q'] ) : '';
				$a = isset( $item['a'] ) ? sanitize_text_field( $item['a'] ) : '';
				if ( '' !== $q && '' !== $a ) {
					$lines[] = $q . ' || ' . $a;
				}
			}
			if ( $lines ) {
				update_post_meta( $post_id, agentsteamer_ai_meta_key( 'schema_faq' ), implode( "\n", $lines ) );
			}
		}
		if ( ! empty( $article['howto']['steps'] ) && is_array( $article['howto']['steps'] ) ) {
			$lines = array();
			foreach ( $article['howto']['steps'] as $step ) {
				$n = isset( $step['name'] ) ? sanitize_text_field( $step['name'] ) : '';
				$t = isset( $step['text'] ) ? sanitize_text_field( $step['text'] ) : '';
				if ( '' !== $n || '' !== $t ) {
					$lines[] = $n . ' || ' . $t;
				}
			}
			if ( $lines ) {
				update_post_meta( $post_id, agentsteamer_ai_meta_key( 'schema_howto' ), implode( "\n", $lines ) );
				$name = isset( $article['howto']['name'] ) ? sanitize_text_field( $article['howto']['name'] ) : '';
				if ( '' !== $name ) {
					update_post_meta( $post_id, agentsteamer_ai_meta_key( 'schema_howto_name' ), $name );
				}
			}
		}

		return $post_id;
	}

	/**
	 * Test the configured provider.
	 *
	 * @return array|WP_Error [ 'message' => string, 'elapsed' => float ].
	 */
	public function test_connection() {
		$provider = AgentSteamer_AI_Provider_Manager::get_provider();
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$start  = microtime( true );
		$result = $provider->chat(
			array(
				array(
					'role'    => 'user',
					'content' => '请只回复两个字：正常',
				),
			),
			array( 'max_tokens' => 32, 'temperature' => 0 )
		);
		$elapsed = round( microtime( true ) - $start, 2 );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'message' => trim( $result['content'] ),
			'elapsed' => $elapsed,
		);
	}

	/**
	 * Provider label helper.
	 *
	 * @return string
	 */
	protected function provider_label() {
		$provider = AgentSteamer_AI_Provider_Manager::get_provider();
		return is_wp_error( $provider ) ? 'N/A' : $provider->get_label();
	}

	/**
	 * Article system prompt.
	 *
	 * @return string
	 */
	protected function article_system_prompt() {
		return implode(
			"\n",
			array(
				'你是一位资深的中文 SEO 与 GEO（生成式引擎优化）内容编辑。',
				'请撰写结构清晰、可信、便于被搜索引擎与 AI 引用的事实型文章。写作要求：',
				'1. 先确定一个 3–12 字的焦点关键词，并在标题与正文第一段中原样出现该关键词。',
				'2. 使用清晰的 H2/H3 小标题组织结构，开头用 1-2 句直接回答主题（便于被 AI 提取）。',
				'3. 至少包含一个 <ul> 或 <ol> 列表。',
				'4. 正文中至少出现一处具体数据或统计（如“约 60%”“2024 年”等）。',
				'5. 正文中至少出现一处引述或来源说明（如“据……报告”“<blockquote>……</blockquote>”），不确定的信息不要编造。',
				'6. 正文中至少包含一个 <a href="..."> 链接（可指向本站首页或相关主题）。',
				'7. 语言自然流畅，避免关键词堆砌。',
				'8. 使用用户消息中指定的「语言」写作（该语言为中文时才用中文）。',
				'9. 只输出一个 JSON 对象，不要输出任何解释性文字或 Markdown 代码围栏。',
				'JSON 结构：{"title":"文章标题","excerpt":"一句话摘要","content_html":"正文HTML（使用 <p><h2><h3><ul><ol><li><strong><a><blockquote> 等标签）","meta_title":"SEO标题(30-60字符,含焦点关键词)","meta_description":"SEO描述(写足字数:中文120-150字/英文120-160字符,含焦点关键词)","focus_keyword":"焦点关键词","tags":["标签1","标签2"]}',
			)
		);
	}

	/**
	 * Article user prompt.
	 *
	 * @param array $params Params.
	 * @return string
	 */
	protected function article_user_prompt( array $params ) {
		$lines   = array();
		$lines[] = '主题：' . ( $params['topic'] ? $params['topic'] : $params['prompt'] );
		if ( $params['prompt'] && $params['prompt'] !== $params['topic'] ) {
			$lines[] = '补充要求：' . $params['prompt'];
		}
		if ( $params['keywords'] ) {
			$lines[] = '目标关键词：' . $params['keywords'];
		}
		if ( $params['audience'] ) {
			$lines[] = '目标读者：' . $params['audience'];
		}
		$lines[] = '语气风格：' . $params['tone'];
		$lines[] = '目标字数：约 ' . (int) $params['length'] . ' 字';
		$lines[] = '语言：' . $params['language'];
		return implode( "\n", $lines );
	}
}
