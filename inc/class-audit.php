<?php
/**
 * Site-wide SEO / GEO audit.
 *
 * @package AgentSteamer_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs site-level checks and renders the audit dashboard.
 */
class AgentSteamer_AI_Audit {

	/**
	 * Cache key.
	 */
	const TRANSIENT = 'agentsteamer_ai_audit_results';

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'menu' ), 20 );
			add_action( 'admin_post_agentsteamer_ai_run_audit', array( $this, 'handle_run' ) );
			add_action( 'admin_post_agentsteamer_ai_autofill_all', array( $this, 'handle_autofill_all' ) );
		}
	}

	/**
	 * Register the menu.
	 */
	public function menu() {
		add_submenu_page(
			'agentsteamer-ai',
			__( '站点审计', 'agentsteamer-ai' ),
			__( '站点审计', 'agentsteamer-ai' ),
			'manage_options',
			'agentsteamer-ai-audit',
			array( $this, 'render' )
		);
	}

	/**
	 * Clear the cache and re-run.
	 */
	public function handle_run() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-ai' ) );
		}
		check_admin_referer( 'agentsteamer_ai_run_audit' );
		delete_transient( self::TRANSIENT );
		wp_safe_redirect( admin_url( 'admin.php?page=agentsteamer-ai-audit&ran=1' ) );
		exit;
	}

	/**
	 * Schedule background autofill for all published content with blank fields.
	 */
	public function handle_autofill_all() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-ai' ) );
		}
		check_admin_referer( 'agentsteamer_ai_autofill_all' );

		$count = 0;
		foreach ( agentsteamer_ai_supported_post_types() as $type ) {
			$posts = get_posts(
				array(
					'post_type'     => $type,
					'post_status'   => 'publish',
					'numberposts'   => 2000,
					'no_found_rows' => true,
					'fields'        => 'ids',
				)
			);
			foreach ( $posts as $post_id ) {
				$title = agentsteamer_ai_get_post_meta( $post_id, 'title' );
				$desc  = agentsteamer_ai_get_post_meta( $post_id, 'description' );
				$kw    = agentsteamer_ai_get_post_meta( $post_id, 'focus_keyword' );
				if ( $title && $desc && $kw ) {
					continue;
				}
				if ( ! wp_next_scheduled( 'agentsteamer_ai_autofill', array( $post_id ) ) ) {
					wp_schedule_single_event( time() + ( 5 * $count ), 'agentsteamer_ai_autofill', array( $post_id ) );
					$count++;
				}
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=agentsteamer-ai-audit&queued=' . $count ) );
		exit;
	}

	/**
	 * Build a check row.
	 *
	 * @param string $id      ID.
	 * @param string $label   Label.
	 * @param string $status  pass|warn|fail.
	 * @param string $detail  Detail text.
	 * @param array  $items   Sample items.
	 * @return array
	 */
	protected function check( $id, $label, $status, $detail = '', $items = array() ) {
		return array(
			'id'     => $id,
			'label'  => $label,
			'status' => $status,
			'detail' => $detail,
			'items'  => $items,
		);
	}

	/**
	 * Get audit results (cached).
	 *
	 * @param bool $force Force refresh.
	 * @return array
	 */
	public function get_results( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$results = $this->run();
		set_transient( self::TRANSIENT, $results, 10 * MINUTE_IN_SECONDS );
		return $results;
	}

	/**
	 * Run all checks.
	 *
	 * @return array
	 */
	public function run() {
		$checks = array();

		// Technical.
		$sitemap_on = (bool) agentsteamer_ai_get_option( 'sitemap_enabled', 1 );
		$checks[]   = $this->check( 'sitemap', __( 'XML Sitemap 已启用', 'agentsteamer-ai' ), $sitemap_on ? 'pass' : 'fail', $sitemap_on ? home_url( '/asi-sitemap.xml' ) : __( '请在「设置 → GEO」中启用 Sitemap。', 'agentsteamer-ai' ) );

		$llms_on  = (bool) agentsteamer_ai_get_option( 'llms_txt_enabled', 1 );
		$checks[] = $this->check( 'llms', __( 'llms.txt 已启用', 'agentsteamer-ai' ), $llms_on ? 'pass' : 'warn', $llms_on ? home_url( '/llms.txt' ) : __( '未启用 llms.txt，AI 智能体不便发现内容。', 'agentsteamer-ai' ) );

		$md_on    = (bool) agentsteamer_ai_get_option( 'markdown_enabled', 1 );
		$checks[] = $this->check( 'markdown', __( 'Markdown 输出已启用', 'agentsteamer-ai' ), $md_on ? 'pass' : 'warn', $md_on ? __( '文章提供 .md 版本供 AI 消费。', 'agentsteamer-ai' ) : '' );

		$indexnow = agentsteamer_ai_get_option( 'indexnow_enabled', 0 ) && agentsteamer_ai_get_option( 'indexnow_key' );
		$checks[] = $this->check( 'indexnow', __( 'IndexNow 收录提交已配置', 'agentsteamer-ai' ), $indexnow ? 'pass' : 'warn', $indexnow ? '' : __( '未配置 IndexNow，发布后无法即时通知 Bing 等。', 'agentsteamer-ai' ) );

		$ai_ok    = AgentSteamer_AI_Provider_Manager::is_configured();
		$checks[] = $this->check( 'ai', __( '大模型接口已配置', 'agentsteamer-ai' ), $ai_ok ? 'pass' : 'warn', $ai_ok ? '' : __( '未配置将无法使用 AI 生成与自动补全。', 'agentsteamer-ai' ) );

		$org_name = agentsteamer_ai_get_option( 'org_name' );
		$checks[] = $this->check( 'entity', __( '组织/品牌实体信息已填写', 'agentsteamer-ai' ), $org_name ? 'pass' : 'warn', $org_name ? $org_name : __( '建议填写组织名称与 Logo，增强结构化数据。', 'agentsteamer-ai' ) );

		// Home meta.
		$home_desc = agentsteamer_ai_get_option( 'home_desc' ) ? agentsteamer_ai_get_option( 'home_desc' ) : ( agentsteamer_ai_get_option( 'default_desc' ) ? agentsteamer_ai_get_option( 'default_desc' ) : get_bloginfo( 'description' ) );
		$checks[]  = $this->check( 'home_meta', __( '首页 Meta 描述已设置', 'agentsteamer-ai' ), '' !== trim( (string) $home_desc ) ? 'pass' : 'warn', agentsteamer_ai_trim( $home_desc, 80 ) );

		// Collect posts.
		$posts = get_posts(
			array(
				'post_type'     => agentsteamer_ai_supported_post_types(),
				'post_status'   => 'publish',
				'numberposts'   => 2000,
				'no_found_rows' => true,
			)
		);

		$missing_desc = array();
		$missing_kw   = array();
		$thin         = array();
		$noindex      = array();
		$title_map    = array();

		foreach ( $posts as $post ) {
			$desc = agentsteamer_ai_get_post_meta( $post->ID, 'description' );
			if ( '' === $desc && '' === trim( (string) $post->post_excerpt ) ) {
				$missing_desc[] = $post;
			}
			if ( '' === agentsteamer_ai_get_post_meta( $post->ID, 'focus_keyword' ) ) {
				$missing_kw[] = $post;
			}
			$plain = agentsteamer_ai_plain_content( $post->ID );
			if ( mb_strlen( $plain ) < 300 ) {
				$thin[] = $post;
			}
			if ( '1' === agentsteamer_ai_get_post_meta( $post->ID, 'noindex', '0' ) ) {
				$noindex[] = $post;
			}
			$seo_title = agentsteamer_ai_get_post_meta( $post->ID, 'title' );
			if ( '' !== $seo_title ) {
				$key = mb_strtolower( $seo_title );
				if ( ! isset( $title_map[ $key ] ) ) {
					$title_map[ $key ] = array();
				}
				$title_map[ $key ][] = $post;
			}
		}

		$dup_titles = array_filter(
			$title_map,
			function ( $group ) {
				return count( $group ) > 1;
			}
		);

		$checks[] = $this->make_content_check( 'missing_desc', __( '文章缺少 Meta 描述', 'agentsteamer-ai' ), $missing_desc, __( '缺乏描述的页面在搜索结果中点击率偏低。', 'agentsteamer-ai' ) );
		$checks[] = $this->make_content_check( 'missing_kw', __( '文章缺少焦点关键词', 'agentsteamer-ai' ), $missing_kw, __( '未设置焦点关键词，无法进行针对性优化。', 'agentsteamer-ai' ) );
		$checks[] = $this->make_content_check( 'thin', __( '正文过短（<300 字）', 'agentsteamer-ai' ), $thin, __( '过短内容不利于排名与 AI 引用。', 'agentsteamer-ai' ) );

		$dup_items = array();
		foreach ( $dup_titles as $group ) {
			foreach ( $group as $post ) {
				$dup_items[] = $post;
			}
		}
		$checks[] = $this->make_content_check( 'dup_titles', __( 'SEO 标题重复', 'agentsteamer-ai' ), $dup_items, __( '多个页面使用相同 SEO 标题会造成关键词竞争。', 'agentsteamer-ai' ) );

		$checks[] = $this->make_content_check( 'noindex', __( '已设为 noindex 的文章', 'agentsteamer-ai' ), $noindex, __( '这些文章将不被收录，请确认是否为有意为之。', 'agentsteamer-ai' ), 'warn' );

		$missing_alt = $this->count_missing_alt();
		$checks[]    = $this->check(
			'image_alt',
			__( '图片缺少 Alt 文本', 'agentsteamer-ai' ),
			( 0 === $missing_alt ) ? 'pass' : ( $missing_alt > 10 ? 'fail' : 'warn' ),
			sprintf( __( '共 %d 张图片缺少 Alt 文本。', 'agentsteamer-ai' ), $missing_alt )
		);

		return $checks;
	}

	/**
	 * Build a check from a list of posts.
	 *
	 * @param string $id     ID.
	 * @param string $label  Label.
	 * @param array  $posts  Posts.
	 * @param string $detail Detail.
	 * @param string $warn   Status when non-empty.
	 * @return array
	 */
	protected function make_content_check( $id, $label, $posts, $detail, $warn = 'fail' ) {
		$count = count( $posts );
		if ( 0 === $count ) {
			return $this->check( $id, $label, 'pass', __( '全部通过。', 'agentsteamer-ai' ) );
		}

		$items = array();
		foreach ( array_slice( $posts, 0, 8 ) as $post ) {
			$items[] = array(
				'title' => $post->post_title,
				'url'   => get_edit_post_link( $post->ID, 'raw' ),
			);
		}

		return $this->check( $id, $label, $warn, sprintf( __( '共 %d 篇：', 'agentsteamer-ai' ), $count ) . $detail, $items );
	}

	/**
	 * Count attachments with empty alt text.
	 *
	 * @return int
	 */
	protected function count_missing_alt() {
		$ids = get_posts(
			array(
				'post_type'     => 'attachment',
				'post_mime_type' => 'image',
				'post_status'   => 'inherit',
				'numberposts'   => 2000,
				'no_found_rows' => true,
				'fields'        => 'ids',
			)
		);
		$missing = 0;
		foreach ( $ids as $id ) {
			$alt = get_post_meta( $id, '_wp_attachment_image_alt', true );
			if ( '' === trim( (string) $alt ) ) {
				$missing++;
			}
		}
		return $missing;
	}

	/**
	 * Render the audit page.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-ai' ) );
		}

		$results = $this->get_results();
		$total   = count( $results );
		$pass    = 0;
		$warn    = 0;
		$fail    = 0;
		foreach ( $results as $row ) {
			if ( 'pass' === $row['status'] ) {
				$pass++;
			} elseif ( 'warn' === $row['status'] ) {
				$warn++;
			} else {
				$fail++;
			}
		}
		$score = $total ? (int) round( ( ( $pass + $warn * 0.5 ) / $total ) * 100 ) : 0;
		$color = $score >= 80 ? '#1a7f37' : ( $score >= 50 ? '#b26a00' : '#b32d2e' );
		?>
		<div class="wrap asi-wrap">
			<h1><?php esc_html_e( '站点审计', 'agentsteamer-ai' ); ?></h1>
			<p class="asi-sub"><?php esc_html_e( '检查技术 SEO 与内容质量，并给出改进方向。', 'agentsteamer-ai' ); ?></p>

			<?php if ( isset( $_GET['queued'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( __( '已为 %d 篇内容排队自动补全（后台运行）。', 'agentsteamer-ai' ), (int) $_GET['queued'] ) ); ?></p></div>
			<?php endif; ?>

			<div class="asi-card">
				<div class="asi-score-row">
					<span class="asi-score" style="color:<?php echo esc_attr( $color ); ?>;border-color:<?php echo esc_attr( $color ); ?>"><?php echo esc_html( $score ); ?></span>
					<span class="asi-score-label">
						<?php echo esc_html( sprintf( __( '通过 %1$d · 警告 %2$d · 未通过 %3$d / 共 %4$d', 'agentsteamer-ai' ), $pass, $warn, $fail, $total ) ); ?>
					</span>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:10px;">
					<input type="hidden" name="action" value="agentsteamer_ai_run_audit" />
					<?php wp_nonce_field( 'agentsteamer_ai_run_audit' ); ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( '重新检查', 'agentsteamer-ai' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
					<input type="hidden" name="action" value="agentsteamer_ai_autofill_all" />
					<?php wp_nonce_field( 'agentsteamer_ai_autofill_all' ); ?>
					<button type="submit" class="button" <?php disabled( ! AgentSteamer_AI_Provider_Manager::is_configured() ); ?>><?php esc_html_e( '为全部内容排队 AI 补全', 'agentsteamer-ai' ); ?></button>
				</form>
			</div>

			<div class="asi-card">
				<table class="widefat striped">
					<thead>
						<tr>
							<th style="width:120px;"><?php esc_html_e( '状态', 'agentsteamer-ai' ); ?></th>
							<th><?php esc_html_e( '检查项', 'agentsteamer-ai' ); ?></th>
							<th><?php esc_html_e( '详情', 'agentsteamer-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $results as $row ) : ?>
							<tr>
								<td>
									<?php
									$badge = 'pass' === $row['status'] ? 'asi-badge-ok' : ( 'warn' === $row['status'] ? 'asi-badge-warn' : 'asi-badge-fail' );
									$text  = 'pass' === $row['status'] ? __( '通过', 'agentsteamer-ai' ) : ( 'warn' === $row['status'] ? __( '警告', 'agentsteamer-ai' ) : __( '未通过', 'agentsteamer-ai' ) );
									?>
									<span class="asi-badge <?php echo esc_attr( $badge ); ?>"><?php echo esc_html( $text ); ?></span>
								</td>
								<td><strong><?php echo esc_html( $row['label'] ); ?></strong></td>
								<td>
									<?php
									echo esc_html( $row['detail'] );
									if ( ! empty( $row['items'] ) ) {
										echo '<ul class="asi-audit-items">';
										foreach ( $row['items'] as $item ) {
											echo '<li><a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['title'] ) . '</a></li>';
										}
										echo '</ul>';
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}
}
