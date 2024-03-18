<?php

require __DIR__ . '/vendor/autoload.php';

use Mariadb\CatalogsPHP\Catalog;

class MariaDB_Catalog
{
    private static ?Catalog $api = null;

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
		if (!self::$api) {
			$dbhost     = (defined( 'CATALOG_DB_HOST' ) ? CATALOG_DB_HOST : defined( 'DB_HOST' ) ) ? DB_HOST : '';
			$dbuser     = (defined( 'CATALOG_DB_USER' ) ? CATALOG_DB_USER : defined( 'DB_USER' ) ) ? DB_USER : '';
			$dbpassword = (defined( 'CATALOG_DB_PASSWORD' ) ? CATALOG_DB_PASSWORD : defined( 'DB_PASSWORD' ) ) ? DB_PASSWORD : '';

	        self::$api = new Catalog($dbhost, 3306, $dbuser, $dbpassword, null);
	    }

		return self::$api;
	}

	public static function get_new_config() {
        $name = str_replace( '.', '', $_SERVER['HTTP_HOST'] );

        return [
            'dbname' => $name,
            'username' => $name,
            'password' => wp_generate_password(),
            'host' => (defined( 'CATALOG_DB_HOST' ) ? CATALOG_DB_HOST : defined( 'DB_HOST' ) ) ? DB_HOST : '127.0.0.1',
        ];
    }

	public function network_site_creation( $queries ) {

		return $queries;
	}
}

new MariaDB_Catalog();
