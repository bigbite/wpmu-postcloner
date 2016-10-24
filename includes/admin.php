<?php

namespace WPMU_PostCloner;

class Admin {

	protected $siteList;
	protected $current;

	public function __construct( $siteList ) {
		$this->siteList = $siteList;
		$this->current  = \get_current_blog_id();

		$this->render();
	}

	/**
	 * Render the post submit box clone functionality
	 *
	 * @return void
	 */
	protected function render() {
		$sites = $this->generate_sitelist();

		require_once realpath( __DIR__ . '/../templates/submitbox.php' );
	}

	/**
	 * Format the list of available blogs for rendering
	 *
	 * @return array
	 */
	protected function generate_sitelist() {
		$sites = [];
		foreach ( $this->siteList as $site ) {
			if ( intval( $site->blog_id, 10 ) === intval( $this->current, 10 ) ) {
				continue;
			}

			$sites[ $site->blogname ] = $site;
		}

		ksort( $sites );

		return array_values( $sites );
	}

}
