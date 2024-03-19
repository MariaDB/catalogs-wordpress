<?php

class MariaDB_DB extends wpdb {
	private $current_catalog = 'def';
	private $def_connection = null;
	private $catalog_connection = null;

	/**
	 * Connects to and selects database.
	 *
	 * If `$allow_bail` is false, the lack of database connection will need to be handled manually.
	 *
	 * @since 3.0.0
	 * @since 3.9.0 $allow_bail parameter added.
	 *
	 * @param bool $allow_bail Optional. Allows the function to bail. Default true.
	 * @return bool True with a successful connection, false on failure.
	 */
	public function db_connect( $allow_bail = true ) {
		$this->is_mysql = true;

		$client_flags = defined( 'MYSQL_CLIENT_FLAGS' ) ? MYSQL_CLIENT_FLAGS : 0;

		/*
		 * Set the MySQLi error reporting off because WordPress handles its own.
		 * This is due to the default value change from `MYSQLI_REPORT_OFF`
		 * to `MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT` in PHP 8.1.
		 */
		mysqli_report( MYSQLI_REPORT_OFF );

		$this->dbh = mysqli_init();

		if ($this->get_connection_type() == 'double') {
			$this->def_connection = &$this->dbh;
		}

		$host    = $this->dbhost;
		$port    = null;
		$socket  = null;
		$is_ipv6 = false;

		$host_data = $this->parse_db_host( $this->dbhost );
		if ( $host_data ) {
			list( $host, $port, $socket, $is_ipv6 ) = $host_data;
		}

		/*
		 * If using the `mysqlnd` library, the IPv6 address needs to be enclosed
		 * in square brackets, whereas it doesn't while using the `libmysqlclient` library.
		 * @see https://bugs.php.net/bug.php?id=67563
		 */
		if ( $is_ipv6 && extension_loaded( 'mysqlnd' ) ) {
			$host = "[$host]";
		}

		if ( WP_DEBUG ) {
			mysqli_real_connect( $this->dbh, $host, $this->dbuser, $this->dbpassword, $this->dbname, $port, $socket, $client_flags );
		} else {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@mysqli_real_connect( $this->dbh, $host, $this->dbuser, $this->dbpassword, $this->dbname, $port, $socket, $client_flags );
		}

		if ( $this->dbh->connect_errno ) {
			$this->dbh = null;
		}

		if ( ! $this->dbh && $allow_bail ) {
			wp_load_translations_early();

			// Load custom DB error template, if present.
			if ( file_exists( WP_CONTENT_DIR . '/db-error.php' ) ) {
				require_once WP_CONTENT_DIR . '/db-error.php';
				die();
			}

			$message = '<h1>' . __( 'Error establishing a database connection' ) . "</h1>\n";

			$message .= '<p>' . sprintf(
				/* translators: 1: wp-config.php, 2: Database host. */
				__( 'This either means that the username and password information in your %1$s file is incorrect or that contact with the database server at %2$s could not be established. This could mean your host&#8217;s database server is down.' ),
				'<code>wp-config.php</code>',
				'<code>' . htmlspecialchars( $this->dbhost, ENT_QUOTES ) . '</code>'
			) . "</p>\n";

			$message .= "<ul>\n";
			$message .= '<li>' . __( 'Are you sure you have the correct username and password?' ) . "</li>\n";
			$message .= '<li>' . __( 'Are you sure you have typed the correct hostname?' ) . "</li>\n";
			$message .= '<li>' . __( 'Are you sure the database server is running?' ) . "</li>\n";
			$message .= "</ul>\n";

			$message .= '<p>' . sprintf(
				/* translators: %s: Support forums URL. */
				__( 'If you are unsure what these terms mean you should probably contact your host. If you still need help you can always visit the <a href="%s">WordPress support forums</a>.' ),
				__( 'https://wordpress.org/support/forums/' )
			) . "</p>\n";

			$this->bail( $message, 'db_connect_fail' );

			return false;
		} elseif ( $this->dbh ) {
			if ( ! $this->has_connected ) {
				$this->init_charset();
			}

			$this->has_connected = true;

			$this->set_charset( $this->dbh );

			$this->ready = true;
			$this->set_sql_mode();

			return true;
		}

		return false;
	}


	/**
	 * Sets blog ID.
	 *
	 * @since 3.0.0
	 *
	 * @param int $blog_id
	 * @param int $network_id Optional. Network ID. Default 0.
	 * @return int Previous blog ID.
	 */
	public function set_blog_id( $blog_id, $network_id = 0 ) {
		$old_blog_id = parent::set_blog_id( $blog_id, $network_id );

		$this->possible_switch_catalog($blog_id);
		
		return $old_blog_id;
	}


	public function query( $query ) {
		var_dump($query);
		if ($this->blogid > 1) {
			$this->possible_switch_catalog(
				$this->get_blog_id_by_table( $this->get_table_from_query($query) )
			);	
		}

		parent::query('SELECT CATALOG()');

		return parent::query($query);
	}


	/**
	 * Sets catalog when it's not the current one.
	 *
	 * @param int $blog_id
	 */
	public function possible_switch_catalog( $blog_id )
	{
		if ($blog_id <= 1) {
			$new_catalog = 'def';
		}
		else {
			$new_catalog = 'blog_' . $blog_id;
		}

		if ($this->current_catalog != $new_catalog) {
			if ( $this->get_connection_type() == 'double' ) {
				if ($new_catalog == 'def') {
					$this->dbh = &$this->def_connection;
				}
				else {
					if (is_null($this->catalog_connection)) {
						$host    = $this->dbhost;
						$port    = null;
						$socket  = null;
						$is_ipv6 = false;
						$client_flags = defined( 'MYSQL_CLIENT_FLAGS' ) ? MYSQL_CLIENT_FLAGS : 0;

						$host_data = $this->parse_db_host( $this->dbhost );
						if ( $host_data ) {
							list( $host, $port, $socket, $is_ipv6 ) = $host_data;
						}

						$this->catalog_connection = mysqli_init();
						mysqli_real_connect( $this->catalog_connection, $host, $this->dbuser, $this->dbpassword, $new_catalog . '.' . $this->dbname, $port, $socket, $client_flags );

						$this->dbh = &$this->catalog_connection;
						$this->init_charset();
						$this->set_charset( $this->dbh );
						$this->set_sql_mode();
					}
					else {
						$this->dbh = &$this->catalog_connection;
					}
				}
			}
			else {
				mysqli_query( $this->dbh, 'USE CATALOG ' . $new_catalog );

				$this->select( $this->dbname, $this->dbh );
			}

			$this->current_catalog = $new_catalog;
		}
	}

	public function get_connection_type() {
		return defined( 'CATALOG_CONNECTION_TYPE' ) ? CATALOG_CONNECTION_TYPE : 'single';
	}

	public function get_blog_id_by_table($table) {
		if ( !str_starts_with( $table, $this->prefix ) ) {
			return 0;
		}

		return $this->blogid;
	}

}

$dbuser     = defined( 'DB_USER' ) ? DB_USER : '';
$dbpassword = defined( 'DB_PASSWORD' ) ? DB_PASSWORD : '';
$dbname     = defined( 'DB_NAME' ) ? DB_NAME : '';
$dbhost     = defined( 'DB_HOST' ) ? DB_HOST : '';

$wpdb       = new MariaDB_DB( $dbuser, $dbpassword, $dbname, $dbhost );