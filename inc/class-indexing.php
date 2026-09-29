<?php
/**
 * Search engine indexing submission (IndexNow + Baidu push).
 *
 * @package AgentSteamer_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves the IndexNow key file, submits URLs on publish/update, and offers a manual panel.
 */
class AgentSteamer_AI_Indexing {

	/**
	 * Log option name.
	 */
	const LOG_OPTION = 'agentsteamer_ai_index_log';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'rewrites' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'render_key' ), 1 );
		add_filter( 'redirect_canonical', array( $this, 'prevent_canonical' ), 10, 2 );

		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( 'save_post', array( $this, 'on_save' ), 25, 3 );

		add_action( 'agentsteamer_ai_submit_urls', array( $this, 'run_submit_urls' ), 10, 2 );

		add_action( 'admin_post_agentsteamer_ai_submit_all', array( $this, 'handle_submit_all' ) );
		add_action( 'admin_post_agentsteamer_ai_clear_index_log', array( $this, 'handle_clear_log' ) );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		}
	}

	/**
	 * Generate a new IndexNow key.
	 *
	 * @return string
	 */
	public static function generate_key() {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Register the key-file rewrite rule.
	 */
	public function rewrites() {
		// One-time rewrite flush when the rule scheme changes (key-specific rule).
		if ( '2' !== get_option( 'agentsteamer_ai_indexnow_rules' ) ) {
			update_option( 'agentsteamer_ai_indexnow_rules', '2', false );
			update_option( 'agentsteamer_ai_flush_rewrites', 1 );
		}

		$key = (string) agentsteamer_ai_get_option( 'indexnow_key' );
		if ( '' === $key || ! preg_match( '/^[A-Za-z0-9-]{8,128}$/', $key ) ) {
			return;
		}
		add_rewrite_rule( '^(' . preg_quote( $key, '/' ) . ')\.txt$', 'index.php?asi_indexnow_key=$matches[1]', 'top' );
	}

	/**
	 * Query var.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public function query_vars( $vars ) {
		$vars[] = 'asi_indexnow_key';
		return $vars;
	}

	/**
	 * Prevent canonical redirects on the key endpoint.
	 *
	 * @param string|false $redirect  Redirect.
	 * @param string       $requested Requested.
	 * @return string|false
	 */
	public function prevent_canonical( $redirect, $requested ) {
		if ( get_query_var( 'asi_indexnow_key' ) ) {
			return false;
		}
		return $redirect;
	}

	/**
	 * Output the key file when its name matches the configured key.
	 */
	public function render_key() {
		$key = get_query_var( 'asi_indexnow_key' );
		if ( ! $key ) {
			return;
		}

		$configured = (string) agentsteamer_ai_get_option( 'indexnow_key' );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );

		if ( '' !== $configured && hash_equals( $configured, $key ) ) {
			echo $configured; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		status_header( 404 );
		echo 'Not Found';
		exit;
	}

	/**
	 * Auto-submit on status transitions to publish.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public function on_transition( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		$this->submit_post_now( $post );
	}

	/**
	 * Auto-submit on updates to published posts.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @param bool    $update  Update flag.
	 */
	public function on_save( $post_id, $post, $update ) {
		if ( ! $update || ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		$this->submit_post_now( $post );
	}

	/**
	 * Submit a post to the configured engines immediately (on publish/update).
	 *
	 * @param WP_Post $post Post.
	 */
	protected function submit_post_now( $post ) {
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, agentsteamer_ai_supported_post_types(), true ) ) {
			return;
		}
		if ( ! agentsteamer_ai_get_option( 'indexnow_auto', 1 ) ) {
			return;
		}
		if ( ! $this->is_configured() ) {
			return;
		}

		// Throttle: skip if this post was queued in the last 60 seconds.
		$throttle = 'agentsteamer_ai_sub_' . (int) $post->ID;
		if ( get_transient( $throttle ) ) {
			return;
		}
		set_transient( $throttle, 1, 60 );

		$url = get_permalink( $post->ID );
		if ( $url ) {
			$this->schedule_submit( array( $url ), 'publish:post#' . $post->ID );
		}
	}

	/**
	 * Queue URLs for background submission so publishing/updating stays instant.
	 *
	 * @param array  $urls    URLs.
	 * @param string $context Context label.
	 */
	protected function schedule_submit( array $urls, $context ) {
		$urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) ) );
		if ( empty( $urls ) ) {
			return;
		}
		if ( ! wp_next_scheduled( 'agentsteamer_ai_submit_urls', array( $urls, $context ) ) ) {
			wp_schedule_single_event( time() + 5, 'agentsteamer_ai_submit_urls', array( $urls, $context ) );
		}
		if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
			spawn_cron();
		}
	}

	/**
	 * Cron handler: submit queued URLs (runs in the background).
	 *
	 * @param array  $urls    URLs.
	 * @param string $context Context.
	 */
	public function run_submit_urls( $urls, $context = 'cron' ) {
		$this->submit_urls( (array) $urls, $context );
	}

	/**
	 * Whether at least one engine is configured.
	 *
	 * @return bool
	 */
	public function is_configured() {
		$indexnow = agentsteamer_ai_get_option( 'indexnow_enabled', 0 ) && agentsteamer_ai_get_option( 'indexnow_key' );
		$baidu    = agentsteamer_ai_get_option( 'baidu_enabled', 0 ) && ( agentsteamer_ai_get_option( 'baidu_endpoint' ) || agentsteamer_ai_get_option( 'baidu_token' ) );
		return (bool) ( $indexnow || $baidu );
	}

	/**
	 * Submit a list of URLs to all configured engines.
	 *
	 * @param array  $urls    URLs.
	 * @param string $context Context label.
	 * @return array
	 */
	public function submit_urls( array $urls, $context = 'manual' ) {
		$urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) ) );
		if ( empty( $urls ) ) {
			return array();
		}

		$results = array();

		if ( agentsteamer_ai_get_option( 'indexnow_enabled', 0 ) && agentsteamer_ai_get_option( 'indexnow_key' ) ) {
			$results['indexnow'] = $this->submit_indexnow( $urls );
		}
		if ( agentsteamer_ai_get_option( 'baidu_enabled', 0 ) && ( agentsteamer_ai_get_option( 'baidu_endpoint' ) || agentsteamer_ai_get_option( 'baidu_token' ) ) ) {
			$results['baidu'] = $this->submit_baidu( $urls );
		}

		$this->add_log( $urls, $results, $context );
		return $results;
	}

	/**
	 * Submit to IndexNow.
	 *
	 * @param array $urls URLs.
	 * @return array
	 */
	protected function submit_indexnow( array $urls ) {
		$key  = (string) agentsteamer_ai_get_option( 'indexnow_key' );
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		$body = array(
			'host'        => $host,
			'key'         => $key,
			'keyLocation' => home_url( '/' . $key . '.txt' ),
			'urlList'     => array_slice( $urls, 0, 10000 ),
		);

		$response = wp_remote_post(
			'https://api.indexnow.org/indexnow',
			array(
				'timeout' => 8,
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => wp_json_encode( $body ),
			)
		);

		return $this->describe_response( $response );
	}

	/**
	 * Submit to the Baidu rapid-indexing push API.
	 *
	 * @param array $urls URLs.
	 * @return array
	 */
	protected function submit_baidu( array $urls ) {
		$endpoint = trim( (string) agentsteamer_ai_get_option( 'baidu_endpoint' ) );
		if ( '' === $endpoint ) {
			// Legacy: build from site + token.
			$token = (string) agentsteamer_ai_get_option( 'baidu_token' );
			if ( '' === $token ) {
				return array(
					'ok'      => false,
					'code'    => 0,
					'message' => __( '未配置百度推送地址。', 'agentsteamer-ai' ),
				);
			}
			$endpoint = 'http://data.zz.baidu.com/urls?site=' . rawurlencode( $this->effective_baidu_site() ) . '&token=' . rawurlencode( $token );
		} else {
			$endpoint = str_replace( '&amp;', '&', $endpoint );
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 8,
				'headers' => array( 'Content-Type' => 'text/plain' ),
				'body'    => implode( "\n", array_slice( $urls, 0, 2000 ) ),
			)
		);

		return $this->describe_baidu_response( $response );
	}

	/**
	 * The site string sent to Baidu (as configured, else the home URL).
	 *
	 * @return string
	 */
	public function effective_baidu_site() {
		$site = trim( (string) agentsteamer_ai_get_option( 'baidu_site' ) );
		if ( '' === $site ) {
			$site = home_url( '/' );
		}
		return untrailingslashit( $site );
	}

	/**
	 * Display label for the Baidu target, with the token masked.
	 *
	 * @return string
	 */
	public function baidu_target_label() {
		$endpoint = trim( (string) agentsteamer_ai_get_option( 'baidu_endpoint' ) );
		if ( '' !== $endpoint ) {
			$masked = preg_replace( '/(token=)[^&]+/i', '$1****', str_replace( '&amp;', '&', $endpoint ) );
			/* translators: %s: push URL */
			return sprintf( __( '推送地址：%s', 'agentsteamer-ai' ), $masked );
		}
		/* translators: 1: site, 2: token */
		return sprintf( __( '发送 site=%1$s · token=%2$s', 'agentsteamer-ai' ), $this->effective_baidu_site(), AgentSteamer_AI_Settings::mask( (string) agentsteamer_ai_get_option( 'baidu_token' ) ) );
	}

	/**
	 * Human-readable hint for common Baidu push errors.
	 *
	 * @param int    $code Baidu error code.
	 * @param string $msg  Baidu message.
	 * @return string
	 */
	protected function baidu_hint( $code, $msg ) {
		$hints = array(
			400 => __( '：百度未识别该站点 —— 该站点未在百度搜索资源平台验证，或推送地址里的 site= 与验证域名不一致（注意 www、http/https）。建议直接粘贴「百度搜索资源平台 → 该站点 → 普通收录 / 快速收录 → API 推送」给出的完整地址。', 'agentsteamer-ai' ),
			401 => __( '：Token 无效或与该站点不匹配，请重新复制该站点的 API 推送 Token。', 'agentsteamer-ai' ),
			403 => __( '：配额已用尽，或该站点未开通此推送通道。', 'agentsteamer-ai' ),
			429 => __( '：请求过于频繁，请稍后再试。', 'agentsteamer-ai' ),
		);
		if ( isset( $hints[ $code ] ) ) {
			return $hints[ $code ];
		}
		if ( false !== stripos( (string) $msg, 'site init fail' ) ) {
			return $hints[400];
		}
		return '';
	}

	/**
	 * Normalise a Baidu push response, surfacing success count / remaining quota.
	 *
	 * @param array|WP_Error $response Response.
	 * @return array
	 */
	protected function describe_baidu_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'code'    => 0,
				'message' => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		$out = array(
			'ok'      => ( $code >= 200 && $code < 300 ),
			'code'    => $code,
			'message' => substr( wp_strip_all_tags( $raw ), 0, 300 ),
		);

		if ( is_array( $data ) ) {
			if ( isset( $data['error'] ) ) {
				$out['ok']      = false;
				$base           = trim( (string) ( isset( $data['message'] ) ? $data['message'] : '' ) );
				$out['message'] = $base . ' (error ' . (int) $data['error'] . ')' . $this->baidu_hint( (int) $data['error'], $base );
			} elseif ( isset( $data['success'] ) ) {
				$out['ok'] = true;
				$parts     = array( 'success ' . (int) $data['success'] );
				if ( isset( $data['remain'] ) ) {
					$parts[] = 'remain ' . (int) $data['remain'];
				}
				if ( ! empty( $data['not_same_site'] ) ) {
					$parts[] = 'not_same_site ' . count( (array) $data['not_same_site'] );
				}
				if ( ! empty( $data['not_valid'] ) ) {
					$parts[] = 'not_valid ' . count( (array) $data['not_valid'] );
				}
				$out['message'] = implode( ' · ', $parts );
			}
		}

		return $out;
	}

	/**
	 * Normalise an HTTP response into a log-friendly array.
	 *
	 * @param array|WP_Error $response Response.
	 * @return array
	 */
	protected function describe_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'code'    => 0,
				'message' => $response->get_error_message(),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return array(
			'ok'      => ( $code >= 200 && $code < 300 ),
			'code'    => $code,
			'message' => substr( wp_strip_all_tags( (string) wp_remote_retrieve_body( $response ) ), 0, 300 ),
		);
	}

	/**
	 * Append to the submission log.
	 *
	 * @param array  $urls    URLs.
	 * @param array  $results Engine results.
	 * @param string $context Context.
	 */
	protected function add_log( array $urls, array $results, $context ) {
		$log = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift(
			$log,
			array(
				'time'    => current_time( 'mysql' ),
				'context' => $context,
				'count'   => count( $urls ),
				'sample'  => array_slice( $urls, 0, 3 ),
				'results' => $results,
			)
		);
		update_option( self::LOG_OPTION, array_slice( $log, 0, 50 ), false );
	}

	/**
	 * Get the submission log.
	 *
	 * @return array
	 */
	public function get_log() {
		$log = get_option( self::LOG_OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Register the admin submenu.
	 */
	public function menu() {
		add_submenu_page(
			'agentsteamer-ai',
			__( '收录提交', 'agentsteamer-ai' ),
			__( '收录提交', 'agentsteamer-ai' ),
			'manage_options',
			'agentsteamer-ai-indexing',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Handle the "submit all" action.
	 */
	public function handle_submit_all() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-ai' ) );
		}
		check_admin_referer( 'agentsteamer_ai_submit_all' );

		$urls = array( home_url( '/' ) );
		foreach ( agentsteamer_ai_supported_post_types() as $type ) {
			$offset = 0;
			$per    = 500;
			do {
				$posts = get_posts(
					array(
						'post_type'     => $type,
						'post_status'   => 'publish',
						'numberposts'   => $per,
						'offset'        => $offset,
						'orderby'       => 'ID',
						'order'         => 'ASC',
						'no_found_rows' => true,
					)
				);
				foreach ( $posts as $post ) {
					$urls[] = get_permalink( $post );
				}
				$offset += $per;
			} while ( count( $posts ) === $per );
		}

		$this->submit_urls( $urls, 'manual:all' );

		wp_safe_redirect( add_query_arg( array( 'page' => 'agentsteamer-ai-indexing', 'submitted' => count( $urls ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle the "clear log" action.
	 */
	public function handle_clear_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-ai' ) );
		}
		check_admin_referer( 'agentsteamer_ai_clear_index_log' );
		update_option( self::LOG_OPTION, array(), false );
		wp_safe_redirect( add_query_arg( array( 'page' => 'agentsteamer-ai-indexing', 'cleared' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render the indexing admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-ai' ) );
		}

		$indexnow = agentsteamer_ai_get_option( 'indexnow_enabled', 0 ) && agentsteamer_ai_get_option( 'indexnow_key' );
		$baidu    = agentsteamer_ai_get_option( 'baidu_enabled', 0 ) && ( agentsteamer_ai_get_option( 'baidu_endpoint' ) || agentsteamer_ai_get_option( 'baidu_token' ) );
		$key      = (string) agentsteamer_ai_get_option( 'indexnow_key' );
		?>
		<div class="wrap asi-wrap">
			<h1><?php esc_html_e( '收录提交', 'agentsteamer-ai' ); ?></h1>
			<p class="asi-sub"><?php esc_html_e( '把新内容提交给搜索引擎，加快收录。', 'agentsteamer-ai' ); ?></p>

			<?php if ( isset( $_GET['submitted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( __( '已提交 %d 个 URL。', 'agentsteamer-ai' ), (int) $_GET['submitted'] ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '日志已清空。', 'agentsteamer-ai' ); ?></p></div>
			<?php endif; ?>

			<div class="asi-card">
				<h2><?php esc_html_e( '接口状态', 'agentsteamer-ai' ); ?></h2>
				<p>
					<strong>IndexNow：</strong>
					<?php if ( $indexnow ) : ?>
						<span class="asi-badge asi-badge-ok"><?php esc_html_e( '已启用', 'agentsteamer-ai' ); ?></span>
						<code><?php echo esc_html( home_url( '/' . $key . '.txt' ) ); ?></code>
					<?php else : ?>
						<span class="asi-badge"><?php esc_html_e( '未启用', 'agentsteamer-ai' ); ?></span>
					<?php endif; ?>
				</p>
				<p>
					<strong><?php esc_html_e( '百度推送：', 'agentsteamer-ai' ); ?></strong>
					<?php if ( $baidu ) : ?>
						<span class="asi-badge asi-badge-ok"><?php esc_html_e( '已启用', 'agentsteamer-ai' ); ?></span>
						<span class="description"><?php echo esc_html( $this->baidu_target_label() ); ?></span>
					<?php else : ?>
						<span class="asi-badge"><?php esc_html_e( '未启用', 'agentsteamer-ai' ); ?></span>
					<?php endif; ?>
				</p>
				<p class="description">
					<?php esc_html_e( 'IndexNow 支持 Bing、Yandex、Naver、Seznam 等；Google 普通收录不提供此类实时推送接口（其 Indexing API 仅限招聘/直播结构化数据），Google 通过 Sitemap 与正常抓取收录。', 'agentsteamer-ai' ); ?>
				</p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( home_url( '/asi-sitemap.xml' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( '查看 Sitemap', 'agentsteamer-ai' ); ?></a>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=agentsteamer-ai-settings#indexing' ) ); ?>"><?php esc_html_e( '前往设置', 'agentsteamer-ai' ); ?></a>
				</p>
			</div>

			<div class="asi-card">
				<h2><?php esc_html_e( '手动提交', 'agentsteamer-ai' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="agentsteamer_ai_submit_all" />
					<?php wp_nonce_field( 'agentsteamer_ai_submit_all' ); ?>
					<p><?php esc_html_e( '向已配置的引擎提交站点首页及全部已发布内容。', 'agentsteamer-ai' ); ?></p>
					<button type="submit" class="button button-primary" <?php disabled( ! $indexnow && ! $baidu ); ?>><?php esc_html_e( '立即提交全部 URL', 'agentsteamer-ai' ); ?></button>
				</form>
			</div>

			<div class="asi-card">
				<h2><?php esc_html_e( '最近提交记录', 'agentsteamer-ai' ); ?></h2>
				<?php $log = $this->get_log(); ?>
				<?php if ( empty( $log ) ) : ?>
					<p><?php esc_html_e( '暂无记录。', 'agentsteamer-ai' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( '时间', 'agentsteamer-ai' ); ?></th>
								<th><?php esc_html_e( '来源', 'agentsteamer-ai' ); ?></th>
								<th><?php esc_html_e( 'URL 数', 'agentsteamer-ai' ); ?></th>
								<th><?php esc_html_e( '结果', 'agentsteamer-ai' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $log as $entry ) : ?>
								<tr>
									<td><?php echo esc_html( $entry['time'] ); ?></td>
									<td><?php echo esc_html( $entry['context'] ); ?></td>
									<td><?php echo esc_html( $entry['count'] ); ?></td>
									<td>
										<?php
										if ( ! empty( $entry['results'] ) ) {
											foreach ( $entry['results'] as $engine => $r ) {
												$ok = ! empty( $r['ok'] );
												$msg = isset( $r['message'] ) ? (string) $r['message'] : '';
												echo '<span class="asi-badge ' . ( $ok ? 'asi-badge-ok' : 'asi-badge-warn' ) . '">' . esc_html( $engine . ' ' . ( $ok ? 'OK' : 'FAIL' ) . ( isset( $r['code'] ) && $r['code'] ? ' (' . $r['code'] . ')' : '' ) ) . '</span>';
												if ( '' !== $msg ) {
													echo ' <span class="description">' . esc_html( mb_substr( $msg, 0, 70 ) ) . ( mb_strlen( $msg ) > 70 ? '…' : '' ) . '</span>';
												}
												echo ' ';
											}
										} else {
											echo '<span class="asi-badge">' . esc_html__( '未配置引擎', 'agentsteamer-ai' ) . '</span>';
										}
										?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px;">
						<input type="hidden" name="action" value="agentsteamer_ai_clear_index_log" />
						<?php wp_nonce_field( 'agentsteamer_ai_clear_index_log' ); ?>
						<button type="submit" class="button"><?php esc_html_e( '清空日志', 'agentsteamer-ai' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
