<?php
/**
 * Image SEO: AI alt-text generation.
 *
 * @package AgentSteamer_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates and manages image alt text.
 */
class AgentSteamer_AI_Image_Seo {

	/**
	 * Cron hook.
	 */
	const CRON = 'agentsteamer_ai_generate_alt';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( self::CRON, array( $this, 'run_alt' ) );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'menu' ), 20 );
			add_action( 'admin_post_agentsteamer_ai_gen_alt_one', array( $this, 'handle_one' ) );
			add_action( 'admin_post_agentsteamer_ai_gen_alt_all', array( $this, 'handle_all' ) );
		}
	}

	/**
	 * Register the menu.
	 */
	public function menu() {
		add_submenu_page(
			'agentsteamer-ai',
			__( '图片 SEO', 'agentsteamer-ai' ),
			__( '图片 SEO', 'agentsteamer-ai' ),
			'manage_options',
			'agentsteamer-ai-images',
			array( $this, 'render' )
		);
	}

	/**
	 * Generate and store alt text for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public function run_alt( $attachment_id ) {
		if ( ! agentsteamer_ai_get_option( 'ai_enabled', 1 ) ) {
			return false;
		}
		$ai  = new AgentSteamer_AI();
		$alt = $ai->generate_alt( (int) $attachment_id );
		if ( is_wp_error( $alt ) ) {
			return false;
		}
		update_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', $alt );
		return true;
	}

	/**
	 * Handle a single-image generation request.
	 */
	public function handle_one() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-ai' ) );
		}
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'agentsteamer_ai_gen_alt_one_' . $id );

		$ok = $this->run_alt( $id );
		wp_safe_redirect( admin_url( 'admin.php?page=agentsteamer-ai-images&done=' . ( $ok ? 1 : 0 ) ) );
		exit;
	}

	/**
	 * Queue alt generation for all images missing alt text.
	 */
	public function handle_all() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-ai' ) );
		}
		check_admin_referer( 'agentsteamer_ai_gen_alt_all' );

		$count = 0;
		foreach ( $this->missing_alt_ids() as $id ) {
			if ( ! wp_next_scheduled( self::CRON, array( $id ) ) ) {
				wp_schedule_single_event( time() + ( 5 * $count ), self::CRON, array( $id ) );
				$count++;
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=agentsteamer-ai-images&queued=' . $count ) );
		exit;
	}

	/**
	 * Attachment IDs missing alt text.
	 *
	 * @return int[]
	 */
	protected function missing_alt_ids() {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				'numberposts'    => 2000,
				'no_found_rows'  => true,
				'fields'         => 'ids',
			)
		);
		$missing = array();
		foreach ( $ids as $id ) {
			$alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
			if ( '' === trim( $alt ) ) {
				$missing[] = $id;
			}
		}
		return $missing;
	}

	/**
	 * Render the image SEO page.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-ai' ) );
		}

		$missing = $this->missing_alt_ids();
		?>
		<div class="wrap asi-wrap">
			<h1><?php esc_html_e( '图片 SEO', 'agentsteamer-ai' ); ?></h1>
			<p class="asi-sub"><?php esc_html_e( '为缺少 Alt 文本的图片生成描述。', 'agentsteamer-ai' ); ?></p>

			<?php if ( isset( $_GET['done'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php echo '1' === $_GET['done'] ? esc_html__( '已生成 Alt 文本。', 'agentsteamer-ai' ) : esc_html__( '生成失败。', 'agentsteamer-ai' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['queued'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( __( '已为 %d 张图片排队生成 Alt（后台运行）。', 'agentsteamer-ai' ), (int) $_GET['queued'] ) ); ?></p></div>
			<?php endif; ?>

			<div class="asi-card">
				<p class="description"><?php echo esc_html( sprintf( __( '当前有 %d 张图片缺少 Alt 文本。', 'agentsteamer-ai' ), count( $missing ) ) ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="agentsteamer_ai_gen_alt_all" />
					<?php wp_nonce_field( 'agentsteamer_ai_gen_alt_all' ); ?>
					<button type="submit" class="button button-primary" <?php disabled( empty( $missing ) ); disabled( ! AgentSteamer_AI_Provider_Manager::is_configured() ); ?>><?php esc_html_e( '为缺失 Alt 的图片批量生成', 'agentsteamer-ai' ); ?></button>
				</form>
			</div>

			<div class="asi-card">
				<?php if ( empty( $missing ) ) : ?>
					<p><?php esc_html_e( '所有图片都已有 Alt 文本。', 'agentsteamer-ai' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( '缩略图', 'agentsteamer-ai' ); ?></th>
								<th><?php esc_html_e( '标题', 'agentsteamer-ai' ); ?></th>
								<th><?php esc_html_e( '文件名', 'agentsteamer-ai' ); ?></th>
								<th><?php esc_html_e( '操作', 'agentsteamer-ai' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							foreach ( array_slice( $missing, 0, 100 ) as $id ) :
								$file = get_attached_file( $id );
								?>
								<tr>
									<td><?php echo wp_get_attachment_image( $id, 'thumbnail' ); ?></td>
									<td><?php echo esc_html( get_the_title( $id ) ); ?></td>
									<td><code><?php echo esc_html( $file ? basename( $file ) : '' ); ?></code></td>
									<td>
										<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=agentsteamer_ai_gen_alt_one&id=' . $id ), 'agentsteamer_ai_gen_alt_one_' . $id ) ); ?>"><?php esc_html_e( '生成 Alt', 'agentsteamer-ai' ); ?></a>
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
