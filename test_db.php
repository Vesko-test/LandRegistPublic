<?php
// Simple test to check database and table structure
require_once 'config.php';

try {
    $pdo = getDevConnection();
    echo "<h2>Database Connection: SUCCESS</h2>";
    
    // Check if persons table exists and has data
    $sql = "SELECT COUNT(*) as count FROM persons";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p>Persons table records: " . $result['count'] . "</p>";
    
    // Show first person
    $sql = "SELECT * FROM persons LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $person = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($person) {
        echo "<h3>First Person:</h3>";
        echo "<pre>" . print_r($person, true) . "</pre>";
        
        // Check if plots table exists
        try {
            $sql = "SELECT COUNT(*) as count FROM plots WHERE person_id = :person_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':person_id' => $person['id']]);
            $plotCount = $stmt->fetch(PDO::FETCH_ASSOC);
            echo "<p>Plots for this person: " . $plotCount['count'] . "</p>";
            
            if ($plotCount['count'] > 0) {
                $sql = "SELECT plot_id, дк, area, тип, usage FROM plots WHERE person_id = :person_id LIMIT 3";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':person_id' => $person['id']]);
                $plots = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo "<h3>First 3 Plots:</h3>";
                echo "<pre>" . print_r($plots, true) . "</pre>";
            }
        } catch (Exception $e) {
            echo "<p>Plots table error: " . $e->getMessage() . "</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<h2>Database Error:</h2>";
    echo "<p>" . $e->getMessage() . "</p>";
}
?>
