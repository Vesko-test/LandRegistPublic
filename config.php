<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');  // No password for XAMPP default setup

// Database names
define('DB_NAME_DEV', 'land_registry_dev');
define('DB_NAME_PROD', 'land_registry');

// Create database connection
function getDBConnection($database = 'dev') {
    try {
        $dbName = ($database === 'prod') ? DB_NAME_PROD : DB_NAME_DEV;
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $dbName . ";charset=utf8", DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch(PDOException $e) {
        throw new Exception("Connection failed: " . $e->getMessage());
    }
}

// Get connection to development database (default)
function getDevConnection() {
    return getDBConnection('dev');
}

// Get connection to production database
function getProdConnection() {
    return getDBConnection('prod');
}

// Database tables already exist - no initialization needed
?>
