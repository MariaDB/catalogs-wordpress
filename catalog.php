<?php

include 'api.php';

class MariaDB_Catalog
{
	public function __construct() {
		add_action( 'init', [ $this, 'hijack' ] );
		add_filter( 'dbdelta_queries', [ $this, 'network_site_creation' ] );
	}

	public function hijack() {
		if ( str_contains( $_SERVER['REQUEST_URI'], 'wp-admin/setup-config.php' ) ) {
			$path = wp_guess_url() . '/wp-content/mu-plugins/catalog/setup-config.php';
			header( 'Location: ' . $path );
			exit;
		}
	}

	public static function get_api() {
		return new MariaDB_Catalog_API('127.0.0.1', 3306, 'root', 'password123');
	}

	public function network_site_creation( $queries ) {

		return $queries;
	}
}

new MariaDB_Catalog();
