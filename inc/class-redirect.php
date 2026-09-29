<?php
/**
 * Redirect manager and 404 monitor.
 *
 * @package AgentSteamer_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles 301/302/307/410/451 redirects, slug-change redirects and 404 logging.
 */
class AgentSteamer_AI_Redirect {

	/**
	 * Old permalink captured before an update.
	 *
	 * @var string
	 */
	protected $old_permalink = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'template_redirect', array( $this, 'handle_request' ), 1 );
		add_action( 'template_redirect', array( $this, 'log_404' ), 20 );
		add_action( 'pre_post_update', array( $this, 'remember_old_permalink' ), 10, 2 );
		add_action( 'post_updated', array( $this, 'maybe_create_slug_redirect' ), 10, 3 );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'menu' ), 20 );
			add_action( 'admin_post_agentsteamer_ai_save_redirect', array( $this, 'handle_save' ) );
			add_action( 'admin_post_agentsteamer_ai_delete_redirect', array( $this, 'handle_delete' ) );
			add_action( 'admin_post_agentsteamer_ai_export_redirects', array( $this, 'handle_export' ) );
			add_action( 'admin_post_agentsteamer_ai_import_redirects', array( $this, 'handle_import' ) );
			add_action( 'admin_post_agentsteamer_ai_add_404_redirect', array( $this, 'handle_add_404_redirect' ) );
			add_action( 'admin_post_agentsteamer_ai_clear_404', array( $this, 'handle_clear_404' ) );
		}
	}

	/**
	 * Redirects table name.
	 *
	 * @return string
	 */
	public static function redirects_table() {
		global $wpdb;
		return $wpdb->prefix . 'agentsteamer_ai_redirects';
	}

	/**
	 * 404 table name.
	 *
	 * @return string
	 */
	public static function notfound_table() {
		global $wpdb;
		return $wpdb->prefix . 'agentsteamer_ai_404';
	}

	/**
	 * Create/upgrade tables.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset   = $wpdb->get_charset_collate();
		$redirects = self::redirects_table();
		$notfound  = self::notfound_table();

		$sql1 = "CREATE TABLE {$redirects} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source varchar(255) NOT NULL DEFAULT '',
			source_hash char(32) NOT NULL DEFAULT '',
			target varchar(500) NOT NULL DEFAULT '',
			type smallint(5) NOT NULL DEFAULT 301,
			status varchar(10) NOT NULL DEFAULT 'enabled',
			source_type varchar(20) NOT NULL DEFAULT 'manual',
			hits bigint(20) unsigned NOT NULL DEFAULT 0,
			last_accessed datetime DEFAULT NULL,
			created_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_hash (source_hash)
		) {$charset};";

		$sql2 = "CREATE TABLE {$notfound} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			path varchar(255) NOT NULL DEFAULT '',
			path_hash char(32) NOT NULL DEFAULT '',
			referrer varchar(255) NOT NULL DEFAULT '',
			user_agent varchar(255) NOT NULL DEFAULT '',
			hits bigint(20) unsigned NOT NULL DEFAULT 0,
			last_accessed datetime DEFAULT NULL,
			created_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY path_hash (path_hash)
		) {$charset};";

		dbDelta( $sql1 );
		dbDelta( $sql2 );
	}

	/* ---------------------------------------------------------------------
	 * Runtime
	 * ------------------------------------------------------------------ */

	/**
	 * Path of the current request relative to the site home.
	 *
	 * @param bool $with_query Include the query string.
	 * @return string
	 */
	protected function current_path( $with_query = false ) {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$parts = wp_parse_url( $uri );
		$path  = isset( $parts['path'] ) ? $parts['path'] : '/';

		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home = '/' . trim( $home, '/' );
		if ( '/' !== $home && 0 === strpos( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) );
		}
		$path = '/' . ltrim( $path, '/' );
		$path = ( '/' === $path ) ? '/' : untrailingslashit( $path );

		if ( $with_query && ! empty( $parts['query'] ) ) {
			$path .= '?' . $parts['query'];
		}
		return $path;
	}

	/**
	 * Convert a full URL to a relative path.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	protected function url_to_relative( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home = '/' . trim( $home, '/' );
		if ( '/' !== $home && 0 === strpos( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) );
		}
		$path = '/' . ltrim( $path, '/' );
		return ( '/' === $path ) ? '/' : untrailingslashit( $path );
	}

	/**
	 * Resolve a stored target into a redirect URL.
	 *
	 * @param string $target Target.
	 * @return string
	 */
	protected function resolve_target( $target ) {
		$target = trim( (string) $target );
		if ( '' === $target ) {
			return home_url( '/' );
		}
		if ( preg_match( '#^https?://#i', $target ) ) {
			return $target;
		}
		return home_url( $target );
	}

	/**
	 * Look up an enabled redirect for a hash.
	 *
	 * @param string $hash Source hash.
	 * @return array|null
	 */
	protected function find_redirect( $hash ) {
		global $wpdb;
		$table = self::redirects_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_hash = %s AND status = 'enabled' LIMIT 1", $hash ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? $row : null;
	}

	/**
	 * Apply a redirect if one matches the current request.
	 */
	public function handle_request() {
		if ( is_admin() || is_robots() || is_feed() || is_trackback() ) {
			return;
		}
		if ( get_query_var( 'asi_llms' ) || get_query_var( 'asi_sitemap' ) || get_query_var( 'asi_md' ) || get_query_var( 'asi_indexnow_key' ) ) {
			return;
		}

		$with_query = $this->current_path( true );
		$path       = $this->current_path( false );

		$row = $this->find_redirect( md5( $with_query ) );
		if ( ! $row && $with_query !== $path ) {
			$row = $this->find_redirect( md5( $path ) );
		}
		if ( ! $row ) {
			return;
		}

		global $wpdb;
		$table = self::redirects_table();
		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "UPDATE {$table} SET hits = hits + 1, last_accessed = %s WHERE id = %d", current_time( 'mysql' ), $row['id'] )
		);

		$type = (int) $row['type'];

		if ( 410 === $type || 451 === $type ) {
			status_header( $type );
			nocache_headers();
			header( 'Content-Type: text/html; charset=utf-8' );
			echo '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html( (string) $type ) . '</title></head><body><h1>' . esc_html( (string) $type ) . '</h1></body></html>';
			exit;
		}

		$allowed = array( 301, 302, 303, 307, 308 );
		if ( ! in_array( $type, $allowed, true ) ) {
			$type = 301;
		}

		wp_redirect( $this->resolve_target( $row['target'] ), $type ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}

	/**
	 * Log 404 requests.
	 */
	public function log_404() {
		if ( ! is_404() || ! agentsteamer_ai_get_option( 'notfound_log', 1 ) ) {
			return;
		}

		$path = $this->current_path( true );
		if ( '' === $path || '/' === $path ) {
			return;
		}
		if ( preg_match( '/\.(css|js|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|map)$/i', $path ) ) {
			return;
		}
		if ( preg_match( '#^/(wp-[a-z0-9\-]+\.php|wp-json/|wp-admin/|wp-content/|wp-includes/|xmlrpc\.php)#i', $path ) ) {
			return;
		}

		global $wpdb;
		$table = self::notfound_table();
		$hash  = md5( $path );
		$id    = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE path_hash = %s", $hash ) ); // phpcs:ignore WordPress.DB

		if ( $id ) {
			$wpdb->query( // phpcs:ignore WordPress.DB
				$wpdb->prepare( "UPDATE {$table} SET hits = hits + 1, last_accessed = %s WHERE id = %d", current_time( 'mysql' ), $id )
			);
			return;
		}

		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$agent    = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( substr( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ), 0, 255 ) ) : '';

		$wpdb->insert( // phpcs:ignore WordPress.DB
			$table,
			array(
				'path'          => substr( $path, 0, 255 ),
				'path_hash'     => $hash,
				'referrer'      => substr( $referrer, 0, 255 ),
				'user_agent'    => $agent,
				'hits'          => 1,
				'last_accessed' => current_time( 'mysql' ),
				'created_at'    => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Remember the permalink before an update.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    Update data.
	 */
	public function remember_old_permalink( $post_id, $data ) {
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( isset( $data['post_status'] ) && 'publish' !== $data['post_status'] && 'publish' !== get_post_status( $post_id ) ) {
			// still capture; publishing transition is handled separately.
		}
		$this->old_permalink = (string) get_permalink( $post_id );
	}

	/**
	 * Create a redirect when a published post's permalink changes.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $after   After.
	 * @param WP_Post $before  Before.
	 */
	public function maybe_create_slug_redirect( $post_id, $after, $before ) {
		if ( ! agentsteamer_ai_get_option( 'redirect_slug_change', 1 ) ) {
			return;
		}
		if ( ! $after instanceof WP_Post || 'publish' !== $after->post_status ) {
			return;
		}
		// Only redirect permalinks that were already public; ignore drafts / first publish.
		if ( ! $before instanceof WP_Post || 'publish' !== $before->post_status ) {
			return;
		}
		if ( ! in_array( $after->post_type, agentsteamer_ai_supported_post_types(), true ) ) {
			return;
		}
		// Skip query-string style permalinks (e.g. draft ?p=ID), which resolve to "/".
		if ( '' === $this->old_permalink || false !== strpos( $this->old_permalink, '?' ) ) {
			return;
		}

		$new = (string) get_permalink( $post_id );
		if ( '' === $new || $new === $this->old_permalink ) {
			return;
		}

		$source = $this->url_to_relative( $this->old_permalink );
		if ( '' === $source || '/' === $source ) {
			return;
		}

		$this->add_redirect( $source, $new, 301, 'slug' );
	}

	/**
	 * Insert or update a redirect.
	 *
	 * @param string $source      Source path.
	 * @param string $target      Target URL/path.
	 * @param int    $type        Redirect type.
	 * @param string $source_type Source type.
	 * @return int|false
	 */
	public function add_redirect( $source, $target, $type = 301, $source_type = 'manual' ) {
		global $wpdb;
		$source = trim( (string) $source );
		if ( '' === $source ) {
			return false;
		}
		if ( 0 !== strpos( $source, '/' ) && ! preg_match( '#^https?://#i', $source ) ) {
			$source = '/' . $source;
		}
		if ( preg_match( '#^https?://#i', $source ) ) {
			$source = $this->url_to_relative( $source );
		}
		$source = ( '/' === $source ) ? '/' : untrailingslashit( $source );

		$table = self::redirects_table();
		$hash  = md5( $source );

		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE source_hash = %s", $hash ) ); // phpcs:ignore WordPress.DB
		$data     = array(
			'source'      => substr( $source, 0, 255 ),
			'source_hash' => $hash,
			'target'      => substr( (string) $target, 0, 500 ),
			'type'        => (int) $type,
			'status'      => 'enabled',
			'source_type' => $source_type,
		);

		if ( $existing ) {
			$wpdb->update( $table, $data, array( 'id' => $existing ) ); // phpcs:ignore WordPress.DB
			return (int) $existing;
		}

		$data['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB
		return (int) $wpdb->insert_id;
	}

	/* ---------------------------------------------------------------------
	 * Admin
	 * ------------------------------------------------------------------ */

	/**
	 * Register admin menus.
	 */
	public function menu() {
		add_submenu_page(
			'agentsteamer-ai',
			__( '重定向', 'agentsteamer-ai' ),
			__( '重定向', 'agentsteamer-ai' ),
			'manage_options',
			'agentsteamer-ai-redirects',
			array( $this, 'render_redirects' )
		);
		add_submenu_page(
			'agentsteamer-ai',
			__( '404 记录', 'agentsteamer-ai' ),
			__( '404 记录', 'agentsteamer-ai' ),
			'manage_options',
			'agentsteamer-ai-404',
			array( $this, 'render_404' )
		);
	}

	/**
	 * Save a redirect from the admin form.
	 */
	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-ai' ) );
		}
		check_admin_referer( 'agentsteamer_ai_save_redirect' );

		$source = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '';
		$target = isset( $_POST['target'] ) ? esc_url_raw( wp_unslash( $_POST['target'] ) ) : '';
		$type   = isset( $_POST['type'] ) ? (int) $_POST['type'] : 301;
		$id     = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( '' !== $source ) {
			if ( $id ) {
				global $wpdb;
				$wpdb->update( // phpcs:ignore WordPress.DB
					self::redirects_table(),
					array(
						'source'      => substr( $source, 0, 255 ),
						'source_hash' => md5( $source ),
						'target'      => substr( $target, 0, 500 ),
						'type'        => $type,
					),
					array( 'id' => $id )
				);
			} else {
				$this->add_redirect( $source, $target, $type, 'manual' );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=agentsteamer-ai-redirects&updated=1' ) );
		exit;
	}

	/**
	 * Delete a redirect.
	 */
	public function handle_delete() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-ai' ) );
		}
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'agentsteamer_ai_delete_redirect_' . $id );

		global $wpdb;
		$wpdb->delete( self::redirects_table(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
		wp_safe_redirect( admin_url( 'admin.php?page=agentsteamer-ai-redirects&deleted=1' ) );
		exit;
	}

	/**
	 * Export redirects as CSV.
	 */
	public function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-ai' ) );
		}
		check_admin_referer( 'agentsteamer_ai_export_redirects' );

		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT source, target, type, status FROM ' . self::redirects_table() . ' ORDER BY id ASC', ARRAY_A ); // phpcs:ignore WordPress.DB

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=agentsteamer-redirects.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'source', 'target', 'type', 'status' ) );
		foreach ( (array) $rows as $row ) {
			fputcsv( $out, array( $row['source'], $row['target'], $row['type'], $row['status'] ) );
		}
		fclose( $out );
		exit;
	}

	/**
	 * Import redirects from CSV.
	 */
	public function handle_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-ai' ) );
		}
		check_admin_referer( 'agentsteamer_ai_import_redirects' );

		$count = 0;
		if ( ! empty( $_FILES['csv']['tmp_name'] ) && is_uploaded_file( $_FILES['csv']['tmp_name'] ) ) {
			$handle = fopen( $_FILES['csv']['tmp_name'], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( $handle ) {
				$first = true;
				while ( false !== ( $row = fgetcsv( $handle ) ) ) {
					if ( $first ) {
						$first = false;
						if ( ! isset( $row[1] ) || 'target' === strtolower( (string) $row[0] ) ) {
							continue; // header row.
						}
					}
					$source = isset( $row[0] ) ? sanitize_text_field( $row[0] ) : '';
					$target = isset( $row[1] ) ? esc_url_raw( $row[1] ) : '';
					$type   = isset( $row[2] ) ? (int) $row[2] : 301;
					if ( '' !== $source ) {
						$this->add_redirect( $source, $target, $type ? $type : 301, 'import' );
						$count++;
					}
				}
				fclose( $handle );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=agentsteamer-ai-redirects&imported=' . $count ) );
		exit;
	}

	/**
	 * Create a redirect from a 404 entry.
	 */
	public function handle_add_404_redirect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-ai' ) );
		}
		check_admin_referer( 'agentsteamer_ai_add_404_redirect' );

		$path   = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '';
		$target = isset( $_POST['target'] ) ? esc_url_raw( wp_unslash( $_POST['target'] ) ) : '';
		if ( '' !== $path ) {
			$this->add_redirect( $path, $target ? $target : home_url( '/' ), 301, '404' );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=agentsteamer-ai-404&added=1' ) );
		exit;
	}

	/**
	 * Clear the 404 log.
	 */
	public function handle_clear_404() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-ai' ) );
		}
		check_admin_referer( 'agentsteamer_ai_clear_404' );
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . self::notfound_table() ); // phpcs:ignore WordPress.DB
		wp_safe_redirect( admin_url( 'admin.php?page=agentsteamer-ai-404&cleared=1' ) );
		exit;
	}

	/**
	 * Render redirects page.
	 */
	public function render_redirects() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-ai' ) );
		}
		global $wpdb;
		$table = self::redirects_table();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 500", ARRAY_A ); // phpcs:ignore WordPress.DB

		$edit = null;
		if ( isset( $_GET['edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$edit = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $_GET['edit'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
		}
		?>
		<div class="wrap asi-wrap">
			<h1><?php esc_html_e( '重定向', 'agentsteamer-ai' ); ?></h1>
			<p class="asi-sub"><?php esc_html_e( '管理 301/302/410 等跳转；文章改地址时自动创建。', 'agentsteamer-ai' ); ?></p>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '已保存。', 'agentsteamer-ai' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['imported'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( __( '已导入 %d 条。', 'agentsteamer-ai' ), (int) $_GET['imported'] ) ); ?></p></div>
			<?php endif; ?>

			<div class="asi-card">
				<h2><?php echo $edit ? esc_html__( '编辑重定向', 'agentsteamer-ai' ) : esc_html__( '新增重定向', 'agentsteamer-ai' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="agentsteamer_ai_save_redirect" />
					<input type="hidden" name="id" value="<?php echo esc_attr( $edit ? $edit['id'] : 0 ); ?>" />
					<?php wp_nonce_field( 'agentsteamer_ai_save_redirect' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th><label for="asi-redirect-source"><?php esc_html_e( '来源路径', 'agentsteamer-ai' ); ?></label></th>
							<td><input type="text" id="asi-redirect-source" name="source" class="large-text" value="<?php echo esc_attr( $edit ? $edit['source'] : '' ); ?>" placeholder="/old-path" required /></td>
						</tr>
						<tr>
							<th><label for="asi-redirect-target"><?php esc_html_e( '目标 URL', 'agentsteamer-ai' ); ?></label></th>
							<td><input type="text" id="asi-redirect-target" name="target" class="large-text" value="<?php echo esc_attr( $edit ? $edit['target'] : '' ); ?>" placeholder="<?php echo esc_attr( home_url( '/new-path' ) ); ?>" /></td>
						</tr>
						<tr>
							<th><label for="asi-redirect-type"><?php esc_html_e( '类型', 'agentsteamer-ai' ); ?></label></th>
							<td>
								<select id="asi-redirect-type" name="type">
									<?php
									$types = array(
										301 => '301 永久',
										302 => '302 临时',
										307 => '307 保持方法',
										308 => '308 永久保持方法',
										410 => '410 已删除',
										451 => '451 法律原因不可用',
									);
									$cur = $edit ? (int) $edit['type'] : 301;
									foreach ( $types as $code => $label ) {
										echo '<option value="' . esc_attr( $code ) . '" ' . selected( $cur, $code, false ) . '>' . esc_html( $label ) . '</option>';
									}
									?>
								</select>
							</td>
						</tr>
					</table>
					<?php submit_button( $edit ? __( '保存修改', 'agentsteamer-ai' ) : __( '添加', 'agentsteamer-ai' ) ); ?>
				</form>
			</div>

			<div class="asi-card">
				<h2><?php esc_html_e( '导入 / 导出', 'agentsteamer-ai' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="display:inline-block;margin-right:16px;">
					<input type="hidden" name="action" value="agentsteamer_ai_import_redirects" />
					<?php wp_nonce_field( 'agentsteamer_ai_import_redirects' ); ?>
					<input type="file" name="csv" accept=".csv,text/csv" />
					<button type="submit" class="button"><?php esc_html_e( '导入 CSV', 'agentsteamer-ai' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
					<input type="hidden" name="action" value="agentsteamer_ai_export_redirects" />
					<?php wp_nonce_field( 'agentsteamer_ai_export_redirects' ); ?>
					<button type="submit" class="button"><?php esc_html_e( '导出 CSV', 'agentsteamer-ai' ); ?></button>
				</form>
				<p class="description"><?php esc_html_e( 'CSV 列：source,target,type,status', 'agentsteamer-ai' ); ?></p>
			</div>

			<div class="asi-card">
				<h2><?php esc_html_e( '全部重定向', 'agentsteamer-ai' ); ?></h2>
				<?php if ( empty( $rows ) ) : ?>
					<p><?php esc_html_e( '暂无重定向。', 'agentsteamer-ai' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( '来源', 'agentsteamer-ai' ); ?></th>
								<th><?php esc_html_e( '目标', 'agentsteamer-ai' ); ?></th>
								<th><?php esc_html_e( '类型', 'agentsteamer-ai' ); ?></th>
								<th><?php esc_html_e( '来源类型', 'agentsteamer-ai' ); ?></th>
								<th><?php esc_html_e( '命中', 'agentsteamer-ai' ); ?></th>
								<th><?php esc_html_e( '最近', 'agentsteamer-ai' ); ?></th>
								<th><?php esc_html_e( '操作', 'agentsteamer-ai' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $row ) : ?>
								<tr>
									<td><code><?php echo esc_html( $row['source'] ); ?></code></td>
									<td><?php echo esc_html( $row['target'] ); ?></td>
									<td><?php echo esc_html( $row['type'] ); ?></td>
									<td><?php echo esc_html( $row['source_type'] ); ?></td>
									<td><?php echo esc_html( $row['hits'] ); ?></td>
									<td><?php echo esc_html( $row['last_accessed'] ); ?></td>
									<td>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentsteamer-ai-redirects&edit=' . $row['id'] ) ); ?>"><?php esc_html_e( '编辑', 'agentsteamer-ai' ); ?></a> |
										<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=agentsteamer_ai_delete_redirect&id=' . $row['id'] ), 'agentsteamer_ai_delete_redirect_' . $row['id'] ) ); ?>" onclick="return confirm('<?php echo esc_js( __( '确定删除？', 'agentsteamer-ai' ) ); ?>');"><?php esc_html_e( '删除', 'agentsteamer-ai' ); ?></a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render 404 page.
	 */
	public function render_404() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-ai' ) );
		}
		global $wpdb;
		$table = self::notfound_table();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY hits DESC, id DESC LIMIT 200", ARRAY_A ); // phpcs:ignore WordPress.DB
		?>
		<div class="wrap asi-wrap">
			<h1><?php esc_html_e( '404 记录', 'agentsteamer-ai' ); ?></h1>
			<p class="asi-sub"><?php esc_html_e( '查看访问失败最多的地址，一键转为跳转。', 'agentsteamer-ai' ); ?></p>
			<?php if ( isset( $_GET['added'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '已创建重定向。', 'agentsteamer-ai' ); ?></p></div>
			<?php endif; ?>

			<div class="asi-card">
				<p class="description"><?php esc_html_e( '记录访问不存在的 URL，可将高频 404 一键转为 301 重定向。', 'agentsteamer-ai' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="agentsteamer_ai_clear_404" />
					<?php wp_nonce_field( 'agentsteamer_ai_clear_404' ); ?>
					<button type="submit" class="button"><?php esc_html_e( '清空记录', 'agentsteamer-ai' ); ?></button>
				</form>
			</div>

			<div class="asi-card">
				<?php if ( empty( $rows ) ) : ?>
					<p><?php esc_html_e( '暂无 404 记录。', 'agentsteamer-ai' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( '路径', 'agentsteamer-ai' ); ?></th>
								<th><?php esc_html_e( '次数', 'agentsteamer-ai' ); ?></th>
								<th><?php esc_html_e( '来源页', 'agentsteamer-ai' ); ?></th>
								<th><?php esc_html_e( '最近', 'agentsteamer-ai' ); ?></th>
								<th><?php esc_html_e( '创建重定向', 'agentsteamer-ai' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $row ) : ?>
								<tr>
									<td><code><?php echo esc_html( $row['path'] ); ?></code></td>
									<td><?php echo esc_html( $row['hits'] ); ?></td>
									<td><?php echo esc_html( $row['referrer'] ); ?></td>
									<td><?php echo esc_html( $row['last_accessed'] ); ?></td>
									<td>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:6px;">
											<input type="hidden" name="action" value="agentsteamer_ai_add_404_redirect" />
											<input type="hidden" name="path" value="<?php echo esc_attr( $row['path'] ); ?>" />
											<?php wp_nonce_field( 'agentsteamer_ai_add_404_redirect' ); ?>
											<input type="text" name="target" class="regular-text" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>" />
											<button type="submit" class="button"><?php esc_html_e( '301', 'agentsteamer-ai' ); ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
