<?php

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

    public static function getInstance($config) {

        $key = $config['name']; // unique DB key

        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = new self($config);
        }

        return self::$instances[$key];
    }

    public function getConnection() {
        return $this->connection;
    }
}