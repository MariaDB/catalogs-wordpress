<?php

class MariaDB_Catalog_API
{
    /**
     * The connection to the MariaDB server.
     * @var \PDO
     */
    private $connection;

    const MINIMAL_MARIA_VERSION = '11.0.2'; // This is too low, because this is a beta version we are devloping for.

    /**
     * 
     * @param string $server
     * @param int $serverPort
     * @param string $dbUser
     * @param string $dbPass
     * @param array $options
     * @return void
     * @throws PDOException 
     * @throws Exception 
     */
    public function __construct( protected $server = 'localhost', protected $serverPort = 3306, protected $dbUser = 'root', protected $dbPass = '') {
        $this->server = $server;
        $this->serverPort = $serverPort;
        $this->user = $dbUser;
        $this->password = $dbPass;

        //$this->createAdminUserForCatalog('test11', 'yolo', 'yolo');
    }

    public function get_new_config() {
        $name = str_replace( '.', '', $_SERVER['HTTP_HOST'] );

        return [
            'dbname' => $name,
            'username' => $name,
            'password' => wp_generate_password(),
            'host' => $this->server,
        ];
    }

    public function getConnection() {
        if (!$this->connection)
        {
            // Connect.
            $this->connection = new \PDO("mysql:host=$this->server;port=$this->serverPort", $this->user, $this->password);

            // Check the maria DB version.
            $version_query = $this->connection->query('SELECT VERSION()');
            $version = $version_query->fetchColumn();

            if (version_compare($version, self::MINIMAL_MARIA_VERSION, '<')) {
                throw new Exception('The MariaDB version is too low. The minimal version is ' . self::MINIMAL_MARIA_VERSION);
            }
        }

        return $this->connection;
    }

    /**
     * Create a new catalog
     * 
     * @param string $catName The new Catalofg name.
     * @param string|null $catUser 
     * @param string|null $catPassword 
     * @param array|null $args 
     * @return int 
     */
    public function create( string $catName, string $catUser = null, string $catPassword=null, array $args=null): int{
        // Check if shell scripts are allowed to execute.
        // Might be restricted by the server.
        // Check if the Catalog name is valid.
        if (in_array($catName, array_keys($this->show()))) {
            throw new Exception('Catalog name already exists.');
        }
        // Basicly run:
        // mariadb-install-db --catalogs="list" --catalog-user=user --catalog-password[=password] --catalog-client-arg=arg

        $cmd = '/usr/local/mysql/scripts/mariadb-install-db --datadir=/datadir --catalogs=' . escapeshellarg($catName);

        $output=null;
        $retval=null;
        $last_line = exec($cmd, $output, $retval);

        shell_exec('killall mariadbd');
        shell_exec('/usr/local/mysql/bin/mariadbd --datadir=/datadir --catalogs --user=root > /dev/null 2>/dev/null &');
        sleep(2);
        $this->connection = null;
        

        $this->createAdminUserForCatalog($catName, $catUser, $catPassword);

        return $this->getPort($catName);
    }

    /**
     * Get the port of a catalog.
     * @param string $catName Tha catalog name.
     * @return int
     */
    public function getPort(string $catName) :int {
        // TODO what query to run?
        return $this->serverPort;
    }

    /**
     * Get all catalogs.
     * @return int[] Named array with cat name and port.
     */
    public function show() :array
    {
        $catalogs = [];
        $results = $this->getConnection()->query('SHOW CATALOGS');
        foreach ($results as $row)
        {
            // For now, we just return the default port for all catalogs.
            $catalogs[$row['Catalog']] = $this->serverPort;
        }
        return $catalogs;
    }

    /**
     * Drop a catalog.
     * @param string $catName The catalog name.
     * @return void 
     */
    public function drop( string $catName ) : bool
    {
        $this->getConnection()->query('DROP CATALOG ' .
            $this->getConnection()->quote($catName));

        if ($this->getConnection()->errorCode()) {
            throw new Exception('Error dropping catalog: ' . $this->getConnection()->errorInfo()[2]);
        }
        return true;
    }

    public function alter() {
        // Out of scope
    }

    /**
     * @return void
     */
    public function createAdminUserForCatalog(string $catalog, string $userName, string $password): void
    {     
        $this->getConnection()->exec("USE CATALOG ". $catalog);
        $this->getConnection()->prepare("CREATE USER ?@? IDENTIFIED BY ?;")->execute([$userName, $this->server, $password]);
        $this->getConnection()->prepare("GRANT ALL PRIVILEGES ON *.* TO ?@? IDENTIFIED BY ? WITH GRANT OPTION;")->execute([$userName, $this->server,$password]);
        $this->getConnection()->exec("CREATE DATABASE ". $catalog);
    }
}