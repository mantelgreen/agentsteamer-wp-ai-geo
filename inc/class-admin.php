<?php
/**
 * Admin menus, pages and assets.
 *
 * @package AgentSteamer_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the admin experience.
 */
class AgentSteamer_AI_Admin {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_menu', array( $this, 'reorder_menu' ), 999 );
		add_action( 'admin_enqueue_scripts', array( $this, 'menu_icon_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'plugin_action_links_' . AGENTSTEAMER_AI_BASENAME, array( $this, 'action_links' ) );
		add_action( 'admin_post_agentsteamer_ai_save_settings', array( $this, 'handle_settings' ) );
		add_action( 'admin_post_agentsteamer_ai_generate_stream', array( $this, 'handle_generate_stream' ) );

		foreach ( agentsteamer_ai_supported_post_types() as $type ) {
			add_filter( 'manage_' . $type . '_posts_columns', array( $this, 'columns' ) );
			add_action( 'manage_' . $type . '_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
		}
	}

	/**
	 * Register admin menus.
	 */
	public function menu() {
		add_menu_page(
			__( 'AgentSteamer AI', 'agentsteamer-ai' ),
			__( 'AgentSteamer AI', 'agentsteamer-ai' ),
			'edit_posts',
			'agentsteamer-ai',
			array( $this, 'render_generator' ),
			AGENTSTEAMER_AI_URL . 'assets/images/menu-icon.png',
			58
		);

		add_submenu_page(
			'agentsteamer-ai',
			__( '一键生成文章', 'agentsteamer-ai' ),
			__( '一键生成文章', 'agentsteamer-ai' ),
			'edit_posts',
			'agentsteamer-ai',
			array( $this, 'render_generator' )
		);

		add_submenu_page(
			'agentsteamer-ai',
			__( 'SEO / GEO 设置', 'agentsteamer-ai' ),
			__( '设置', 'agentsteamer-ai' ),
			'manage_options',
			'agentsteamer-ai-settings',
			array( $this, 'render_settings' )
		);
	}

	/**
	 * Reorder the plugin submenus into a task-oriented sequence and make the
	 * overview the landing page.
	 */
	public function reorder_menu() {
		global $submenu;
		$parent = 'agentsteamer-ai';
		if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
			return;
		}

		$order = array(
			'agentsteamer-ai-dashboard',
			'agentsteamer-ai',
			'agentsteamer-ai-reviews',
			'agentsteamer-ai-audit',
			'agentsteamer-ai-topics',
			'agentsteamer-ai-images',
			'agentsteamer-ai-redirects',
			'agentsteamer-ai-404',
			'agentsteamer-ai-indexing',
			'agentsteamer-ai-settings',
		);

		$by_slug = array();
		foreach ( $submenu[ $parent ] as $item ) {
			if ( ! isset( $item[2] ) || isset( $by_slug[ $item[2] ] ) ) {
				continue;
			}
			$by_slug[ $item[2] ] = $item;
		}

		$sorted = array();
		foreach ( $order as $slug ) {
			if ( isset( $by_slug[ $slug ] ) ) {
				$sorted[] = $by_slug[ $slug ];
				unset( $by_slug[ $slug ] );
			}
		}
		foreach ( $by_slug as $item ) {
			$sorted[] = $item;
		}

		$submenu[ $parent ] = $sorted;
	}

	/**
	 * Enqueue the menu-icon stylesheet on every admin screen.
	 */
	public function menu_icon_assets() {
		wp_enqueue_style( 'agentsteamer-ai-menu', AGENTSTEAMER_AI_URL . 'assets/css/menu.css', array(), AGENTSTEAMER_AI_VERSION );
	}

	/**
	 * Enqueue admin assets where needed.
	 *
	 * @param string $hook Current admin page.
	 */
	public function assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_editor = $screen && 'post' === $screen->base;

		if ( false === strpos( $hook, 'agentsteamer-ai' ) && ! $is_editor ) {
			return;
		}

		wp_enqueue_style( 'agentsteamer-ai-fonts', 'https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&display=swap', array(), null );
		wp_enqueue_style( 'agentsteamer-ai-admin', AGENTSTEAMER_AI_URL . 'assets/css/admin.css', array(), AGENTSTEAMER_AI_VERSION );
		wp_enqueue_script( 'agentsteamer-ai-admin', AGENTSTEAMER_AI_URL . 'assets/js/admin.js', array( 'wp-api-fetch' ), AGENTSTEAMER_AI_VERSION, true );

		wp_localize_script(
			'agentsteamer-ai-admin',
			'AgentSteamerAI',
			array(
				'restUrl'   => esc_url_raw( rest_url( AgentSteamer_AI_Rest::NS ) ),
				'adminPost' => esc_url_raw( admin_url( 'admin-post.php' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'presets'   => AgentSteamer_AI_Provider_Manager::presets(),
				'i18n'      => array(
					'generating' => __( '正在生成正文…', 'agentsteamer-ai' ),
					'saving'     => __( '保存中…', 'agentsteamer-ai' ),
					'saved'      => __( '已创建草稿。', 'agentsteamer-ai' ),
					'error'      => __( '出错了：', 'agentsteamer-ai' ),
					'testing'    => __( '测试中…', 'agentsteamer-ai' ),
					'preview'    => __( '生成结果预览', 'agentsteamer-ai' ),
					'saveDraft'  => __( '保存为草稿', 'agentsteamer-ai' ),
					'regenerate' => __( '重新生成', 'agentsteamer-ai' ),
					'streamEmpty' => __( '填写左侧配置并点击「开始生成」，这里会实时显示大模型的输出。', 'agentsteamer-ai' ),
					'streamDone' => __( '生成完成，可编辑后保存为草稿。', 'agentsteamer-ai' ),
					'schemaAdded' => __( '已自动附带 FAQ / HowTo 结构化数据。', 'agentsteamer-ai' ),
					'stopped'    => __( '已停止。', 'agentsteamer-ai' ),
					'afRunning'  => __( '正在补全…', 'agentsteamer-ai' ),
					'afDone'     => __( '补全完成', 'agentsteamer-ai' ),
					'afFilled'   => __( '已补全', 'agentsteamer-ai' ),
					'afSkipped'  => __( '已存在', 'agentsteamer-ai' ),
					'afFailed'   => __( '失败', 'agentsteamer-ai' ),
				),
			)
		);
	}

	/**
	 * Add a settings link on the plugins list.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=agentsteamer-ai-settings' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( '设置', 'agentsteamer-ai' ) . '</a>' );
		return $links;
	}

	/**
	 * Render the one-click article generator page.
	 */
	public function render_generator() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-ai' ) );
		}

		$configured = AgentSteamer_AI_Provider_Manager::is_configured();
		?>
		<div class="wrap asi-wrap">
			<h1><?php esc_html_e( '一键生成文章', 'agentsteamer-ai' ); ?></h1>
			<p class="asi-sub"><?php esc_html_e( '左侧配置，右侧实时显示大模型的输出，完成后保存为草稿。', 'agentsteamer-ai' ); ?></p>

			<?php if ( ! $configured ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php esc_html_e( '尚未配置大模型接口。', 'agentsteamer-ai' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentsteamer-ai-settings' ) ); ?>"><?php esc_html_e( '前往设置', 'agentsteamer-ai' ); ?></a>
					</p>
				</div>
			<?php endif; ?>

			<div class="asi-gen">
				<form id="asi-generator-form" class="asi-card asi-gen-config">
					<h2><?php esc_html_e( '生成配置', 'agentsteamer-ai' ); ?></h2>
					<input type="hidden" name="action" value="agentsteamer_ai_generate_stream" />
					<input type="hidden" name="_asi_nonce" value="<?php echo esc_attr( wp_create_nonce( 'agentsteamer_ai_generate_stream' ) ); ?>" />

					<p class="asi-field">
						<label for="asi-topic"><?php esc_html_e( '文章主题', 'agentsteamer-ai' ); ?></label>
						<input type="text" id="asi-topic" name="topic" class="widefat" placeholder="<?php esc_attr_e( '例如：企业如何选择大模型私有化部署方案', 'agentsteamer-ai' ); ?>" required />
					</p>

					<p class="asi-field">
						<label for="asi-prompt"><?php esc_html_e( '补充要求（可选）', 'agentsteamer-ai' ); ?></label>
						<textarea id="asi-prompt" name="prompt" class="widefat" rows="3" placeholder="<?php esc_attr_e( '写作角度、必须包含的要点、语气等', 'agentsteamer-ai' ); ?>"></textarea>
					</p>

					<p class="asi-field">
						<label for="asi-keywords"><?php esc_html_e( '目标关键词', 'agentsteamer-ai' ); ?></label>
						<input type="text" id="asi-keywords" name="keywords" class="widefat" />
					</p>

					<div class="asi-grid">
						<p class="asi-field">
							<label for="asi-audience"><?php esc_html_e( '目标读者', 'agentsteamer-ai' ); ?></label>
							<input type="text" id="asi-audience" name="audience" class="widefat" />
						</p>
						<p class="asi-field">
							<label for="asi-tone"><?php esc_html_e( '语气风格', 'agentsteamer-ai' ); ?></label>
							<input type="text" id="asi-tone" name="tone" class="widefat" value="<?php esc_attr_e( '专业、可信', 'agentsteamer-ai' ); ?>" />
						</p>
						<p class="asi-field">
							<label for="asi-length"><?php esc_html_e( '目标字数', 'agentsteamer-ai' ); ?></label>
							<input type="number" id="asi-length" name="length" class="widefat" value="1200" min="300" max="6000" step="100" />
						</p>
						<p class="asi-field">
							<label for="asi-post-type"><?php esc_html_e( '内容类型', 'agentsteamer-ai' ); ?></label>
							<select id="asi-post-type" name="post_type" class="widefat">
								<?php foreach ( agentsteamer_ai_supported_post_types() as $type ) : $obj = get_post_type_object( $type ); ?>
									<option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $obj ? $obj->labels->singular_name : $type ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<p class="asi-field">
							<label for="asi-language"><?php esc_html_e( '语言', 'agentsteamer-ai' ); ?></label>
							<input type="text" id="asi-language" name="language" class="widefat" value="zh-CN" />
						</p>
					</div>

					<p class="asi-gen-actions">
						<button type="submit" class="button button-primary" id="asi-generate"><?php esc_html_e( '开始生成', 'agentsteamer-ai' ); ?></button>
						<button type="button" class="button" id="asi-stop" disabled><?php esc_html_e( '停止', 'agentsteamer-ai' ); ?></button>
						<span class="asi-inline-status" id="asi-generate-status" aria-live="polite"></span>
					</p>
				</form>

				<section class="asi-card asi-gen-output">
					<div class="asi-gen-output-head">
						<h2><?php esc_html_e( '生成结果', 'agentsteamer-ai' ); ?></h2>
						<span class="asi-hint" id="asi-stream-status" aria-live="polite"></span>
					</div>
					<div class="asi-stream" id="asi-stream">
						<p class="asi-stream-empty" id="asi-stream-empty"><?php esc_html_e( '填写左侧配置并点击「开始生成」，这里会实时显示大模型的输出。', 'agentsteamer-ai' ); ?></p>
					</div>

					<div id="asi-result" class="asi-hidden">
						<p class="asi-field">
							<label for="asi-result-title"><?php esc_html_e( '标题', 'agentsteamer-ai' ); ?></label>
							<input type="text" id="asi-result-title" class="widefat" />
						</p>
						<p class="asi-field">
							<label for="asi-result-excerpt"><?php esc_html_e( '摘要', 'agentsteamer-ai' ); ?></label>
							<textarea id="asi-result-excerpt" class="widefat" rows="2"></textarea>
						</p>
						<details class="asi-result-more">
							<summary><?php esc_html_e( 'SEO / GEO 元数据', 'agentsteamer-ai' ); ?></summary>
							<p class="asi-field">
								<label for="asi-result-metatitle"><?php esc_html_e( 'SEO 标题', 'agentsteamer-ai' ); ?></label>
								<input type="text" id="asi-result-metatitle" class="widefat" />
							</p>
							<p class="asi-field">
								<label for="asi-result-metadesc"><?php esc_html_e( 'Meta 描述', 'agentsteamer-ai' ); ?></label>
								<textarea id="asi-result-metadesc" class="widefat" rows="2"></textarea>
							</p>
							<p class="asi-field">
								<label for="asi-result-keyword"><?php esc_html_e( '焦点关键词', 'agentsteamer-ai' ); ?></label>
								<input type="text" id="asi-result-keyword" class="widefat" />
							</p>
							<p class="asi-field">
								<label for="asi-result-tags"><?php esc_html_e( '标签（逗号分隔）', 'agentsteamer-ai' ); ?></label>
								<input type="text" id="asi-result-tags" class="widefat" />
							</p>
						</details>
						<p class="asi-gen-actions">
							<button type="button" class="button button-primary" id="asi-save-draft"><?php esc_html_e( '保存为草稿', 'agentsteamer-ai' ); ?></button>
							<button type="button" class="button" id="asi-regenerate"><?php esc_html_e( '重新生成', 'agentsteamer-ai' ); ?></button>
							<span class="asi-inline-status" id="asi-save-status" aria-live="polite"></span>
						</p>
					</div>
				</section>
			</div>
		</div>
		<?php
	}

	/**
	 * Stream an article to the browser as Server-Sent Events.
	 */
	public function handle_generate_stream() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			status_header( 403 );
			exit( 'forbidden' );
		}

		$nonce = isset( $_POST['_asi_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_asi_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'agentsteamer_ai_generate_stream' ) ) {
			status_header( 403 );
			exit( 'forbidden' );
		}

		@ini_set( 'zlib.output_compression', '0' );
		@ini_set( 'output_buffering', '0' );
		@ini_set( 'implicit_flush', '1' );
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
		if ( function_exists( 'ob_implicit_flush' ) ) {
			ob_implicit_flush( true );
		}

		nocache_headers();
		header( 'Content-Type: text/event-stream; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-transform' );
		header( 'X-Accel-Buffering: no' );

		$emit = static function ( $event, $data ) {
			echo 'event: ' . $event . "\n";
			echo 'data: ' . wp_json_encode( $data ) . "\n\n";
			flush();
		};

		$params = array(
			'prompt'   => isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '',
			'topic'    => isset( $_POST['topic'] ) ? sanitize_text_field( wp_unslash( $_POST['topic'] ) ) : '',
			'keywords' => isset( $_POST['keywords'] ) ? sanitize_text_field( wp_unslash( $_POST['keywords'] ) ) : '',
			'audience' => isset( $_POST['audience'] ) ? sanitize_text_field( wp_unslash( $_POST['audience'] ) ) : '',
			'tone'     => isset( $_POST['tone'] ) ? sanitize_text_field( wp_unslash( $_POST['tone'] ) ) : '专业、可信',
			'length'   => isset( $_POST['length'] ) ? (int) $_POST['length'] : 1200,
			'language' => isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : 'zh-CN',
		);

		if ( '' === $params['topic'] && '' === $params['prompt'] ) {
			$emit( 'error', array( 'message' => __( '请填写文章主题。', 'agentsteamer-ai' ) ) );
			exit;
		}

		$emit( 'status', array( 'text' => __( '正在生成正文…', 'agentsteamer-ai' ) ) );

		$ai     = new AgentSteamer_AI();
		$result = $ai->stream_article(
			$params,
			static function ( $delta ) use ( $emit ) {
				$emit( 'delta', array( 't' => $delta ) );
			}
		);

		if ( is_wp_error( $result ) ) {
			$emit( 'error', array( 'message' => $result->get_error_message() ) );
			exit;
		}

		$emit(
			'meta',
			array(
				'title'            => $result['title'],
				'excerpt'          => $result['excerpt'],
				'meta_title'       => $result['meta_title'],
				'meta_description' => $result['meta_description'],
				'focus_keyword'    => $result['focus_keyword'],
				'tags'             => $result['tags'],
				'faq'              => isset( $result['faq'] ) ? $result['faq'] : array(),
				'howto'            => isset( $result['howto'] ) ? $result['howto'] : array( 'name' => '', 'steps' => array() ),
			)
		);
		$emit( 'done', array( 'ok' => true ) );
		exit;
	}

	/**
	 * Handle the settings form (non-Settings-API fallback for custom tabs).
	 */
	public function handle_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-ai' ) );
		}
		check_admin_referer( 'agentsteamer_ai_save_settings' );

		$input = isset( $_POST['agentsteamer_ai_settings'] ) ? wp_unslash( $_POST['agentsteamer_ai_settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$settings = new AgentSteamer_AI_Settings();
		$clean    = $settings->sanitize( $input );
		update_option( 'agentsteamer_ai_settings', $clean );

		wp_safe_redirect( add_query_arg( array( 'page' => 'agentsteamer-ai-settings', 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-ai' ) );
		}
		$s = agentsteamer_ai_get_settings();
		?>
		<div class="wrap asi-wrap">
			<h1><?php esc_html_e( 'AgentSteamer AI · SEO / GEO 设置', 'agentsteamer-ai' ); ?></h1>
			<p class="asi-sub"><?php esc_html_e( '站点信息、大模型接口、GEO 与收录、结构化数据的配置。', 'agentsteamer-ai' ); ?></p>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '设置已保存。', 'agentsteamer-ai' ); ?></p></div>
			<?php endif; ?>

			<h2 class="nav-tab-wrapper asi-tabs">
				<a href="#basic" class="nav-tab nav-tab-active" data-tab="basic"><?php esc_html_e( '基础 SEO', 'agentsteamer-ai' ); ?></a>
				<a href="#geo" class="nav-tab" data-tab="geo"><?php esc_html_e( 'GEO', 'agentsteamer-ai' ); ?></a>
				<a href="#ai" class="nav-tab" data-tab="ai"><?php esc_html_e( '大模型接口', 'agentsteamer-ai' ); ?></a>
				<a href="#crawlers" class="nav-tab" data-tab="crawlers"><?php esc_html_e( 'AI 爬虫', 'agentsteamer-ai' ); ?></a>
				<a href="#indexing" class="nav-tab" data-tab="indexing"><?php esc_html_e( '收录提交', 'agentsteamer-ai' ); ?></a>
				<a href="#schema" class="nav-tab" data-tab="schema"><?php esc_html_e( '结构化数据', 'agentsteamer-ai' ); ?></a>
				<a href="#prompts" class="nav-tab" data-tab="prompts"><?php esc_html_e( '提示词', 'agentsteamer-ai' ); ?></a>
			</h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="agentsteamer_ai_save_settings" />
				<?php wp_nonce_field( 'agentsteamer_ai_save_settings' ); ?>

				<div class="asi-panel" data-panel="basic">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( '启用插件', 'agentsteamer-ai' ); ?></th>
							<td><label><input type="checkbox" name="agentsteamer_ai_settings[enabled]" value="1" <?php checked( $s['enabled'], 1 ); ?> /> <?php esc_html_e( '启用前台 SEO / GEO 输出', 'agentsteamer-ai' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><label for="asi-title-sep"><?php esc_html_e( '标题分隔符', 'agentsteamer-ai' ); ?></label></th>
							<td><input type="text" id="asi-title-sep" name="agentsteamer_ai_settings[title_separator]" value="<?php echo esc_attr( $s['title_separator'] ); ?>" class="small-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="asi-home-title"><?php esc_html_e( '首页 SEO 标题', 'agentsteamer-ai' ); ?></label></th>
							<td><input type="text" id="asi-home-title" name="agentsteamer_ai_settings[home_title]" value="<?php echo esc_attr( $s['home_title'] ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="asi-home-desc"><?php esc_html_e( '首页 Meta 描述', 'agentsteamer-ai' ); ?></label></th>
							<td><textarea id="asi-home-desc" name="agentsteamer_ai_settings[home_desc]" rows="2" class="large-text"><?php echo esc_textarea( $s['home_desc'] ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><label for="asi-default-desc"><?php esc_html_e( '默认 Meta 描述', 'agentsteamer-ai' ); ?></label></th>
							<td><textarea id="asi-default-desc" name="agentsteamer_ai_settings[default_desc]" rows="2" class="large-text"><?php echo esc_textarea( $s['default_desc'] ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><label for="asi-org-name"><?php esc_html_e( '组织 / 品牌名称', 'agentsteamer-ai' ); ?></label></th>
							<td><input type="text" id="asi-org-name" name="agentsteamer_ai_settings[org_name]" value="<?php echo esc_attr( $s['org_name'] ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="asi-org-logo"><?php esc_html_e( '组织 Logo URL', 'agentsteamer-ai' ); ?></label></th>
							<td><input type="url" id="asi-org-logo" name="agentsteamer_ai_settings[org_logo]" value="<?php echo esc_attr( $s['org_logo'] ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="asi-social"><?php esc_html_e( '社交 / 权威主页（每行一个）', 'agentsteamer-ai' ); ?></label></th>
							<td><textarea id="asi-social" name="agentsteamer_ai_settings[social_profiles]" rows="3" class="large-text" placeholder="https://github.com/..."><?php echo esc_textarea( $s['social_profiles'] ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><label for="asi-knows"><?php esc_html_e( '作者专长 (knowsAbout，每行一个)', 'agentsteamer-ai' ); ?></label></th>
							<td><textarea id="asi-knows" name="agentsteamer_ai_settings[author_knows_about]" rows="2" class="large-text"><?php echo esc_textarea( $s['author_knows_about'] ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( '自动生成描述', 'agentsteamer-ai' ); ?></th>
							<td><label><input type="checkbox" name="agentsteamer_ai_settings[auto_meta]" value="1" <?php checked( $s['auto_meta'], 1 ); ?> /> <?php esc_html_e( '未填写描述时，自动截取正文生成', 'agentsteamer-ai' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'noindex 范围', 'agentsteamer-ai' ); ?></th>
							<td>
								<label><input type="checkbox" name="agentsteamer_ai_settings[noindex_search]" value="1" <?php checked( $s['noindex_search'], 1 ); ?> /> <?php esc_html_e( '搜索结果页', 'agentsteamer-ai' ); ?></label><br />
								<label><input type="checkbox" name="agentsteamer_ai_settings[noindex_author]" value="1" <?php checked( $s['noindex_author'], 1 ); ?> /> <?php esc_html_e( '作者归档页', 'agentsteamer-ai' ); ?></label><br />
								<label><input type="checkbox" name="agentsteamer_ai_settings[noindex_date]" value="1" <?php checked( $s['noindex_date'], 1 ); ?> /> <?php esc_html_e( '日期归档页', 'agentsteamer-ai' ); ?></label>
							</td>
						</tr>
					</table>
				</div>

				<div class="asi-panel asi-hidden" data-panel="geo">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'XML Sitemap', 'agentsteamer-ai' ); ?></th>
							<td>
								<label><input type="checkbox" name="agentsteamer_ai_settings[sitemap_enabled]" value="1" <?php checked( $s['sitemap_enabled'], 1 ); ?> /> <?php esc_html_e( '启用 /asi-sitemap.xml（并关闭核心 Sitemap）', 'agentsteamer-ai' ); ?></label>
								<p class="description"><a href="<?php echo esc_url( home_url( '/asi-sitemap.xml' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( home_url( '/asi-sitemap.xml' ) ); ?></a></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="asi-sitemap-extra"><?php esc_html_e( '附加站点 Sitemap', 'agentsteamer-ai' ); ?></label></th>
							<td>
								<textarea id="asi-sitemap-extra" name="agentsteamer_ai_settings[sitemap_extra]" rows="3" class="large-text code" placeholder="https://blog.agentsteamer.com/asi-sitemap.xml"><?php echo esc_textarea( $s['sitemap_extra'] ); ?></textarea>
								<p class="description"><?php esc_html_e( '每行一个其它站点的 sitemap 地址（如子域博客站）。填写后本 sitemap 会输出为 Sitemap Index，引用本站与这些站点。需在 Google Search Console 同时验证这些域名并交叉提交。', 'agentsteamer-ai' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'llms.txt', 'agentsteamer-ai' ); ?></th>
							<td>
								<label><input type="checkbox" name="agentsteamer_ai_settings[llms_txt_enabled]" value="1" <?php checked( $s['llms_txt_enabled'], 1 ); ?> /> <?php esc_html_e( '生成 /llms.txt', 'agentsteamer-ai' ); ?></label><br />
								<label><input type="checkbox" name="agentsteamer_ai_settings[llms_full_enabled]" value="1" <?php checked( $s['llms_full_enabled'], 1 ); ?> /> <?php esc_html_e( '生成 /llms-full.txt（全文，体积较大）', 'agentsteamer-ai' ); ?></label>
								<p class="description"><a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( home_url( '/llms.txt' ) ); ?></a></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="asi-llms-extra"><?php esc_html_e( '其他站点（llms.txt）', 'agentsteamer-ai' ); ?></label></th>
							<td>
								<textarea id="asi-llms-extra" name="agentsteamer_ai_settings[llms_extra]" rows="3" class="large-text code" placeholder="博客 | https://blog.agentsteamer.com/llms.txt"><?php echo esc_textarea( $s['llms_extra'] ); ?></textarea>
								<p class="description"><?php esc_html_e( '每行一个，格式「标题 | URL」或直接填 URL。会作为「其他站点」一节写入 llms.txt，便于 AI 发现姊妹站点。', 'agentsteamer-ai' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="asi-llms-intro"><?php esc_html_e( 'llms.txt 摘要', 'agentsteamer-ai' ); ?></label></th>
							<td><textarea id="asi-llms-intro" name="agentsteamer_ai_settings[llms_txt_intro]" rows="2" class="large-text"><?php echo esc_textarea( $s['llms_txt_intro'] ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Markdown 输出', 'agentsteamer-ai' ); ?></th>
							<td><label><input type="checkbox" name="agentsteamer_ai_settings[markdown_enabled]" value="1" <?php checked( $s['markdown_enabled'], 1 ); ?> /> <?php esc_html_e( '为文章提供 /{slug}.md（供 AI 消费）', 'agentsteamer-ai' ); ?></label></td>
						</tr>
					</table>
				</div>

				<div class="asi-panel asi-hidden" data-panel="ai">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( '启用 AI 功能', 'agentsteamer-ai' ); ?></th>
							<td><label><input type="checkbox" name="agentsteamer_ai_settings[ai_enabled]" value="1" <?php checked( $s['ai_enabled'], 1 ); ?> /> <?php esc_html_e( '启用一键生成文章与 AI 元数据', 'agentsteamer-ai' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( '留空自动生成', 'agentsteamer-ai' ); ?></th>
							<td>
								<label><input type="checkbox" name="agentsteamer_ai_settings[auto_fill_blank]" value="1" <?php checked( $s['auto_fill_blank'], 1 ); ?> /> <?php esc_html_e( '保存文章后，自动用 AI 补全空白的 SEO 标题 / 描述 / 焦点关键词', 'agentsteamer-ai' ); ?></label>
								<p class="description"><?php esc_html_e( '通过后台定时任务异步生成，不阻塞保存；已填写的字段不会被覆盖。', 'agentsteamer-ai' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="asi-provider"><?php esc_html_e( '服务商', 'agentsteamer-ai' ); ?></label></th>
							<td>
								<select id="asi-provider" name="agentsteamer_ai_settings[provider]">
									<?php foreach ( AgentSteamer_AI_Provider_Manager::presets() as $key => $preset ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['provider'], $key ); ?>><?php echo esc_html( $preset['label'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="asi-thinking"><?php esc_html_e( '思考模式', 'agentsteamer-ai' ); ?></label></th>
							<td>
								<select id="asi-thinking" name="agentsteamer_ai_settings[thinking_mode]">
									<option value="auto" <?php selected( $s['thinking_mode'], 'auto' ); ?>><?php esc_html_e( '自动（火山方舟等推理接口自动关闭思考）', 'agentsteamer-ai' ); ?></option>
									<option value="on" <?php selected( $s['thinking_mode'], 'on' ); ?>><?php esc_html_e( '关闭思考（加速）', 'agentsteamer-ai' ); ?></option>
									<option value="off" <?php selected( $s['thinking_mode'], 'off' ); ?>><?php esc_html_e( '保留思考（默认，较慢）', 'agentsteamer-ai' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( '推理模型会消耗大量时间；关闭思考可显著提速（部分模型不支持该参数）。', 'agentsteamer-ai' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="asi-base-url"><?php esc_html_e( '接口地址 Base URL', 'agentsteamer-ai' ); ?></label></th>
							<td><input type="url" id="asi-base-url" name="agentsteamer_ai_settings[base_url]" value="<?php echo esc_attr( $s['base_url'] ); ?>" class="large-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="asi-api-key"><?php esc_html_e( 'API Key', 'agentsteamer-ai' ); ?></label></th>
							<td>
								<input type="password" id="asi-api-key" name="agentsteamer_ai_settings[api_key]" value="" class="large-text" autocomplete="off" placeholder="<?php echo esc_attr( $s['api_key'] ? AgentSteamer_AI_Settings::mask( $s['api_key'] ) : __( '填写你的 API Key', 'agentsteamer-ai' ) ); ?>" />
								<p class="description"><?php esc_html_e( '留空表示保持现有 Key 不变。密钥仅存储于本站数据库。', 'agentsteamer-ai' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="asi-model"><?php esc_html_e( '模型名', 'agentsteamer-ai' ); ?></label></th>
							<td><input type="text" id="asi-model" name="agentsteamer_ai_settings[model]" value="<?php echo esc_attr( $s['model'] ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="asi-temp"><?php esc_html_e( '温度 temperature', 'agentsteamer-ai' ); ?></label></th>
							<td><input type="number" id="asi-temp" name="agentsteamer_ai_settings[temperature]" value="<?php echo esc_attr( $s['temperature'] ); ?>" min="0" max="2" step="0.1" class="small-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="asi-max-tokens"><?php esc_html_e( 'max_tokens', 'agentsteamer-ai' ); ?></label></th>
							<td>
								<input type="number" id="asi-max-tokens" name="agentsteamer_ai_settings[max_tokens]" value="<?php echo esc_attr( $s['max_tokens'] ); ?>" min="256" max="200000" step="1" style="width:180px;" list="asi-max-tokens-presets" />
								<datalist id="asi-max-tokens-presets">
									<option value="2048"></option>
									<option value="4096"></option>
									<option value="8192"></option>
									<option value="16384"></option>
									<option value="32768"></option>
									<option value="65536"></option>
								</datalist>
								<p class="description"><?php esc_html_e( '单次响应最多生成的「输出」token 上限，不是上下文窗口（上下文由所选模型决定，无需在此填写）。它是上限而非预留，按实际输出计费：填大不会多花钱，但超过所选模型的最大输出会被接口拒绝。一般 2k–8k；需要一次生成更长正文可设 8k–16k，并同步调大「生成长度」。', 'agentsteamer-ai' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="asi-timeout"><?php esc_html_e( '超时（秒）', 'agentsteamer-ai' ); ?></label></th>
							<td>
								<input type="number" id="asi-timeout" name="agentsteamer_ai_settings[timeout]" value="<?php echo esc_attr( $s['timeout'] ); ?>" min="10" max="600" step="5" class="small-text" />
								<p class="description"><?php esc_html_e( '建议元数据生成使用快速（非推理）模型；推理模型响应可能较慢。服务器网关超时通常为 60 秒，若超时会提示重试。', 'agentsteamer-ai' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( '连接测试', 'agentsteamer-ai' ); ?></th>
							<td>
								<button type="button" class="button" id="asi-test-provider"><?php esc_html_e( '测试连接', 'agentsteamer-ai' ); ?></button>
								<span class="asi-inline-status" id="asi-test-status" aria-live="polite"></span>
								<p class="description"><?php esc_html_e( '请先保存设置，再测试连接。', 'agentsteamer-ai' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="asi-panel asi-hidden" data-panel="crawlers">
					<p class="description"><?php esc_html_e( '控制各类 AI 爬虫对本站的访问，写入 robots.txt。注意：屏蔽“搜索索引类/用户实时抓取类”会降低被 AI 引用的机会。', 'agentsteamer-ai' ); ?></p>
					<?php
					$policy  = is_array( $s['crawler_policy'] ) ? $s['crawler_policy'] : array();
					$purposes = agentsteamer_ai_crawler_purposes();
					$grouped  = array();
					foreach ( agentsteamer_ai_crawler_catalog() as $bot ) {
						$grouped[ $bot['purpose'] ][] = $bot;
					}
					foreach ( $purposes as $purpose => $label ) :
						if ( empty( $grouped[ $purpose ] ) ) {
							continue;
						}
						?>
						<h3><?php echo esc_html( $label ); ?></h3>
						<table class="widefat striped asi-crawler-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'User-Agent', 'agentsteamer-ai' ); ?></th>
									<th><?php esc_html_e( '所属', 'agentsteamer-ai' ); ?></th>
									<th><?php esc_html_e( '说明', 'agentsteamer-ai' ); ?></th>
									<th><?php esc_html_e( '策略', 'agentsteamer-ai' ); ?></th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ( $grouped[ $purpose ] as $bot ) : $current = isset( $policy[ $bot['ua'] ] ) ? $policy[ $bot['ua'] ] : 'allow'; ?>
								<tr>
									<td><code><?php echo esc_html( $bot['ua'] ); ?></code></td>
									<td><?php echo esc_html( $bot['owner'] ); ?></td>
									<td><?php echo esc_html( $bot['note'] ); ?></td>
									<td>
										<select name="agentsteamer_ai_settings[crawler_policy][<?php echo esc_attr( $bot['ua'] ); ?>]">
											<option value="allow" <?php selected( $current, 'allow' ); ?>><?php esc_html_e( '允许', 'agentsteamer-ai' ); ?></option>
											<option value="disallow" <?php selected( $current, 'disallow' ); ?>><?php esc_html_e( '禁止', 'agentsteamer-ai' ); ?></option>
										</select>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endforeach; ?>
				</div>

				<div class="asi-panel asi-hidden" data-panel="indexing">
					<p class="description"><?php esc_html_e( '内容发布或更新后，自动向搜索引擎提交 URL 以加快收录。IndexNow 覆盖 Bing / Yandex / Naver / Seznam 等；百度支持普通收录 / 快速收录的 API 推送（同一接口，Token 区分通道）。', 'agentsteamer-ai' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">IndexNow</th>
							<td>
								<label><input type="checkbox" name="agentsteamer_ai_settings[indexnow_enabled]" value="1" <?php checked( $s['indexnow_enabled'], 1 ); ?> /> <?php esc_html_e( '启用 IndexNow 提交', 'agentsteamer-ai' ); ?></label>
								<p class="description">
									<?php esc_html_e( '密钥文件（需可公开访问）：', 'agentsteamer-ai' ); ?>
									<?php if ( ! empty( $s['indexnow_key'] ) ) : ?>
										<a href="<?php echo esc_url( home_url( '/' . $s['indexnow_key'] . '.txt' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( home_url( '/' . $s['indexnow_key'] . '.txt' ) ); ?></a>
									<?php endif; ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="asi-indexnow-key"><?php esc_html_e( 'IndexNow 密钥', 'agentsteamer-ai' ); ?></label></th>
							<td>
								<input type="text" id="asi-indexnow-key" name="agentsteamer_ai_settings[indexnow_key]" value="<?php echo esc_attr( $s['indexnow_key'] ); ?>" class="regular-text" />
								<p class="description"><?php esc_html_e( '留空则保持当前密钥；建议使用自动生成的密钥。', 'agentsteamer-ai' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( '自动提交', 'agentsteamer-ai' ); ?></th>
							<td>
								<label><input type="checkbox" name="agentsteamer_ai_settings[indexnow_auto]" value="1" <?php checked( $s['indexnow_auto'], 1 ); ?> /> <?php esc_html_e( '文章发布或更新时自动提交对应 URL', 'agentsteamer-ai' ); ?></label>
								<p class="description"><?php esc_html_e( '后台异步提交（不阻塞发布/保存）；同一文章 60 秒内自动去重。', 'agentsteamer-ai' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( '百度推送', 'agentsteamer-ai' ); ?></th>
							<td>
								<label><input type="checkbox" name="agentsteamer_ai_settings[baidu_enabled]" value="1" <?php checked( $s['baidu_enabled'], 1 ); ?> /> <?php esc_html_e( '启用百度收录 API 推送', 'agentsteamer-ai' ); ?></label>
								<p class="description"><?php esc_html_e( '百度「普通收录」与「快速收录」使用同一个 API 推送地址（data.zz.baidu.com/urls），由 Token 区分通道：填哪个通道的 Token 就提交到哪个通道。', 'agentsteamer-ai' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="asi-baidu-token"><?php esc_html_e( '百度推送 Token', 'agentsteamer-ai' ); ?></label></th>
							<td>
								<input type="text" id="asi-baidu-token" name="agentsteamer_ai_settings[baidu_token]" value="<?php echo esc_attr( $s['baidu_token'] ); ?>" class="large-text" />
								<p class="description"><?php esc_html_e( '粘贴「普通收录」或「快速收录」页面的 API 推送 Token；站点需已在百度搜索资源平台验证。', 'agentsteamer-ai' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="asi-baidu-site"><?php esc_html_e( '百度站点（可选）', 'agentsteamer-ai' ); ?></label></th>
							<td><input type="url" id="asi-baidu-site" name="agentsteamer_ai_settings[baidu_site]" value="<?php echo esc_attr( $s['baidu_site'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>" /></td>
						</tr>
					</table>
					<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=agentsteamer-ai-indexing' ) ); ?>"><?php esc_html_e( '打开收录提交面板', 'agentsteamer-ai' ); ?></a></p>
				</div>

				<div class="asi-panel asi-hidden" data-panel="schema">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="asi-global-jsonld"><?php esc_html_e( '全局 JSON-LD 模板', 'agentsteamer-ai' ); ?></label></th>
							<td>
								<textarea id="asi-global-jsonld" name="agentsteamer_ai_settings[schema_global_jsonld]" rows="6" class="large-text code" placeholder='{"@type":"Organization","sameAs":["..."]}'><?php echo esc_textarea( $s['schema_global_jsonld'] ); ?></textarea>
								<p class="description"><?php esc_html_e( '可填单个节点或 {"@graph":[...]}，会合并进每个页面的 JSON-LD。文章的 FAQ / HowTo / Speakable 在文章编辑器侧边栏设置。', 'agentsteamer-ai' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="asi-panel asi-hidden" data-panel="prompts">
					<p class="description"><?php esc_html_e( '留空则使用内置默认提示词；点击每项下方「查看内置默认」可复制后修改。修改会影响对应的 AI 任务，请谨慎调整。', 'agentsteamer-ai' ); ?></p>
					<table class="form-table" role="presentation">
						<?php
						$prompt_labels = array(
							'article'  => __( '正文生成（一键生成文章）', 'agentsteamer-ai' ),
							'finalize' => __( '元数据 + FAQ/HowTo（生成后处理）', 'agentsteamer-ai' ),
							'optimize' => __( '内容优化（审阅草案）', 'agentsteamer-ai' ),
							'meta'     => __( '编辑器元数据生成', 'agentsteamer-ai' ),
							'alt'      => __( '图片 Alt 生成', 'agentsteamer-ai' ),
							'schema'   => __( 'FAQ/HowTo 提取', 'agentsteamer-ai' ),
							'topics'   => __( '选题建议', 'agentsteamer-ai' ),
						);
						$prompt_defaults = agentsteamer_ai_prompt_defaults();
						foreach ( $prompt_labels as $pkey => $plabel ) :
							$psetting = 'prompt_' . $pkey;
							?>
							<tr>
								<th scope="row"><label for="asi-<?php echo esc_attr( $psetting ); ?>"><?php echo esc_html( $plabel ); ?></label></th>
								<td>
									<textarea id="asi-<?php echo esc_attr( $psetting ); ?>" name="agentsteamer_ai_settings[<?php echo esc_attr( $psetting ); ?>]" rows="4" class="large-text code" placeholder="<?php esc_attr_e( '留空使用内置默认提示词', 'agentsteamer-ai' ); ?>"><?php echo esc_textarea( isset( $s[ $psetting ] ) ? $s[ $psetting ] : '' ); ?></textarea>
									<details>
										<summary class="asi-hint"><?php esc_html_e( '查看内置默认', 'agentsteamer-ai' ); ?></summary>
										<pre class="asi-prompt-default"><?php echo esc_html( isset( $prompt_defaults[ $pkey ] ) ? $prompt_defaults[ $pkey ] : '' ); ?></pre>
									</details>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
				</div>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Add the SEO column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function columns( $columns ) {
		$columns['agentsteamer_ai'] = __( 'SEO/GEO', 'agentsteamer-ai' );
		return $columns;
	}

	/**
	 * Render the SEO column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function column_content( $column, $post_id ) {
		if ( 'agentsteamer_ai' !== $column ) {
			return;
		}
		$title = agentsteamer_ai_get_post_meta( $post_id, 'title' );
		$desc  = agentsteamer_ai_get_post_meta( $post_id, 'description' );
		$noindex = '1' === agentsteamer_ai_get_post_meta( $post_id, 'noindex', '0' );

		if ( $noindex ) {
			echo '<span class="asi-badge asi-badge-warn">noindex</span> ';
		}
		if ( $title ) {
			echo '<span class="asi-badge asi-badge-ok" title="' . esc_attr( $title ) . '">' . esc_html__( '标题', 'agentsteamer-ai' ) . '</span> ';
		}
		if ( $desc ) {
			echo '<span class="asi-badge asi-badge-ok" title="' . esc_attr( $desc ) . '">' . esc_html__( '描述', 'agentsteamer-ai' ) . '</span> ';
		}
		if ( ! $title && ! $desc && ! $noindex ) {
			echo '<span class="asi-badge">' . esc_html__( '默认', 'agentsteamer-ai' ) . '</span>';
		}
	}
}
