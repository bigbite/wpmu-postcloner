<?php

namespace WPMU_PostCloner;

/*
Plugin Name: WPMU Post Cloner
Plugin URI: https://github.com/jonmcpartland/WPMU-Postcloner/
Description: Clone posts across blogs in WPMU.
Author: Jon McPartland
Version: 0.1.0
Author URI: https://jon.mcpart.land
Textdomain: wpmu-postcloner
*/


require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/cloner.php';


new class {

	protected $siteList;

	public function __construct() {
		$this->siteList = \get_sites();

		\add_action( 'post_submitbox_start', function () {
			new Admin( $this->siteList );
		} );

		\add_action( 'post_updated', function ( $postID, \WP_Post $post ) {
			new Cloner( $postID, $post, $this->siteList );
		}, 10, 2 );
	}

};
