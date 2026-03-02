<?php

class Database
{
    private static ?mysqli $instance = null;
    private static ?array $config = null;

    private function __construct() {}

    public static function getConnection(): mysqli
    {
        if (self::$instance === null) {

            if (self::$config === null) {
                self::$config = require __DIR__ . '/config/db.php';
            }

            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

            try {
                self::$instance = new mysqli(
                    self::$config['host'],
                    self::$config['user'],
                    self::$config['pass'],
                    self::$config['dbname']
                );

                self::$instance->set_charset(self::$config['charset']);

            } catch (mysqli_sql_exception $e) {
                http_response_code(500);
                echo json_encode([
                    'status' => 'error',
                    'code' => 'DB500',
                    'answer' => 'Error de conexión a base de datos'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        return self::$instance;
    }
}