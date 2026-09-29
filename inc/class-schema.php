<?php
/**
 * JSON-LD structured data.
 *
 * @package AgentSteamer_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and prints a schema.org @graph.
 */
class AgentSteamer_AI_Schema {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_head', array( $this, 'output' ), 5 );
	}

	/**
	 * Build the graph for the current request.
	 *
	 * @return array
	 */
	public function build_graph() {
		$settings = agentsteamer_ai_get_settings();
		$home     = home_url( '/' );
		$org_id   = $home . '#organization';
		$site_id  = $home . '#website';
		$org_name = $settings['org_name'] ? $settings['org_name'] : get_bloginfo( 'name' );

		$organization = array(
			'@type' => $settings['org_type'] ? $settings['org_type'] : 'Organization',
			'@id'   => $org_id,
			'name'  => $org_name,
			'url'   => $home,
		);
		if ( $settings['org_logo'] ) {
			$organization['logo'] = array(
				'@type' => 'ImageObject',
				'url'   => $settings['org_logo'],
			);
		}
		$same_as = $this->parse_lines( $settings['social_profiles'] );
		if ( $same_as ) {
			$organization['sameAs'] = $same_as;
		}

		$website = array(
			'@type'     => 'WebSite',
			'@id'       => $site_id,
			'url'       => $home,
			'name'      => $org_name,
			'publisher' => array( '@id' => $org_id ),
			'inLanguage' => get_bloginfo( 'language' ),
			'potentialAction' => array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'       => 'EntryPoint',
					'urlTemplate' => home_url( '/?s={search_term_string}' ),
				),
				'query-input' => 'required name=search_term_string',
			),
		);

		$graph = array( $organization, $website );

		if ( is_singular() ) {
			$post_id  = get_queried_object_id();
			$post     = get_post( $post_id );
			$thumb    = get_the_post_thumbnail_url( $post_id, 'full' );
			$disabled = ( '1' === agentsteamer_ai_get_post_meta( $post_id, 'schema_disabled', '0' ) );

			$article = array(
				'@type'            => 'BlogPosting',
				'@id'              => get_permalink( $post_id ) . '#article',
				'isPartOf'         => array( '@id' => $site_id ),
				'mainEntityOfPage' => array( '@id' => get_permalink( $post_id ) ),
				'headline'         => wp_strip_all_tags( $post->post_title ),
				'datePublished'    => get_the_date( 'c', $post_id ),
				'dateModified'     => get_the_modified_date( 'c', $post_id ),
				'inLanguage'       => get_bloginfo( 'language' ),
				'publisher'        => array( '@id' => $org_id ),
			);

			$desc = agentsteamer_ai_get_post_meta( $post_id, 'description' );
			if ( $desc ) {
				$article['description'] = $desc;
			}
			if ( ! $thumb && ! empty( $settings['org_logo'] ) ) {
				$thumb = $settings['org_logo'];
			}
			if ( $thumb ) {
				$article['image'] = $thumb;
			}

			$plain = agentsteamer_ai_plain_content( $post_id );
			if ( '' !== $plain ) {
				$article['wordCount'] = function_exists( 'mb_strlen' ) ? mb_strlen( $plain ) : strlen( $plain );
			}

			$cats = get_the_category( $post_id );
			if ( ! empty( $cats ) ) {
				$article['articleSection'] = $cats[0]->name;
			}

			$keywords = array();
			$focus    = agentsteamer_ai_get_post_meta( $post_id, 'focus_keyword' );
			if ( '' !== $focus ) {
				$keywords[] = $focus;
			}
			$tags = wp_get_post_tags( $post_id, array( 'fields' => 'names' ) );
			if ( ! empty( $tags ) ) {
				$keywords = array_merge( $keywords, $tags );
			}
			if ( ! empty( $keywords ) ) {
				$article['keywords'] = implode( ', ', array_unique( $keywords ) );
			}

			if ( ! $disabled ) {
				if ( '1' === agentsteamer_ai_get_post_meta( $post_id, 'speakable', '1' ) ) {
					$article['speakable'] = array(
						'@type'       => 'SpeakableSpecification',
						'cssSelector' => array( 'h1', '.entry-content p:first-of-type', 'article p:first-of-type' ),
					);
				}

				$author_id   = (int) $post->post_author;
				$author_idlg = get_author_posts_url( $author_id ) . '#person';
				$author      = array(
					'@type' => 'Person',
					'@id'   => $author_idlg,
					'name'  => get_the_author_meta( 'display_name', $author_id ),
					'url'   => get_author_posts_url( $author_id ),
				);
				$knows = $this->parse_lines( $settings['author_knows_about'] );
				if ( $knows ) {
					$author['knowsAbout'] = $knows;
				}
				$article['author'] = array( '@id' => $author_idlg );

				$graph[] = $author;
				$graph[] = $article;

				$graph[] = $this->breadcrumb( $post_id );

				// Term-aware page reference for non-post types.
				$graph[] = array(
					'@type'    => 'WebPage',
					'@id'      => get_permalink( $post_id ),
					'url'      => get_permalink( $post_id ),
					'name'     => wp_strip_all_tags( $post->post_title ),
					'isPartOf' => array( '@id' => $site_id ),
				);

				$graph = array_merge( $graph, $this->faq_nodes( $post_id ), $this->howto_nodes( $post_id ), $this->custom_nodes( $post_id ) );
			}
		} elseif ( is_home() || is_front_page() ) {
			$graph[] = array(
				'@type'    => 'WebPage',
				'@id'      => $home,
				'url'      => $home,
				'name'     => wp_get_document_title(),
				'isPartOf' => array( '@id' => $site_id ),
			);
		}

		$graph = array_merge( $graph, $this->global_nodes() );

		/**
		 * Filter the schema graph.
		 *
		 * @param array $graph Graph nodes.
		 */
		return apply_filters( 'agentsteamer_ai_schema_graph', $graph );
	}

	/**
	 * Nodes from the global JSON-LD template.
	 *
	 * @return array
	 */
	protected function global_nodes() {
		$raw = agentsteamer_ai_get_option( 'schema_global_jsonld', '' );
		if ( '' === trim( (string) $raw ) ) {
			return array();
		}
		$decoded = json_decode( (string) $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		if ( isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) ) {
			return $decoded['@graph'];
		}
		if ( isset( $decoded['@type'] ) ) {
			return array( $decoded );
		}
		return array();
	}

	/**
	 * Breadcrumb node for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	protected function breadcrumb( $post_id ) {
		$items    = array();
		$position = 1;

		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $position++,
			'name'     => __( '首页', 'agentsteamer-ai' ),
			'item'     => home_url( '/' ),
		);

		$post     = get_post( $post_id );
		$taxonomy = ( 'post' === $post->post_type ) ? 'category' : '';
		if ( $taxonomy ) {
			$terms = get_the_terms( $post_id, $taxonomy );
			if ( $terms && ! is_wp_error( $terms ) ) {
				$term = $terms[0];
				$items[] = array(
					'@type'    => 'ListItem',
					'position' => $position++,
					'name'     => $term->name,
					'item'     => get_term_link( $term ),
				);
			}
		}

		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $position++,
			'name'     => wp_strip_all_tags( $post->post_title ),
			'item'     => get_permalink( $post_id ),
		);

		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => get_permalink( $post_id ) . '#breadcrumb',
			'itemListElement' => $items,
		);
	}

	/**
	 * Split a textarea into a list of URLs/values.
	 *
	 * @param string $text Text.
	 * @return array
	 */
	protected function parse_lines( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return array();
		}
		$lines = preg_split( '/[\r\n]+/', $text );
		$lines = array_map( 'trim', $lines );
		$lines = array_filter( $lines );
		return array_values( $lines );
	}

	/**
	 * Build FAQPage nodes from the stored FAQ lines.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	protected function faq_nodes( $post_id ) {
		$rows = agentsteamer_ai_parse_pairs( agentsteamer_ai_get_post_meta( $post_id, 'schema_faq' ) );
		if ( empty( $rows ) ) {
			return array();
		}
		$entities = array();
		foreach ( $rows as $row ) {
			$entities[] = array(
				'@type'          => 'Question',
				'name'           => $row[0],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $row[1],
				),
			);
		}
		return array(
			array(
				'@type'      => 'FAQPage',
				'@id'        => get_permalink( $post_id ) . '#faq',
				'mainEntity' => $entities,
			),
		);
	}

	/**
	 * Build HowTo nodes from the stored step lines.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	protected function howto_nodes( $post_id ) {
		$rows = agentsteamer_ai_parse_pairs( agentsteamer_ai_get_post_meta( $post_id, 'schema_howto' ) );
		if ( empty( $rows ) ) {
			return array();
		}
		$name = agentsteamer_ai_get_post_meta( $post_id, 'schema_howto_name' );
		if ( '' === $name ) {
			$name = wp_strip_all_tags( get_the_title( $post_id ) );
		}
		$steps = array();
		$pos   = 1;
		foreach ( $rows as $row ) {
			$steps[] = array(
				'@type'    => 'HowToStep',
				'position' => $pos++,
				'name'     => $row[0],
				'text'     => $row[1],
			);
		}
		return array(
			array(
				'@type' => 'HowTo',
				'@id'   => get_permalink( $post_id ) . '#howto',
				'name'  => $name,
				'step'  => $steps,
			),
		);
	}

	/**
	 * Build nodes from a raw custom JSON-LD blob.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	protected function custom_nodes( $post_id ) {
		$raw = agentsteamer_ai_get_post_meta( $post_id, 'schema_custom' );
		if ( '' === trim( (string) $raw ) ) {
			return array();
		}
		$decoded = json_decode( (string) $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		if ( isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) ) {
			return $decoded['@graph'];
		}
		if ( isset( $decoded['@type'] ) ) {
			return array( $decoded );
		}
		return array();
	}

	/**
	 * Print the JSON-LD script tag.
	 */
	public function output() {
		if ( ! agentsteamer_ai_is_enabled() ) {
			return;
		}
		$graph = $this->build_graph();
		if ( empty( $graph ) ) {
			return;
		}
		$data = array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		);
		echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
