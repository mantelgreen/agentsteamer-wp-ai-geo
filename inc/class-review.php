<?php
/**
 * Review queue for AI-proposed changes.
 *
 * @package AgentSteamer_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores, lists, applies and rejects AI-generated changes.
 */
class AgentSteamer_AI_Review {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'agentsteamer_ai_reviews';
	}

	/**
	 * Create the table.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$table   = self::table();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			field varchar(32) NOT NULL DEFAULT 'content',
			old_value longtext,
			new_value longtext,
			summary text,
			changes longtext,
			status varchar(16) NOT NULL DEFAULT 'pending',
			created_at datetime DEFAULT NULL,
			applied_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY status (status)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'menu' ), 20 );
			add_action( 'admin_post_agentsteamer_ai_apply_review', array( $this, 'handle_apply' ) );
			add_action( 'admin_post_agentsteamer_ai_reject_review', array( $this, 'handle_reject' ) );
			add_action( 'admin_post_agentsteamer_ai_rollback_review', array( $this, 'handle_rollback' ) );
		}
	}

	/**
	 * Add a review entry.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   Field.
	 * @param string $old     Old value.
	 * @param string $new     New value.
	 * @param string $summary Summary.
	 * @param array  $changes Changes.
	 * @return int
	 */
	public function add( $post_id, $field, $old, $new, $summary = '', $changes = array() ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB
			self::table(),
			array(
				'post_id'    => (int) $post_id,
				'field'      => $field,
				'old_value'  => $old,
				'new_value'  => $new,
				'summary'    => $summary,
				'changes'    => wp_json_encode( $changes, JSON_UNESCAPED_UNICODE ),
				'status'     => 'pending',
				'created_at' => current_time( 'mysql' ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Get a review.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? $row : null;
	}

	/**
	 * Get pending reviews.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function get_pending( $limit = 50 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE status = 'pending' ORDER BY id DESC LIMIT %d", (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Recent reviews of any status.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function get_recent( $limit = 50 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Count pending reviews.
	 *
	 * @return int
	 */
	public function count_pending() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE status = 'pending'" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Apply a review.
	 *
	 * @param int $id ID.
	 * @return bool
	 */
	public function apply( $id ) {
		$review = $this->get( $id );
		if ( ! $review || 'pending' !== $review['status'] ) {
			return false;
		}

		$post_id = (int) $review['post_id'];
		$field   = $review['field'];
		$new     = $review['new_value'];

		if ( 'content' === $field ) {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $new,
				)
			);
		} else {
			update_post_meta( $post_id, agentsteamer_ai_meta_key( $field ), $new );
		}

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB
			self::table(),
			array(
				'status'     => 'applied',
				'applied_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id )
		);

		// Invalidate the audit cache.
		delete_transient( 'agentsteamer_ai_audit_results' );

		return true;
	}

	/**
	 * Reject a review.
	 *
	 * @param int $id ID.
	 * @return bool
	 */
	public function reject( $id ) {
		$review = $this->get( $id );
		if ( ! $review || 'pending' !== $review['status'] ) {
			return false;
		}
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB
			self::table(),
			array(
				'status'     => 'rejected',
				'applied_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id )
		);
		return true;
	}

	/**
	 * Register the admin menu.
	 */
	public function menu() {
		$pending = $this->count_pending();
		$title   = __( '审阅队列', 'agentsteamer-ai' );
		if ( $pending > 0 ) {
			$title .= ' <span class="update-plugins count-' . $pending . '"><span class="plugin-count">' . $pending . '</span></span>';
		}
		add_submenu_page(
			'agentsteamer-ai',
			__( '审阅队列', 'agentsteamer-ai' ),
			$title,
			'manage_options',
			'agentsteamer-ai-reviews',
			array( $this, 'render' )
		);
	}

	/**
	 * Roll back an applied review to its previous value.
	 *
	 * @param int $id ID.
	 * @return bool
	 */
	public function rollback( $id ) {
		$review = $this->get( $id );
		if ( ! $review || 'applied' !== $review['status'] ) {
			return false;
		}

		$post_id = (int) $review['post_id'];
		$field   = $review['field'];

		if ( 'content' === $field ) {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $review['old_value'],
				)
			);
		} else {
			update_post_meta( $post_id, agentsteamer_ai_meta_key( $field ), $review['old_value'] );
		}

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB
			self::table(),
			array(
				'status'     => 'rolled_back',
				'applied_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id )
		);

		delete_transient( 'agentsteamer_ai_audit_results' );
		return true;
	}

	/**
	 * Handle apply.
	 */
	public function handle_apply() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-ai' ) );
		}
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'agentsteamer_ai_apply_review_' . $id );
		$this->apply( $id );
		wp_safe_redirect( admin_url( 'admin.php?page=agentsteamer-ai-reviews&applied=1' ) );
		exit;
	}

	/**
	 * Handle reject.
	 */
	public function handle_reject() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-ai' ) );
		}
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'agentsteamer_ai_reject_review_' . $id );
		$this->reject( $id );
		wp_safe_redirect( admin_url( 'admin.php?page=agentsteamer-ai-reviews&rejected=1' ) );
		exit;
	}

	/**
	 * Handle rollback.
	 */
	public function handle_rollback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-ai' ) );
		}
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'agentsteamer_ai_rollback_review_' . $id );
		$this->rollback( $id );
		wp_safe_redirect( admin_url( 'admin.php?page=agentsteamer-ai-reviews&rolledback=1' ) );
		exit;
	}

	/**
	 * Render the review queue page.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-ai' ) );
		}
		$rows = $this->get_recent( 50 );
		?>
		<div class="wrap asi-wrap">
			<h1><?php esc_html_e( '审阅队列', 'agentsteamer-ai' ); ?></h1>
			<p class="asi-sub"><?php esc_html_e( 'AI 改动先进入这里，确认后再写入文章。', 'agentsteamer-ai' ); ?></p>
			<?php if ( isset( $_GET['applied'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '已应用改动。', 'agentsteamer-ai' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['rejected'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '已拒绝改动。', 'agentsteamer-ai' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['rolledback'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '已回滚到修改前的版本。', 'agentsteamer-ai' ); ?></p></div>
			<?php endif; ?>

			<?php if ( empty( $rows ) ) : ?>
				<div class="asi-card"><p><?php esc_html_e( '暂无待审改动。可在文章编辑器侧边栏点击「AI 内容优化」生成。', 'agentsteamer-ai' ); ?></p></div>
			<?php endif; ?>

			<?php foreach ( $rows as $row ) : ?>
				<?php
				$post    = get_post( (int) $row['post_id'] );
				$changes = json_decode( (string) $row['changes'], true );
				$status  = $row['status'];
				$badge   = 'pending' === $status ? 'asi-badge-warn' : ( 'applied' === $status ? 'asi-badge-ok' : 'asi-badge' );
				?>
				<div class="asi-card">
					<p>
						<span class="asi-badge <?php echo esc_attr( $badge ); ?>"><?php echo esc_html( $status ); ?></span>
						<strong><?php echo esc_html( $post ? $post->post_title : '(#)' . $row['post_id'] ); ?></strong>
						<span class="asi-hint"><?php echo esc_html( $row['field'] ); ?>，<?php echo esc_html( $row['created_at'] ); ?></span>
						<?php if ( $post ) : ?>
							<a class="asi-inline-link" href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php esc_html_e( '编辑文章', 'agentsteamer-ai' ); ?></a>
						<?php endif; ?>
					</p>
					<?php if ( ! empty( $row['summary'] ) ) : ?>
						<p><strong><?php esc_html_e( '改动摘要', 'agentsteamer-ai' ); ?>：</strong><?php echo esc_html( $row['summary'] ); ?></p>
					<?php endif; ?>
					<?php if ( is_array( $changes ) && ! empty( $changes ) ) : ?>
						<ul class="asi-audit-items">
							<?php foreach ( $changes as $change ) : ?>
								<li><?php echo esc_html( $change ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<?php if ( 'pending' === $status ) : ?>
						<div class="asi-diff">
							<div class="asi-diff-col">
								<h4><?php esc_html_e( '修改前', 'agentsteamer-ai' ); ?></h4>
								<pre><?php echo esc_html( wp_trim_words( wp_strip_all_tags( (string) $row['old_value'] ), 200, '…' ) ); ?></pre>
							</div>
							<div class="asi-diff-col">
								<h4><?php esc_html_e( '修改后', 'agentsteamer-ai' ); ?></h4>
								<pre><?php echo esc_html( wp_trim_words( wp_strip_all_tags( (string) $row['new_value'] ), 200, '…' ) ); ?></pre>
							</div>
						</div>
						<p>
							<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=agentsteamer_ai_apply_review&id=' . $row['id'] ), 'agentsteamer_ai_apply_review_' . $row['id'] ) ); ?>"><?php esc_html_e( '应用', 'agentsteamer-ai' ); ?></a>
							<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=agentsteamer_ai_reject_review&id=' . $row['id'] ), 'agentsteamer_ai_reject_review_' . $row['id'] ) ); ?>"><?php esc_html_e( '拒绝', 'agentsteamer-ai' ); ?></a>
						</p>
					<?php else : ?>
						<div class="asi-diff">
							<div class="asi-diff-col">
								<h4><?php esc_html_e( '修改后', 'agentsteamer-ai' ); ?></h4>
								<pre><?php echo esc_html( wp_trim_words( wp_strip_all_tags( (string) $row['new_value'] ), 120, '…' ) ); ?></pre>
							</div>
						</div>
						<?php if ( 'applied' === $status ) : ?>
							<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=agentsteamer_ai_rollback_review&id=' . $row['id'] ), 'agentsteamer_ai_rollback_review_' . $row['id'] ) ); ?>"><?php esc_html_e( '回滚到修改前', 'agentsteamer-ai' ); ?></a></p>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
