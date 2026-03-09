<?php

class Database
{
    # Propiedades para almacenar la configuración de la base de datos
    private $host;
    private $dbname;
    private $user;
    private $password;
    private $charset;

    # Constructor para cargar la configuración de la base de datos
    public function __construct()
    {
        $config = require 'config/db.php';
        $this->host = $config['host'];
        $this->dbname = $config['dbname'];
        $this->user = $config['user'];
        $this->password = $config['password'];
        $this->charset = $config['charset'];
    }

    public function connect() # Método para establecer la conexión a la base de datos
    {
        try {
            // Crear la conexión
            $conn = new mysqli($this->host, $this->user, $this->password, $this->dbname);

            // Verificar la conexión
            if ($conn->connect_error) {
                throw new Exception("Connection failed: " . $conn->connect_error);
            }

            // Establecer el charset
            if (!$conn->set_charset($this->charset)) {
                throw new Exception("Error loading character set: " . $conn->error);
            }

            return $conn;
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'code' => 'DB500',
                'answer' => 'Error de conexión a base de datos'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}
