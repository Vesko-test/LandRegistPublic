<?php
// Quick test to see ownership table structure
require_once 'config.php';

try {
    $pdo = getDevConnection();
    
    // Show all columns in ownership table
    $sql = "SHOW COLUMNS FROM ownership";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h2>Ownership Table Columns:</h2>";
    foreach ($columns as $column) {
        echo "<p>" . $column['Field'] . " (" . $column['Type'] . ")</p>";
    }
    
    // Show a sample record
    $sql = "SELECT * FROM ownership LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $sample = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<h2>Sample Record:</h2>";
    echo "<pre>" . print_r($sample, true) . "</pre>";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
