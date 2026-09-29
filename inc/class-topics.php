<?php
/**
 * Topic clustering and content-gap report.
 *
 * @package AgentSteamer_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports topic clusters, gaps and suggests new topics.
 */
class AgentSteamer_AI_Topics {

	/**
	 * Suggestions transient.
	 */
	const TRANSIENT = 'agentsteamer_ai_topic_suggestions';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'agentsteamer_ai_generate_topic', array( $this, 'run_generate_topic' ), 10, 2 );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'menu' ), 20 );
			add_action( 'admin_post_agentsteamer_ai_suggest_topics', array( $this, 'handle_suggest' ) );
			add_action( 'admin_post_agentsteamer_ai_generate_topic', array( $this, 'handle_generate_topic' ) );
			add_action( 'admin_post_agentsteamer_ai_generate_topics_all', array( $this, 'handle_generate_topics_all' ) );
		}
	}

	/**
	 * Queue a single topic for draft generation.
	 */
	public function handle_generate_topic() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-ai' ) );
		}
		check_admin_referer( 'agentsteamer_ai_generate_topic' );

		$topic = isset( $_POST['topic'] ) ? sanitize_text_field( wp_unslash( $_POST['topic'] ) ) : '';
		$count = 0;
		if ( '' !== $topic ) {
			wp_schedule_single_event( time() + 5, 'agentsteamer_ai_generate_topic', array( $topic, get_current_user_id() ) );
			$count = 1;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=agentsteamer-ai-topics&queued=' . $count ) );
		exit;
	}

	/**
	 * Queue all suggestions for draft generation.
	 */
	public function handle_generate_topics_all() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-ai' ) );
		}
		check_admin_referer( 'agentsteamer_ai_generate_topics_all' );

		$raw    = isset( $_POST['topics'] ) ? wp_unslash( $_POST['topics'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$topics = json_decode( (string) $raw, true );
		$count  = 0;
		if ( is_array( $topics ) ) {
			foreach ( $topics as $topic ) {
				$title = isset( $topic['title'] ) ? sanitize_text_field( $topic['title'] ) : '';
				if ( '' === $title ) {
					continue;
				}
				wp_schedule_single_event( time() + ( 5 * $count ) + 5, 'agentsteamer_ai_generate_topic', array( $title, get_current_user_id() ) );
				$count++;
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=agentsteamer-ai-topics&queued=' . $count ) );
		exit;
	}

	/**
	 * Generate a draft for a topic (runs in the background).
	 *
	 * @param string $topic     Topic title.
	 * @param int    $author_id Author.
	 */
	public function run_generate_topic( $topic, $author_id = 0 ) {
		if ( ! agentsteamer_ai_get_option( 'ai_enabled', 1 ) || ! AgentSteamer_AI_Provider_Manager::is_configured() ) {
			return;
		}
		$ai  = new AgentSteamer_AI();
		$res = $ai->stream_article(
			array(
				'topic'    => $topic,
				'length'   => 1200,
				'language' => 'zh-CN',
			),
			null
		);
		if ( is_wp_error( $res ) ) {
			return;
		}
		$ai->create_draft(
			array(
				'title'            => $res['title'],
				'excerpt'          => $res['excerpt'],
				'content_html'     => $res['content_html'],
				'meta_title'       => $res['meta_title'],
				'meta_description' => $res['meta_description'],
				'focus_keyword'    => $res['focus_keyword'],
				'tags'             => $res['tags'],
				'faq'              => isset( $res['faq'] ) ? $res['faq'] : array(),
				'howto'            => isset( $res['howto'] ) ? $res['howto'] : array(),
			),
			array(
				'post_type'   => 'post',
				'post_status' => 'draft',
				'post_author' => $author_id ? (int) $author_id : 1,
			)
		);
	}

	/**
	 * Register menu.
	 */
	public function menu() {
		add_submenu_page(
			'agentsteamer-ai',
			__( '主题聚类', 'agentsteamer-ai' ),
			__( '主题聚类', 'agentsteamer-ai' ),
			'manage_options',
			'agentsteamer-ai-topics',
			array( $this, 'render' )
		);
	}

	/**
	 * Term counts for a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return array
	 */
	protected function term_counts( $taxonomy ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'number'     => 50,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $term ) {
			$out[] = array(
				'name'  => $term->name,
				'count' => (int) $term->count,
				'link'  => get_term_link( $term ),
			);
		}
		return $out;
	}

	/**
	 * Focus keyword frequency.
	 *
	 * @return array
	 */
	protected function keyword_counts() {
		$posts = get_posts(
			array(
				'post_type'     => agentsteamer_ai_supported_post_types(),
				'post_status'   => 'publish',
				'numberposts'   => 2000,
				'no_found_rows' => true,
			)
		);
		$counts = array();
		foreach ( $posts as $post ) {
			$kw = agentsteamer_ai_get_post_meta( $post->ID, 'focus_keyword' );
			if ( '' === $kw ) {
				continue;
			}
			$counts[ $kw ] = isset( $counts[ $kw ] ) ? $counts[ $kw ] + 1 : 1;
		}
		arsort( $counts );
		return $counts;
	}

	/**
	 * Handle AI topic suggestion request.
	 */
	public function handle_suggest() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-ai' ) );
		}
		check_admin_referer( 'agentsteamer_ai_suggest_topics' );

		$cats     = $this->term_counts( 'category' );
		$tags     = $this->term_counts( 'post_tag' );
		$keywords = $this->keyword_counts();

		$context = '站点名称：' . get_bloginfo( 'name' ) . "\n站点描述：" . get_bloginfo( 'description' ) . "\n";
		$context .= '主要分类：' . implode( '、', wp_list_pluck( $cats, 'name' ) ) . "\n";
		$context .= '主要标签：' . implode( '、', wp_list_pluck( $tags, 'name' ) ) . "\n";
		$context .= '已有关键词：' . implode( '、', array_slice( array_keys( $keywords ), 0, 20 ) );

		$ai     = new AgentSteamer_AI();
		$topics = $ai->suggest_topics( $context, 8 );
		if ( ! is_wp_error( $topics ) ) {
			set_transient( self::TRANSIENT, $topics, 20 * MINUTE_IN_SECONDS );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=agentsteamer-ai-topics&suggested=1' ) );
		exit;
	}

	/**
	 * Render the page.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-ai' ) );
		}

		$cats     = $this->term_counts( 'category' );
		$tags     = $this->term_counts( 'post_tag' );
		$keywords = $this->keyword_counts();
		$suggest  = get_transient( self::TRANSIENT );

		$gaps = array();
		foreach ( array_merge( $tags, $cats ) as $term ) {
			if ( 1 === $term['count'] ) {
				$gaps[] = $term['name'];
			}
		}
		?>
		<div class="wrap asi-wrap">
			<h1><?php esc_html_e( '主题聚类与内容缺口', 'agentsteamer-ai' ); ?></h1>
			<p class="asi-sub"><?php esc_html_e( '查看主题分布，发现需要补充内容的方向。', 'agentsteamer-ai' ); ?></p>
			<?php if ( isset( $_GET['suggested'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '已生成选题建议。', 'agentsteamer-ai' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['queued'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( __( '已为 %d 个选题排队生成草稿（后台运行）。', 'agentsteamer-ai' ), (int) $_GET['queued'] ) ); ?></p></div>
			<?php endif; ?>

			<div class="asi-card">
				<h2><?php esc_html_e( 'AI 选题建议', 'agentsteamer-ai' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="agentsteamer_ai_suggest_topics" />
					<?php wp_nonce_field( 'agentsteamer_ai_suggest_topics' ); ?>
					<p class="description"><?php esc_html_e( '根据现有分类、标签与关键词，生成支柱页与长尾选题建议。', 'agentsteamer-ai' ); ?></p>
					<button type="submit" class="button button-primary" <?php disabled( ! AgentSteamer_AI_Provider_Manager::is_configured() ); ?>><?php esc_html_e( '生成选题建议', 'agentsteamer-ai' ); ?></button>
				</form>
				<?php if ( is_array( $suggest ) && $suggest ) : ?>
					<ul class="asi-topic-list">
						<?php foreach ( $suggest as $topic ) : ?>
							<li>
								<span class="asi-topic-text"><strong><?php echo esc_html( $topic['title'] ); ?></strong> <span class="asi-badge"><?php echo esc_html( $topic['type'] ); ?></span> <?php echo esc_html( $topic['reason'] ); ?></span>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="agentsteamer_ai_generate_topic" />
									<input type="hidden" name="topic" value="<?php echo esc_attr( $topic['title'] ); ?>" />
									<?php wp_nonce_field( 'agentsteamer_ai_generate_topic' ); ?>
									<button type="submit" class="button button-small" <?php disabled( ! AgentSteamer_AI_Provider_Manager::is_configured() ); ?>><?php esc_html_e( '生成草稿', 'agentsteamer-ai' ); ?></button>
								</form>
							</li>
						<?php endforeach; ?>
					</ul>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
						<input type="hidden" name="action" value="agentsteamer_ai_generate_topics_all" />
						<input type="hidden" name="topics" value="<?php echo esc_attr( wp_json_encode( $suggest ) ); ?>" />
						<?php wp_nonce_field( 'agentsteamer_ai_generate_topics_all' ); ?>
						<button type="submit" class="button" <?php disabled( ! AgentSteamer_AI_Provider_Manager::is_configured() ); ?>><?php esc_html_e( '全部生成草稿', 'agentsteamer-ai' ); ?></button>
					</form>
				<?php endif; ?>
			</div>

			<div class="asi-card">
				<h2><?php esc_html_e( '分类分布', 'agentsteamer-ai' ); ?></h2>
				<?php if ( empty( $cats ) ) : ?>
					<p><?php esc_html_e( '暂无分类。', 'agentsteamer-ai' ); ?></p>
				<?php else : ?>
					<ul class="asi-audit-items">
						<?php foreach ( $cats as $term ) : ?>
							<li><?php echo esc_html( $term['name'] ); ?> <span class="asi-badge"><?php echo (int) $term['count']; ?> 篇</span></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>

			<div class="asi-card">
				<h2><?php esc_html_e( '标签分布', 'agentsteamer-ai' ); ?></h2>
				<?php if ( empty( $tags ) ) : ?>
					<p><?php esc_html_e( '暂无标签。', 'agentsteamer-ai' ); ?></p>
				<?php else : ?>
					<ul class="asi-audit-items">
						<?php foreach ( $tags as $term ) : ?>
							<li><?php echo esc_html( $term['name'] ); ?> <span class="asi-badge"><?php echo (int) $term['count']; ?> 篇</span></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>

			<div class="asi-card">
				<h2><?php esc_html_e( '焦点关键词', 'agentsteamer-ai' ); ?></h2>
				<?php if ( empty( $keywords ) ) : ?>
					<p><?php esc_html_e( '暂无焦点关键词。', 'agentsteamer-ai' ); ?></p>
				<?php else : ?>
					<ul class="asi-audit-items">
						<?php foreach ( array_slice( $keywords, 0, 30, true ) as $kw => $count ) : ?>
							<li><?php echo esc_html( $kw ); ?> <span class="asi-badge"><?php echo (int) $count; ?> 篇</span></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>

			<div class="asi-card">
				<h2><?php esc_html_e( '内容缺口（仅有 1 篇的主题）', 'agentsteamer-ai' ); ?></h2>
				<?php if ( empty( $gaps ) ) : ?>
					<p><?php esc_html_e( '未发现单一文章的主题。', 'agentsteamer-ai' ); ?></p>
				<?php else : ?>
					<p><?php echo esc_html( implode( '、', array_slice( $gaps, 0, 40 ) ) ); ?></p>
					<p class="description"><?php esc_html_e( '这些主题内容较少，可补充更多文章以形成主题簇。', 'agentsteamer-ai' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
