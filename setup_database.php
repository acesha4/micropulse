<?php
// Database setup script
require_once 'config/database.php';

echo "<h3>MicroPulse Database Setup</h3>";

try {
    $database = new Database();
    $db = $database->getConnection();
    
    if ($db) {
        echo "<p style='color: green;'>✓ Database connection successful</p>";
        
        // Check if database exists
        $query = "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = 'micropulse'";
        $stmt = $db->prepare($query);
        $stmt->execute();
        
        if ($stmt->rowCount() == 0) {
            echo "<p style='color: orange;'>○ Database 'micropulse' does not exist</p>";
            echo "<p>Please create the database first using phpMyAdmin or MySQL console</p>";
            echo "<p><code>CREATE DATABASE micropulse;</code></p>";
        } else {
            echo "<p style='color: green;'>✓ Database 'micropulse' exists</p>";
            
            // Check if users table exists
            $query = "SHOW TABLES LIKE 'users'";
            $stmt = $db->prepare($query);
            $stmt->execute();
            
            if ($stmt->rowCount() == 0) {
                echo "<p style='color: orange;'>○ Tables not found. Please import database.sql</p>";
                echo "<p>Steps:</p>";
                echo "<ol>";
                echo "<li>Open phpMyAdmin</li>";
                echo "<li>Select 'micropulse' database</li>";
                echo "<li>Click 'Import' tab</li>";
                echo "<li>Choose database.sql file</li>";
                echo "<li>Click 'Go' to import</li>";
                echo "</ol>";
            } else {
                echo "<p style='color: green;'>✓ Tables exist</p>";
                
                // Create/reset admin user
                $query = "DELETE FROM users WHERE username = 'admin'";
                $stmt = $db->prepare($query);
                $stmt->execute();
                
                $password = 'password';
                $hash = password_hash($password, PASSWORD_DEFAULT);
                
                $query = "INSERT INTO users (username, email, password_hash, full_name, role) 
                          VALUES ('admin', 'admin@micropulse.com', :hash, 'System Administrator', 'admin')";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':hash', $hash);
                
                if ($stmt->execute()) {
                    echo "<p style='color: green;'>✓ Admin user reset successfully</p>";
                    echo "<p><strong>Login Credentials:</strong></p>";
                    echo "<p>Username: <code>admin</code></p>";
                    echo "<p>Password: <code>password</code></p>";
                    echo "<p><a href='login.php'>Go to Login Page</a></p>";
                } else {
                    echo "<p style='color: red;'>✗ Failed to create admin user</p>";
                }
            }
        }
    } else {
        echo "<p style='color: red;'>✗ Database connection failed</p>";
        echo "<p>Please check your database configuration in config/database.php</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>
