<?php

/*
Plugin Name: WPMU Post Cloner
Plugin URI: https://github.com/jonmcpartland/WPMU-Postcloner/
Description: Clone posts across blogs in WPMU.
Author: Jon McPartland
Version: 0.1.0
Author URI: https://jon.mcpart.land
Textdomain: wpmu-postcloner
*/

new class {

	protected $siteList = null;

	protected $original = null;

	public function __construct() {
		$this->siteList = \get_sites();
		$this->original = \get_blog_details();

		\add_action( 'post_submitbox_start', [ $this, 'ui' ] );
		\add_action( 'post_updated', [ $this, 'clone' ], 10, 2 );
	}

	public function ui() {
		$sites = $this->generate_sitelist();

		require_once __DIR__ . '/ui.php';
	}

	public function clone( $postID, \WP_Post $post ) {
		if ( ! isset( $_POST['clone'], $_POST['sitelist'] ) ) {
			return;
		}

		$cloneTo    = $_POST['sitelist'];
		$siteExists = false;
		foreach ( $this->siteList as $site ) {
			if ( $site->blog_id !== $cloneTo ) {
				continue;
			}

			$cloneTo    = $site;
			$siteExists = true;
		}

		if ( ! $siteExists ) {
			return;
		}

		$this->initiate_clone( $post, $cloneTo );
	}

	protected function initiate_clone( $oldPost, $cloneTo ) {
		$data = $this->gather_data( $oldPost );

		\switch_to_blog( intval( $cloneTo->blog_id, 10 ) );

		// possibly need to check user here

		$newPostID = $this->migrate( $oldPost, $data );

		\restore_current_blog();

		// check whether newPostID is an error

		$this->send_notice( $cloneTo->domain, $cloneTo->blogname, $newPostID, $oldPost->ID );
	}

	protected function gather_data( $post ) {
		$metadata   = \get_post_meta( $post->ID );
		$taxonomies = \get_object_taxonomies( $post->post_type );
		$postTerms  = [];

		foreach ( $taxonomies as $tax ) {
			if ( ! $terms = \wp_get_object_terms( $post->ID, $tax ) ) {
				continue;
			}

			$postTerms[ $tax ] = $terms;
		}

		return [
			'metadata'   => $metadata,
			'taxonomies' => $taxonomies,
			'terms'      => $postTerms,
		];
	}

	protected function migrate( $post, $data ) {
		$cloned = (clone $post)->to_array();
		unset( $cloned['ID'] );

		$cloneTitle = "[Cloned from {$this->original->blogname}] {$cloned['post_title']}";
		$newID = \wp_insert_post( array_merge( $cloned, [
			'post_title'  => $cloneTitle,
			'post_status' => 'draft',
		] ) );

		$disallowCopy = [ '_edit_lock', '_edit_last', '_pingme', '_encloseme' ];
		foreach ( $data['metadata'] as $key => $value ) {
			if ( in_array( $key, $disallowCopy ) ) {
				continue;
			}

			\update_post_meta( $newID, $key, $value );
		}

		foreach ( $data['terms'] as $tax => $terms ) {
			$this->migrate_taxonomy( $tax, $terms );
		}

		return $newID;
	}

	protected function migrate_taxonomy( $taxonomy, $terms ) {
		if ( ! \taxonomy_exists( $taxonomy ) ) {
			return;
		}

		foreach ( $terms as $term ) {
			$this->migrate_term( $term, $taxonomy );
		}
	}

	protected function migrate_term( $term, $taxonomy ) {
		$newTerm = \term_exists( $term->name, $taxonomy, $term->parent );

		if ( ! $newTerm ) {
			$newTerm = $this->create_term( $term, $taxonomy );
		}

		if ( \is_wp_error( $newTerm ) ) {
			return;
		}

		return \wp_set_object_terms( $newID, intval( $newTerm, 10 ), $taxonomy, true );
	}

	protected function create_term( $term, $taxonomy ) {
		return \wp_insert_term( $term->name, $taxonomy, [
			'description' => $term->description,
			'parent'      => $term->parent,
			'slug'        => $term->slug,
		] );
	}

	protected function send_notice( $domain, $name, $postID, $origID ) {
		if ( ! function_exists( 'create_admin_notice' ) ) {
			return;
		}

		$url = "//{$domain}/wp-admin/post.php?action=edit&post={$postID}";

		$message = vsprintf( 'Post cloned to %s. View %s.', [
			"<strong>{$name}</strong>",
			"<a href=\"{$url}\" target=\"_blank\">here</a>",
		] );

		create_admin_notice( [
			'level'   => 'success',
			'message' => $message,
			'display' => [
				'pagenow' => 'post.php',
				'post.ID' => $origID,
			],
		] );
	}

	protected function generate_sitelist() {
		$sites = [];
		foreach ( $this->siteList as $site ) {
			if ( intval( $site->blog_id, 10 ) === intval( $this->original->blog_id, 10 ) ) {
				continue;
			}

			$sites[ $site->blogname ] = $site;
		}

		ksort( $sites );

		return array_values( $sites );
	}

};
