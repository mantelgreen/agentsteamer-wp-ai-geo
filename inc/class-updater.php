<?php
/**
 * GitHub release based auto-updates.
 *
 * The plugin is not hosted on WordPress.org and ships its releases on GitHub,
 * so it registers its own update source: WordPress shows a native "update
 * available" row and installs the new version in place (same folder).
 *
 * @package AgentSteamer_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Offers updates from GitHub releases and installs them in place.
 */
class AgentSteamer_AI_Updater {

	/**
	 * GitHub "owner/repo".
	 */
	const REPO = 'mantelgreen/agentsteamer-wp-ai-geo';

	/**
	 * Plugin basename (folder/main-file.php).
	 */
	const BASENAME = 'agentsteamer-wp-ai-geo/agentsteamer-wp-ai-geo.php';

	/**
	 * Plugin folder slug.
	 */
	const SLUG = 'agentsteamer-wp-ai-geo';

	/**
	 * Release cache key.
	 */
	const CACHE = 'agentsteamer_ai_gh_release';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
	}

	/**
	 * Fetch the latest release from GitHub (cached for 6 hours).
	 *
	 * @param bool $force Force a refresh.
	 * @return array|false
	 */
	protected function release( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE );
			if ( is_array( $cached ) ) {
				return $cached;
			}
			if ( 'none' === $cached ) {
				return false;
			}
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'AgentSteamer-AI/' . AGENTSTEAMER_AI_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::CACHE, 'none', 3 * HOUR_IN_SECONDS );
			return false;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) || empty( $data['zipball_url'] ) ) {
			set_transient( self::CACHE, 'none', 3 * HOUR_IN_SECONDS );
			return false;
		}

		$release = array(
			'version'      => ltrim( (string) $data['tag_name'], 'vV' ),
			'url'          => isset( $data['html_url'] ) ? (string) $data['html_url'] : '',
			'notes'        => isset( $data['body'] ) ? (string) $data['body'] : '',
			'published_at' => isset( $data['published_at'] ) ? (string) $data['published_at'] : '',
			'package'      => (string) $data['zipball_url'],
		);

		// Prefer a release asset that is already a ready-to-install plugin zip.
		if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
			foreach ( $data['assets'] as $asset ) {
				$name = isset( $asset['name'] ) ? strtolower( (string) $asset['name'] ) : '';
				if ( '.zip' === substr( $name, -4 ) && ! empty( $asset['browser_download_url'] ) ) {
					$release['package'] = (string) $asset['browser_download_url'];
					break;
				}
			}
		}

		set_transient( self::CACHE, $release, 6 * HOUR_IN_SECONDS );
		return $release;
	}

	/**
	 * Inject an available update into the plugins transient.
	 *
	 * @param object $transient Update transient.
	 * @return object
	 */
	public function check_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->release();
		if ( ! $release || empty( $release['package'] ) ) {
			return $transient;
		}

		if ( version_compare( $release['version'], AGENTSTEAMER_AI_VERSION, '>' ) ) {
			$obj              = new stdClass();
			$obj->slug        = self::SLUG;
			$obj->plugin      = self::BASENAME;
			$obj->new_version = $release['version'];
			$obj->url         = $release['url'];
			$obj->package     = $release['package'];
			$obj->icons       = array();
			$obj->banners     = array();
			$transient->response[ self::BASENAME ] = $obj;
		}

		return $transient;
	}

	/**
	 * Provide the "View version details" popup content.
	 *
	 * @param false|object|array $result Result.
	 * @param string             $action Action.
	 * @param object             $args   Args.
	 * @return false|object|array
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$release = $this->release();
		if ( ! $release ) {
			return $result;
		}

		$obj                = new stdClass();
		$obj->name          = 'AgentSteamer AI SEO & GEO';
		$obj->slug          = self::SLUG;
		$obj->version       = $release['version'];
		$obj->last_updated  = $release['published_at'];
		$obj->download_link = $release['package'];
		$obj->sections      = array(
			'description' => __( '来自 GitHub Releases 的更新。', 'agentsteamer-ai' ),
			'changelog'   => '<pre style="white-space:pre-wrap">' . esc_html( $release['notes'] ) . '</pre>',
		);

		return $obj;
	}

	/**
	 * Rename the GitHub source folder to the plugin slug so it installs in place.
	 *
	 * @param string      $source        Source path.
	 * @param string      $remote_source Remote source path.
	 * @param WP_Upgrader $upgrader      Upgrader.
	 * @param array       $hook_extra    Extra args.
	 * @return string
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		if ( empty( $hook_extra['plugin'] ) || self::BASENAME !== $hook_extra['plugin'] ) {
			return $source;
		}
		if ( ! $wp_filesystem ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . self::SLUG;
		if ( $source === trailingslashit( $desired ) || $wp_filesystem->exists( $desired ) ) {
			return $source;
		}
		if ( $wp_filesystem->move( $source, $desired ) ) {
			return trailingslashit( $desired );
		}

		return $source;
	}
}
