<?php

namespace WPMU_PostCloner;

use WP_Post;

class Cloner {

	protected $wpdb;
	protected $post;

	protected $originalID;
	protected $originalPost;

	protected $clonedID;
	protected $clonedPost;

	protected $targetID;
	protected $targetPost;
	protected $targetMeta;

	protected $allBlogs;
	protected $sourceBlog;
	protected $targetBlog;


	public function __construct( $postID, WP_Post $post, $siteList ) {
		if ( ! isset( $_POST['clone'], $_POST['sitelist'] ) ) {
			return;
		}

		// add superglobals
		$this->wpdb = $GLOBALS['wpdb'];
		$this->post = $_POST;

		// add "local" parameters
		$this->originalID   = $postID;
		$this->originalPost = $post;
		$this->originalMeta = [];

		// add "global" parameters
		$this->allBlogs   = $siteList;
		$this->sourceBlog = \get_blog_details();
		$this->targetBlog = $this->retrieve_target();

		// run
		$this->instantiate();
	}

	/**
	 * Set destination blog info
	 *
	 * @return \WP_Site
	 */
	protected function retrieve_target() {
		$target = false;

		foreach ( $this->allBlogs as $site ) {
			if ( $site->blog_id !== $this->post['sitelist'] ) {
				continue;
			}

			$target = $site;
		}

		return $target;
	}

	/**
	 * Execute clone
	 *
	 * @return void
	 */
	protected function instantiate() {
		$this->organise_postmeta();
		$this->organise_taxonomy();

		\switch_to_blog( intval( $this->targetBlog->blog_id, 10 ) );

		// possibly need to check user here
		$this->migrate();

		\restore_current_blog();

		$this->notify_admin();
	}

	/**
	 * Retrieve and organise original postmeta
	 *
	 * @return void
	 */
	protected function organise_postmeta() {
		$postmeta  = \get_post_meta( $this->originalPost->ID );
		$formatted = [];

		foreach ( $postmeta as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = $value[0];
			}

			$formatted[ $key ] = $value;
		}

		$formatted = \apply_filters( 'wpmu_postcloner_origin_postmeta', $formatted, $this->originalPost );

		$this->originalMeta['postmeta'] = $formatted;
	}

	/**
	 * Retrieve and organise original taxonomy data
	 *
	 * @return void
	 */
	protected function organise_taxonomy() {
		$taxonomies = \get_object_taxonomies( $this->originalPost->post_type );
		$taxonomies = \apply_filters( 'wpmu_postcloner_origin_taxonomies', $taxonomies );
		$formatted  = [];

		foreach ( $taxonomies as $tax ) {
			if ( ! $terms = \wp_get_object_terms( $this->originalPost->ID, $tax ) ) {
				continue;
			}

			$formatted[ $tax ] = \apply_filters( 'wpmu_postcloner_origin_terms', $terms, $tax );
		}

		$this->originalMeta['taxonomy'] = $formatted;
	}

	/**
	 * Migrate the post and its related data
	 *
	 * @return void
	 */
	protected function migrate() {
		$this->migrate_the_post();
		$this->migrate_postmeta();

		foreach ( $this->originalMeta['taxonomy'] as $tax => $terms ) {
			$this->migrate_taxonomy( $tax, $terms );
		}
	}

	/**
	 * Actually migrate the post
	 *
	 * @return void
	 */
	protected function migrate_the_post() {
		$postdata = (clone $this->originalPost)->to_array();
		unset( $postdata['ID'] );

		$postdata = \apply_filters( 'wpmu_postcloner_target_postdata', $postdata, $this->sourceBlog );

		$this->targetID = \wp_insert_post( $postdata );
	}

	/**
	 * Actually migrate the postmeta
	 *
	 * @return void
	 */
	protected function migrate_postmeta() {
		$metadata = \apply_filters(
			'wpmu_postcloner_target_postmeta', $this->originalMeta['postmeta'],

			$this->targetID, $this->targetBlog
		);

		foreach ( $metadata as $key => $value ) {
			\update_post_meta( $this->targetID, $key, $value );
		}
	}

	/**
	 * Actually migrate a taxonomy
	 *
	 * @param  string  $taxonomy  the taxonomy name to migrate
	 * @param  array   $terms     the taxonomy terms to migrate
	 *
	 * @return void
	 */
	protected function migrate_taxonomy( $taxonomy, $terms ) {
		if ( ! \taxonomy_exists( $taxonomy ) ) {
			return;
		}

		foreach ( $terms as $term ) {
			$this->migrate_term( $term, $taxonomy );
		}
	}

	/**
	 * Actually migrate a taxonomy term
	 *
	 * @param  object  $term     the term to migrate
	 * @param  string  $taxonomy the taxonomy to which the term belongs
	 *
	 * @return mixed
	 */
	protected function migrate_term( $term, $taxonomy ) {
		$newTerm = \term_exists( $term->name, $taxonomy, $term->parent );

		if ( ! $newTerm ) {
			$newTerm = $this->create_term( $term, $taxonomy );
		}

		if ( \is_wp_error( $newTerm ) ) {
			return;
		}

		return \wp_set_object_terms( $this->targetID, intval( $newTerm, 10 ), $taxonomy, true );
	}

	/**
	 * Create a taxonomy term if it doesn't exist on the destination blog
	 *
	 * @param  object  $term      the term to migrate
	 * @param  string  $taxonomy  the taxonomy to which the term belongs
	 *
	 * @return mixed
	 */
	protected function create_term( $term, $taxonomy ) {
		return \wp_insert_term( $term->name, $taxonomy, [
			'description' => $term->description,
			'parent'      => $term->parent,
			'slug'        => $term->slug,
		] );
	}

	/**
	 * Send an admin notification that the post was migrated
	 *
	 * @return void
	 */
	protected function notify_admin() {
		if ( ! function_exists( 'create_admin_notice' ) ) {
			return;
		}

		$url = "//{$this->targetBlog->domain}/wp-admin/post.php?action=edit&post={$this->targetID}";

		$message = vsprintf( 'Post cloned to %s. View %s.', [
			"<strong>{$this->targetBlog->blogname}</strong>",
			"<a href=\"{$url}\" target=\"_blank\">here</a>",
		] );

		create_admin_notice( [
			'level'   => 'success',
			'message' => $message,
			'display' => [
				'pagenow' => 'post.php',
				'post.ID' => $this->originalID,
			],
		] );
	}

}
