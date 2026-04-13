<?php

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_readable($autoload)) {
    require_once $autoload;
}
$bootstrapEnv = __DIR__ . '/bootstrap_env.php';
if (is_readable($bootstrapEnv)) {
    require_once $bootstrapEnv;
}

$bots = require __DIR__ . '/Bots.php';

class Database {

    private static $instances = [];
    private $connection;

    private function __construct($config) {
        try {
            $this->connection = new PDO(
                "mysql:host={$config['host']};dbname={$config['name']};charset=utf8mb4",
                $config['user'],
                $config['pass']
            );

            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * $useReplica = true to use replica DB instead of live
     */
    public static function getInstance(array $config, bool $useReplica = false) {

        $dbConfig = $useReplica ? $config['replica'] : $config['live'];
        $key = $dbConfig['name'];

        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = new self($dbConfig);
        }

        return self::$instances[$key];
    }

    public function getConnection() {
        return $this->connection;
    }
}