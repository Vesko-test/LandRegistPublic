<?php
// Test search functionality directly
header('Content-Type: application/json');
require_once 'config.php';

try {
    // Simulate search input
    $testData = [
        'id' => '1',  // Try ID 1
        'name' => ''   // Empty name
    ];
    
    $searchId = $testData['id'];
    $searchName = $testData['name'];
    
    $pdo = getDevConnection();
    
    // Build search query
    $sql = "SELECT * FROM persons WHERE 1=1";
    $params = [];
    
    if (!empty($searchId)) {
        $sql .= " AND id = :id";
        $params[':id'] = $searchId;
    }
    
    if (!empty($searchName)) {
        $sql .= " AND name LIKE :name";
        $params[':name'] = '%' . $searchName . '%';
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $person = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$person) {
        echo json_encode([
            'success' => false, 
            'message' => 'No person found with ID: ' . $searchId
        ]);
        exit;
    }
    
    // Get plots
    $plots = [];
    try {
        $sql = "SELECT plot_id, дк, area, тип, usage FROM plots WHERE person_id = :person_id ORDER BY plot_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':person_id' => $person['id']]);
        $plots = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $plots = [];
    }
    
    $response = [
        'success' => true,
        'record' => [
            'id' => $person['id'],
            'egn' => $person['egn'] ?? '',
            'name' => $person['name'] ?? '',
            'gsm' => $person['gsm'] ?? '',
            'email' => $person['email'] ?? '',
            'address1' => $person['address1'] ?? '',
            'address2' => $person['address2'] ?? '',
            'plots' => $plots
        ]
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
?>
