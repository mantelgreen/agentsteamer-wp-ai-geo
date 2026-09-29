<?php
/**
 * Per-post SEO metabox.
 *
 * @package AgentSteamer_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the SEO metabox to supported post types.
 */
class AgentSteamer_AI_Metabox {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Register the metabox.
	 */
	public function register() {
		foreach ( agentsteamer_ai_supported_post_types() as $type ) {
			// Block editor post types use the AgentSteamer sidebar instead.
			if ( function_exists( 'use_block_editor_for_post_type' ) && use_block_editor_for_post_type( $type ) ) {
				continue;
			}
			add_meta_box(
				'agentsteamer_ai_seo',
				__( 'AgentSteamer SEO / GEO', 'agentsteamer-ai' ),
				array( $this, 'render' ),
				$type,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Render the metabox.
	 *
	 * @param WP_Post $post Post.
	 */
	public function render( $post ) {
		wp_nonce_field( 'agentsteamer_ai_save', 'agentsteamer_ai_nonce' );

		$title       = agentsteamer_ai_get_post_meta( $post->ID, 'title' );
		$description = agentsteamer_ai_get_post_meta( $post->ID, 'description' );
		$canonical   = agentsteamer_ai_get_post_meta( $post->ID, 'canonical' );
		$keyword     = agentsteamer_ai_get_post_meta( $post->ID, 'focus_keyword' );
		$noindex     = agentsteamer_ai_get_post_meta( $post->ID, 'noindex', '0' );
		$faq         = agentsteamer_ai_get_post_meta( $post->ID, 'schema_faq' );
		$howto       = agentsteamer_ai_get_post_meta( $post->ID, 'schema_howto' );
		$howto_name  = agentsteamer_ai_get_post_meta( $post->ID, 'schema_howto_name' );
		$custom      = agentsteamer_ai_get_post_meta( $post->ID, 'schema_custom' );
		$speakable   = agentsteamer_ai_get_post_meta( $post->ID, 'speakable', '1' );
		$schema_off  = agentsteamer_ai_get_post_meta( $post->ID, 'schema_disabled', '0' );
		?>
		<div class="asi-metabox">
			<p>
				<label class="asi-label" for="asi_focus_keyword"><?php esc_html_e( '焦点关键词', 'agentsteamer-ai' ); ?></label>
				<input type="text" class="widefat" id="asi_focus_keyword" name="asi_focus_keyword" value="<?php echo esc_attr( $keyword ); ?>" />
			</p>
			<p>
				<label class="asi-label" for="asi_title"><?php esc_html_e( 'SEO 标题', 'agentsteamer-ai' ); ?></label>
				<input type="text" class="widefat" id="asi_title" name="asi_title" value="<?php echo esc_attr( $title ); ?>" placeholder="<?php echo esc_attr( wp_strip_all_tags( get_the_title( $post ) ) ); ?>" />
			</p>
			<p>
				<label class="asi-label" for="asi_description"><?php esc_html_e( 'Meta 描述', 'agentsteamer-ai' ); ?></label>
				<textarea class="widefat" id="asi_description" name="asi_description" rows="3" maxlength="320"><?php echo esc_textarea( $description ); ?></textarea>
			</p>
			<p>
				<label class="asi-label" for="asi_canonical"><?php esc_html_e( 'Canonical（留空自动）', 'agentsteamer-ai' ); ?></label>
				<input type="url" class="widefat" id="asi_canonical" name="asi_canonical" value="<?php echo esc_attr( $canonical ); ?>" placeholder="<?php echo esc_attr( get_permalink( $post ) ); ?>" />
			</p>
			<p>
				<label>
					<input type="checkbox" name="asi_noindex" value="1" <?php checked( $noindex, '1' ); ?> />
					<?php esc_html_e( '对该页面设置 noindex（不参与搜索引擎收录）', 'agentsteamer-ai' ); ?>
				</label>
			</p>
			<p>
				<button type="button" class="button button-secondary" id="asi-generate-meta" data-post="<?php echo esc_attr( $post->ID ); ?>">
					<?php esc_html_e( 'AI 生成标题 / 描述', 'agentsteamer-ai' ); ?>
				</button>
				<span class="asi-inline-status" id="asi-meta-status" aria-live="polite"></span>
			</p>

			<hr />
			<p class="asi-label"><?php esc_html_e( '结构化数据 (Schema)', 'agentsteamer-ai' ); ?></p>
			<p>
				<label><input type="checkbox" name="asi_schema_enabled" value="1" <?php checked( $schema_off, '0' ); ?> /> <?php esc_html_e( '输出 JSON-LD 结构化数据', 'agentsteamer-ai' ); ?></label>
				<label style="margin-left:16px;"><input type="checkbox" name="asi_speakable" value="1" <?php checked( $speakable, '1' ); ?> /> <?php esc_html_e( 'Speakable', 'agentsteamer-ai' ); ?></label>
			</p>
			<p class="asi-hint"><?php esc_html_e( 'FAQ / HowTo 每行一条，用「||」分隔：问题 || 答案；步骤 || 说明。', 'agentsteamer-ai' ); ?></p>
			<p>
				<label class="asi-label" for="asi_schema_faq"><?php esc_html_e( 'FAQ 问答', 'agentsteamer-ai' ); ?></label>
				<textarea class="widefat" id="asi_schema_faq" name="asi_schema_faq" rows="3"><?php echo esc_textarea( $faq ); ?></textarea>
			</p>
			<p>
				<label class="asi-label" for="asi_schema_howto_name"><?php esc_html_e( 'HowTo 标题', 'agentsteamer-ai' ); ?></label>
				<input type="text" class="widefat" id="asi_schema_howto_name" name="asi_schema_howto_name" value="<?php echo esc_attr( $howto_name ); ?>" />
			</p>
			<p>
				<label class="asi-label" for="asi_schema_howto"><?php esc_html_e( 'HowTo 步骤', 'agentsteamer-ai' ); ?></label>
				<textarea class="widefat" id="asi_schema_howto" name="asi_schema_howto" rows="3"><?php echo esc_textarea( $howto ); ?></textarea>
			</p>
			<p>
				<label class="asi-label" for="asi_schema_custom"><?php esc_html_e( '自定义 JSON-LD（高级）', 'agentsteamer-ai' ); ?></label>
				<textarea class="widefat" id="asi_schema_custom" name="asi_schema_custom" rows="3"><?php echo esc_textarea( $custom ); ?></textarea>
			</p>
			<p>
				<button type="button" class="button button-secondary" id="asi-extract-schema" data-post="<?php echo esc_attr( $post->ID ); ?>">
					<?php esc_html_e( 'AI 从正文提取 FAQ / HowTo', 'agentsteamer-ai' ); ?>
				</button>
				<span class="asi-inline-status" id="asi-schema-status" aria-live="polite"></span>
			</p>
			<p class="asi-hint"><?php esc_html_e( '提示：AI 生成结果会先填入表单，保存文章后生效。', 'agentsteamer-ai' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Persist metabox values.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public function save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['agentsteamer_ai_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['agentsteamer_ai_nonce'] ) ), 'agentsteamer_ai_save' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, agentsteamer_ai_supported_post_types(), true ) ) {
			return;
		}

		$fields = array(
			'title'             => 'sanitize_text_field',
			'description'       => 'sanitize_textarea_field',
			'canonical'         => 'esc_url_raw',
			'focus_keyword'     => 'sanitize_text_field',
			'schema_faq'        => 'sanitize_textarea_field',
			'schema_howto'      => 'sanitize_textarea_field',
			'schema_howto_name' => 'sanitize_text_field',
			'schema_custom'     => 'sanitize_textarea_field',
		);
		foreach ( $fields as $field => $sanitize ) {
			$key = agentsteamer_ai_meta_key( $field );
			if ( isset( $_POST[ 'asi_' . $field ] ) ) {
				$value = call_user_func( $sanitize, wp_unslash( $_POST[ 'asi_' . $field ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				if ( '' === $value ) {
					delete_post_meta( $post_id, $key );
				} else {
					update_post_meta( $post_id, $key, $value );
				}
			}
		}

		if ( isset( $_POST['asi_noindex'] ) && '1' === $_POST['asi_noindex'] ) {
			update_post_meta( $post_id, agentsteamer_ai_meta_key( 'noindex' ), '1' );
		} else {
			delete_post_meta( $post_id, agentsteamer_ai_meta_key( 'noindex' ) );
		}

		if ( isset( $_POST['asi_speakable'] ) && '1' === $_POST['asi_speakable'] ) {
			update_post_meta( $post_id, agentsteamer_ai_meta_key( 'speakable' ), '1' );
		} else {
			delete_post_meta( $post_id, agentsteamer_ai_meta_key( 'speakable' ) );
		}

		if ( ! isset( $_POST['asi_schema_enabled'] ) || '1' !== $_POST['asi_schema_enabled'] ) {
			update_post_meta( $post_id, agentsteamer_ai_meta_key( 'schema_disabled' ), '1' );
		} else {
			delete_post_meta( $post_id, agentsteamer_ai_meta_key( 'schema_disabled' ) );
		}
	}
}
