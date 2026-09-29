<?php
/**
 * Front-end SEO meta output.
 *
 * @package AgentSteamer_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Outputs title, description, canonical, robots and social meta tags.
 */
class AgentSteamer_AI_Meta_Tags {

	/**
	 * Resolved context cache.
	 *
	 * @var array|null
	 */
	protected $context = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'document_title_parts', array( $this, 'document_title_parts' ), 20 );
		add_filter( 'document_title_separator', array( $this, 'document_title_separator' ), 20 );
		add_action( 'wp_head', array( $this, 'output' ), 1 );
		remove_action( 'wp_head', 'rel_canonical' );
	}

	/**
	 * Resolve the SEO context for the current request.
	 *
	 * @return array
	 */
	protected function context() {
		if ( null !== $this->context ) {
			return $this->context;
		}

		$ctx = array(
			'type'        => 'default',
			'post_id'     => 0,
			'title'       => '',
			'description' => '',
			'canonical'   => '',
			'image'       => '',
		);

		if ( ! agentsteamer_ai_is_enabled() ) {
			$this->context = $ctx;
			return $this->context;
		}

		$settings = agentsteamer_ai_get_settings();

		if ( is_singular() ) {
			$post_id = get_queried_object_id();
			$ctx['type']    = 'singular';
			$ctx['post_id'] = $post_id;
			$ctx['title']   = agentsteamer_ai_get_post_meta( $post_id, 'title' );
			$custom_canonical = agentsteamer_ai_get_post_meta( $post_id, 'canonical' );

			$desc = agentsteamer_ai_get_post_meta( $post_id, 'description' );
			if ( ! $desc ) {
				$post = get_post( $post_id );
				if ( $post && $post->post_excerpt ) {
					$desc = $post->post_excerpt;
				} elseif ( $settings['auto_meta'] ) {
					$desc = wp_trim_words( agentsteamer_ai_plain_content( $post_id ), 30, '…' );
				}
			}
			$ctx['description'] = agentsteamer_ai_trim( $desc, 160 );
			$ctx['canonical']   = $custom_canonical ? $custom_canonical : get_permalink( $post_id );

			$thumb = get_the_post_thumbnail_url( $post_id, 'full' );
			if ( $thumb ) {
				$ctx['image'] = $thumb;
			}
		} elseif ( is_home() || is_front_page() ) {
			$ctx['type']        = 'home';
			$ctx['title']       = $settings['home_title'];
			$ctx['description'] = agentsteamer_ai_trim( $settings['home_desc'] ? $settings['home_desc'] : ( $settings['default_desc'] ? $settings['default_desc'] : get_bloginfo( 'description' ) ), 160 );
			$ctx['canonical']   = home_url( '/' );
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			$ctx['type']        = 'term';
			$ctx['title']       = $term && $term->name ? $term->name : '';
			$ctx['description'] = $term && $term->description ? agentsteamer_ai_trim( $term->description, 160 ) : '';
			$term_link          = $term ? get_term_link( $term ) : '';
			if ( $term_link && ! is_wp_error( $term_link ) ) {
				$ctx['canonical'] = $term_link;
			}
		} elseif ( is_author() ) {
			$user               = get_queried_object();
			$ctx['type']        = 'author';
			$ctx['title']       = $user && isset( $user->display_name ) ? $user->display_name : '';
			$ctx['description'] = $user && isset( $user->description ) ? agentsteamer_ai_trim( $user->description, 160 ) : '';
			$link               = get_author_posts_url( $user->ID );
			$ctx['canonical']   = $link;
		}

		if ( empty( $ctx['image'] ) && ! empty( $settings['org_logo'] ) ) {
			$ctx['image'] = $settings['org_logo'];
		}

		$this->context = apply_filters( 'agentsteamer_ai_context', $ctx );
		return $this->context;
	}

	/**
	 * Override document title parts.
	 *
	 * @param array $parts Title parts.
	 * @return array
	 */
	public function document_title_parts( $parts ) {
		if ( ! agentsteamer_ai_is_enabled() ) {
			return $parts;
		}
		$ctx = $this->context();
		if ( ! empty( $ctx['title'] ) ) {
			$parts['title'] = wp_strip_all_tags( $ctx['title'] );
		}
		return $parts;
	}

	/**
	 * Title separator.
	 *
	 * @param string $sep Separator.
	 * @return string
	 */
	public function document_title_separator( $sep ) {
		$custom = agentsteamer_ai_get_option( 'title_separator', '' );
		return $custom ? $custom : $sep;
	}

	/**
	 * Output meta tags.
	 */
	public function output() {
		if ( ! agentsteamer_ai_is_enabled() ) {
			return;
		}
		$ctx = $this->context();

		if ( ! empty( $ctx['description'] ) ) {
			echo '<meta name="description" content="' . esc_attr( $ctx['description'] ) . '" />' . "\n";
		}

		$robots = $this->robots_for( $ctx );
		if ( $robots ) {
			echo '<meta name="robots" content="' . esc_attr( $robots ) . '" />' . "\n";
		}

		if ( ! empty( $ctx['canonical'] ) ) {
			echo '<link rel="canonical" href="' . esc_url( $ctx['canonical'] ) . '" />' . "\n";
		}

		// Open Graph.
		$og_type = ( 'singular' === $ctx['type'] ) ? 'article' : 'website';
		$title   = $this->resolved_title( $ctx );
		echo '<meta property="og:type" content="' . esc_attr( $og_type ) . '" />' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( $title ) . '" />' . "\n";
		if ( ! empty( $ctx['description'] ) ) {
			echo '<meta property="og:description" content="' . esc_attr( $ctx['description'] ) . '" />' . "\n";
		}
		if ( ! empty( $ctx['canonical'] ) ) {
			echo '<meta property="og:url" content="' . esc_url( $ctx['canonical'] ) . '" />' . "\n";
		}
		echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '" />' . "\n";
		if ( ! empty( $ctx['image'] ) ) {
			echo '<meta property="og:image" content="' . esc_url( $ctx['image'] ) . '" />' . "\n";
		}
		if ( 'singular' === $ctx['type'] ) {
			echo '<meta property="article:published_time" content="' . esc_attr( get_the_date( 'c', $ctx['post_id'] ) ) . '" />' . "\n";
			echo '<meta property="article:modified_time" content="' . esc_attr( get_the_modified_date( 'c', $ctx['post_id'] ) ) . '" />' . "\n";
		}

		// Twitter.
		echo '<meta name="twitter:card" content="' . esc_attr( $ctx['image'] ? 'summary_large_image' : 'summary' ) . '" />' . "\n";
		echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '" />' . "\n";
		if ( ! empty( $ctx['description'] ) ) {
			echo '<meta name="twitter:description" content="' . esc_attr( $ctx['description'] ) . '" />' . "\n";
		}
		if ( ! empty( $ctx['image'] ) ) {
			echo '<meta name="twitter:image" content="' . esc_url( $ctx['image'] ) . '" />' . "\n";
		}
	}

	/**
	 * Resolve the display title for the current request.
	 *
	 * @param array $ctx Context.
	 * @return string
	 */
	protected function resolved_title( $ctx ) {
		if ( ! empty( $ctx['title'] ) ) {
			return $ctx['title'];
		}
		return wp_get_document_title();
	}

	/**
	 * Compute the robots directive.
	 *
	 * @param array $ctx Context.
	 * @return string
	 */
	protected function robots_for( $ctx ) {
		$settings = agentsteamer_ai_get_settings();
		$noindex  = false;
		$nofollow = false;

		if ( 'singular' === $ctx['type'] && '1' === agentsteamer_ai_get_post_meta( $ctx['post_id'], 'noindex', '0' ) ) {
			$noindex = true;
		}
		if ( is_search() && $settings['noindex_search'] ) {
			$noindex = true;
		}
		if ( is_author() && $settings['noindex_author'] ) {
			$noindex = true;
		}
		if ( is_date() && $settings['noindex_date'] ) {
			$noindex = true;
		}

		$dirs = array();
		$dirs[] = $noindex ? 'noindex' : 'index';
		$dirs[] = $nofollow ? 'nofollow' : 'follow';

		return implode( ',', $dirs );
	}
}
