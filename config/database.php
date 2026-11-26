<?php
/**
 * MicroPulse: Real-Time Loan Management Platform
 * Database Configuration
 */

class Database {
    private $host = 'localhost';
    private $db_name = 'micropulse_db';
    private $username = 'root';
    private $password = '';
    private $charset = 'utf8mb4';
    
    public $conn;
    
    public function getConnection() {
        if ($this->conn === null) {
            try {
                $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=" . $this->charset;
                $this->conn = new PDO($dsn, $this->username, $this->password);
                $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                $this->conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            } catch(PDOException $exception) {
                echo "Connection error: " . $exception->getMessage();
                return null;
            }
        }
        
        return $this->conn;
    }
}
?>
