<?php
/**
 * Overview dashboard.
 *
 * @package AgentSteamer_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aggregates SEO / GEO / indexing / audit status into one page.
 */
class AgentSteamer_AI_Dashboard {

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		}
	}

	/**
	 * Register the menu (first submenu).
	 */
	public function menu() {
		add_submenu_page(
			'agentsteamer-ai',
			__( '总览', 'agentsteamer-ai' ),
			__( '总览', 'agentsteamer-ai' ),
			'manage_options',
			'agentsteamer-ai-dashboard',
			array( $this, 'render' )
		);
	}

	/**
	 * Stat card markup.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 * @param string $note  Note.
	 * @param string $color Value color.
	 */
	protected function card( $label, $value, $note = '', $color = '#1d2327' ) {
		echo '<div class="asi-stat">';
		echo '<div class="asi-stat-label">' . esc_html( $label ) . '</div>';
		echo '<div class="asi-stat-value" style="color:' . esc_attr( $color ) . '">' . esc_html( $value ) . '</div>';
		if ( $note ) {
			echo '<div class="asi-stat-note">' . wp_kses_post( $note ) . '</div>';
		}
		echo '</div>';
	}

	/**
	 * Render the dashboard.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-ai' ) );
		}

		global $wpdb;
		$posts    = get_posts(
			array(
				'post_type'     => agentsteamer_ai_supported_post_types(),
				'post_status'   => 'publish',
				'numberposts'   => 2000,
				'no_found_rows' => true,
			)
		);
		$total    = count( $posts );
		$with_d   = 0;
		$with_kw  = 0;
		foreach ( $posts as $post ) {
			$desc = agentsteamer_ai_get_post_meta( $post->ID, 'description' );
			if ( '' !== $desc || '' !== trim( (string) $post->post_excerpt ) ) {
				$with_d++;
			}
			if ( '' !== agentsteamer_ai_get_post_meta( $post->ID, 'focus_keyword' ) ) {
				$with_kw++;
			}
		}
		$coverage = $total ? (int) round( ( $with_d / $total ) * 100 ) : 0;

		$audit        = new AgentSteamer_AI_Audit();
		$audit_rows   = $audit->get_results();
		$pass = $warn = $fail = 0;
		foreach ( $audit_rows as $row ) {
			if ( 'pass' === $row['status'] ) {
				$pass++;
			} elseif ( 'warn' === $row['status'] ) {
				$warn++;
			} else {
				$fail++;
			}
		}
		$audit_total = count( $audit_rows );
		$audit_score = $audit_total ? (int) round( ( ( $pass + $warn * 0.5 ) / $audit_total ) * 100 ) : 0;

		$review = new AgentSteamer_AI_Review();
		$pending_reviews = $review->count_pending();

		$redirects = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . AgentSteamer_AI_Redirect::redirects_table() ); // phpcs:ignore WordPress.DB
		$notfound  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . AgentSteamer_AI_Redirect::notfound_table() ); // phpcs:ignore WordPress.DB
		$top404    = $wpdb->get_row( 'SELECT path, hits FROM ' . AgentSteamer_AI_Redirect::notfound_table() . ' ORDER BY hits DESC LIMIT 1', ARRAY_A ); // phpcs:ignore WordPress.DB

		$index_log     = get_option( 'agentsteamer_ai_index_log', array() );
		$index_count   = is_array( $index_log ) ? count( $index_log ) : 0;
		$index_enabled = ( agentsteamer_ai_get_option( 'indexnow_enabled', 0 ) && agentsteamer_ai_get_option( 'indexnow_key' ) ) || ( agentsteamer_ai_get_option( 'baidu_enabled', 0 ) && agentsteamer_ai_get_option( 'baidu_token' ) );

		$ai_ok = AgentSteamer_AI_Provider_Manager::is_configured();

		$audit_color  = $audit_score >= 80 ? '#1a7f37' : ( $audit_score >= 50 ? '#b26a00' : '#b32d2e' );
		$cov_color    = $coverage >= 80 ? '#1a7f37' : ( $coverage >= 50 ? '#b26a00' : '#b32d2e' );
		?>
		<div class="wrap asi-wrap">
			<h1><?php esc_html_e( '总览', 'agentsteamer-ai' ); ?></h1>
			<p class="asi-sub"><?php esc_html_e( '站点 SEO、GEO、收录与待审改动的一览。', 'agentsteamer-ai' ); ?></p>

			<div class="asi-banner">
				<span class="asi-banner-emblem"><img src="<?php echo esc_url( AGENTSTEAMER_AI_URL . 'assets/images/logo.png' ); ?>" alt="" width="46" height="46" /></span>
				<div class="asi-banner-body">
					<p class="asi-banner-title"><?php esc_html_e( '模釜智能体平台', 'agentsteamer-ai' ); ?></p>
					<p class="asi-banner-sub"><?php esc_html_e( '适用于电商、广告、PPT 制作、办公、数字员工等场景的企业级 AI 智能体，支持私有化部署、数据不出域。', 'agentsteamer-ai' ); ?></p>
				</div>
				<div class="asi-banner-price">
					<span class="asi-price-lead"><?php esc_html_e( '低至', 'agentsteamer-ai' ); ?></span>
					<span class="asi-price-main">39<span class="asi-price-unit"><?php esc_html_e( '元/席/月', 'agentsteamer-ai' ); ?></span></span>
				</div>
				<a class="asi-banner-cta button" href="https://www.agentsteamer.com" target="_blank" rel="noopener"><?php esc_html_e( '访问模釜官网', 'agentsteamer-ai' ); ?></a>
			</div>

			<div class="asi-stat-grid">
				<?php
				$this->card( __( 'SEO 覆盖（描述）', 'agentsteamer-ai' ), $coverage . '%', sprintf( __( '%1$d / %2$d 篇有描述', 'agentsteamer-ai' ), $with_d, $total ), $cov_color );
				$this->card( __( '焦点关键词', 'agentsteamer-ai' ), $with_kw . ' / ' . $total, __( '已设置关键词的篇数', 'agentsteamer-ai' ) );
				$this->card( __( '审计评分', 'agentsteamer-ai' ), $audit_score, sprintf( __( '通过 %1$d · 警告 %2$d · 未通过 %3$d', 'agentsteamer-ai' ), $pass, $warn, $fail ), $audit_color );
				$this->card( __( '待审改动', 'agentsteamer-ai' ), $pending_reviews, '<a href="' . esc_url( admin_url( 'admin.php?page=agentsteamer-ai-reviews' ) ) . '">' . esc_html__( '前往审阅', 'agentsteamer-ai' ) . '</a>' );
				$this->card( __( '重定向', 'agentsteamer-ai' ), $redirects, '<a href="' . esc_url( admin_url( 'admin.php?page=agentsteamer-ai-redirects' ) ) . '">' . esc_html__( '管理', 'agentsteamer-ai' ) . '</a>' );
				$this->card( __( '404 记录', 'agentsteamer-ai' ), $notfound, $top404 ? esc_html( $top404['path'] . ' × ' . $top404['hits'] ) : '<a href="' . esc_url( admin_url( 'admin.php?page=agentsteamer-ai-404' ) ) . '">' . esc_html__( '查看', 'agentsteamer-ai' ) . '</a>' );
				?>
			</div>

			<div class="asi-card">
				<h2><?php esc_html_e( 'GEO 与收录', 'agentsteamer-ai' ); ?></h2>
				<ul class="asi-status-list">
					<li><span class="asi-badge <?php echo agentsteamer_ai_get_option( 'sitemap_enabled', 1 ) ? 'asi-badge-ok' : 'asi-badge-warn'; ?>">Sitemap</span> <a href="<?php echo esc_url( home_url( '/asi-sitemap.xml' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( home_url( '/asi-sitemap.xml' ) ); ?></a></li>
					<li><span class="asi-badge <?php echo agentsteamer_ai_get_option( 'llms_txt_enabled', 1 ) ? 'asi-badge-ok' : 'asi-badge-warn'; ?>">llms.txt</span> <a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( home_url( '/llms.txt' ) ); ?></a></li>
					<li><span class="asi-badge <?php echo agentsteamer_ai_get_option( 'markdown_enabled', 1 ) ? 'asi-badge-ok' : 'asi-badge-warn'; ?>">Markdown</span> <?php esc_html_e( '文章提供 .md 供 AI 消费', 'agentsteamer-ai' ); ?></li>
					<li><span class="asi-badge <?php echo $index_enabled ? 'asi-badge-ok' : 'asi-badge-warn'; ?>">IndexNow/百度</span> <?php echo $index_enabled ? esc_html__( '已配置', 'agentsteamer-ai' ) : esc_html__( '未配置', 'agentsteamer-ai' ); ?>，<?php echo esc_html( sprintf( __( '提交记录 %d 条', 'agentsteamer-ai' ), $index_count ) ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=agentsteamer-ai-indexing' ) ); ?>"><?php esc_html_e( '收录提交', 'agentsteamer-ai' ); ?></a></li>
					<li><span class="asi-badge <?php echo $ai_ok ? 'asi-badge-ok' : 'asi-badge-warn'; ?>"><?php esc_html_e( '大模型接口', 'agentsteamer-ai' ); ?></span> <?php echo $ai_ok ? esc_html( agentsteamer_ai_get_option( 'provider' ) . ' / ' . agentsteamer_ai_get_option( 'model' ) ) : esc_html__( '未配置', 'agentsteamer-ai' ); ?></li>
				</ul>
			</div>

			<div class="asi-card">
				<h2><?php esc_html_e( '下一步', 'agentsteamer-ai' ); ?></h2>
				<p class="asi-actions">
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=agentsteamer-ai' ) ); ?>"><?php esc_html_e( '生成一篇文章', 'agentsteamer-ai' ); ?></a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentsteamer-ai-audit' ) ); ?>"><?php esc_html_e( '查看站点审计', 'agentsteamer-ai' ); ?></a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentsteamer-ai-images' ) ); ?>"><?php esc_html_e( '补齐图片 Alt', 'agentsteamer-ai' ); ?></a>
				</p>
			</div>
		</div>
		<?php
	}
}
