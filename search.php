<?php
// Turn off error display to prevent HTML in JSON response
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }
    
    $searchId = isset($input['id']) ? trim($input['id']) : '';
    $searchName = isset($input['name']) ? trim($input['name']) : '';
    
    if (empty($searchId) && empty($searchName)) {
        throw new Exception('ID или име са задължителни за търсене');
    }
    
    $pdo = getDevConnection(); // Use development database (land_registry_dev)
    
    // Inspect columns for dynamic filters and safe JOIN construction
    $ownershipColumns = [];
    $plotsColumns = [];
    $personsColumns = [];
    try {
        $colsStmt = $pdo->query("SHOW COLUMNS FROM ownership");
        $ownershipColumns = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        $ownershipColumns = [];
    }
    try {
        $colsStmt2 = $pdo->query("SHOW COLUMNS FROM plots");
        $plotsColumns = $colsStmt2->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        $plotsColumns = [];
    }
    try {
        $colsStmt3 = $pdo->query("SHOW COLUMNS FROM persons");
        $personsColumns = $colsStmt3->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        $personsColumns = [];
    }

    // Decide JOIN condition based on existing columns
    $joinOn = '';
    $personsJoinOn = '';
    if (in_array('plot_id', $ownershipColumns, true) && in_array('plot_id', $plotsColumns, true)) {
        $joinOn = "ownership.`plot_id` = plots.`plot_id`";
    } elseif (in_array('id', $ownershipColumns, true) && in_array('ownership_id', $plotsColumns, true)) {
        $joinOn = "plots.`ownership_id` = ownership.`id`";
    }

    // Persons join heuristics (most likely first)
    if (in_array('person_id', $ownershipColumns, true) && in_array('id', $personsColumns, true)) {
        $personsJoinOn = "persons.`id` = ownership.`person_id`";
    } elseif (in_array('owner_id', $ownershipColumns, true) && in_array('id', $personsColumns, true)) {
        $personsJoinOn = "persons.`id` = ownership.`owner_id`";
    } elseif (in_array('id', $ownershipColumns, true) && in_array('ownership_id', $personsColumns, true)) {
        $personsJoinOn = "persons.`ownership_id` = ownership.`id`";
    } elseif (in_array('id', $ownershipColumns, true) && in_array('id', $personsColumns, true)) {
        // Least preferred fallback
        $personsJoinOn = "persons.`id` = ownership.`id`";
    }

    // Build dynamic persons select parts based on existing columns
    $personSelectParts = [];
    // Always safe to expose person id if exists
    if (in_array('id', $personsColumns, true)) {
        $personSelectParts[] = "persons.`id` AS person_id";
    } else {
        $personSelectParts[] = "NULL AS person_id";
    }
    // Map of expected person fields => select alias
    $personFieldMap = [
        'name' => 'person_name',
        'full_name' => 'person_full_name',
        'owner_name' => 'person_owner_name',
        'names' => 'person_names',
        'phone' => 'person_phone',
        'email' => 'person_email',
        'address' => 'person_address',
        'contact_address' => 'person_contact_address',
    ];
    foreach ($personFieldMap as $col => $alias) {
        if (in_array($col, $personsColumns, true)) {
            $personSelectParts[] = "persons.`{$col}` AS {$alias}";
        } else {
            $personSelectParts[] = "NULL AS {$alias}";
        }
    }

    // Build single JOIN query to fetch ownership and plots together
    $sql = "SELECT ownership.*, 
                   plots.`plot_id` AS plot_id, 
                   plots.`area` AS plot_area, 
                   plots.`usage` AS plot_usage,
                   ownership.`share` * plots.`area` AS total_share_area,
                   " . implode(",\n                   ", $personSelectParts) . "
            FROM ownership
            " . ($joinOn !== '' ? "LEFT JOIN plots ON (" . $joinOn . ")" : "LEFT JOIN plots ON (1=0)") . "
            " . ($personsJoinOn !== '' ? "LEFT JOIN persons ON (" . $personsJoinOn . ")" : "") . "
            WHERE 1=1";
    $params = [];
    
    if (!empty($searchId)) {
        // Build dynamic ID filters to allow searching by ownership or person identifiers
        $idConds = [];
        if (in_array('id', $ownershipColumns, true)) {
            $idConds[] = "ownership.`id` = :id";
        }
        if (in_array('person_id', $ownershipColumns, true)) {
            $idConds[] = "ownership.`person_id` = :id";
        }
        if (in_array('owner_id', $ownershipColumns, true)) {
            $idConds[] = "ownership.`owner_id` = :id";
        }
        if ($personsJoinOn !== '' && in_array('id', $personsColumns, true)) {
            $idConds[] = "persons.`id` = :id";
        }
        // Fallback to ownership.id if nothing detected (shouldn't happen, but safe)
        if (empty($idConds)) {
            $idConds[] = "ownership.`id` = :id";
        }
        $sql .= " AND (" . implode(' OR ', $idConds) . ")";
        $params[':id'] = $searchId;
    }
    
    // Only apply name filtering when ID is not provided (ID has priority)
    if (empty($searchId) && !empty($searchName)) {
        // Prefer searching in persons if joined; otherwise in ownership
        $candidateCols = ['name', 'full_name', 'owner_name', 'names', 'first_name', 'last_name'];
        $existingPersonNameCols = array_values(array_intersect($candidateCols, $personsColumns));
        $existingOwnershipNameCols = array_values(array_intersect($candidateCols, $ownershipColumns));

        if (!empty($existingPersonNameCols) && $personsJoinOn !== '') {
            $likeParts = [];
            foreach ($existingPersonNameCols as $col) {
                $likeParts[] = "persons.`" . str_replace("`", "``", $col) . "` LIKE :name";
            }
            $sql .= " AND (" . implode(' OR ', $likeParts) . ")";
            $params[':name'] = '%' . $searchName . '%';
        } elseif (!empty($existingOwnershipNameCols)) {
            $likeParts = [];
            foreach ($existingOwnershipNameCols as $col) {
                $likeParts[] = "ownership.`" . str_replace("`", "``", $col) . "` LIKE :name";
            }
            $sql .= " AND (" . implode(' OR ', $likeParts) . ")";
            $params[':name'] = '%' . $searchName . '%';
        } else {
            throw new Exception('Name search is unavailable: no name column found in persons/ownership tables');
        }
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Build QuickInfo list from persons (by ID prefix or by name LIKE), independent of main query match
    $quickInfo = [];
    if (!empty($personsColumns)) {
        $nameCols = ['full_name', 'name', 'owner_name', 'names'];
        $displayNameCol = null;
        foreach ($nameCols as $c) {
            if (in_array($c, $personsColumns, true)) { $displayNameCol = $c; break; }
        }
        $nameSelect = $displayNameCol ? "persons.`$displayNameCol` AS name" : "NULL AS name";

        if (!empty($searchId)) {
            // ID prefix search (CAST to CHAR to support numeric ids)
            $sqlQuick = "SELECT persons.`id` AS id, $nameSelect FROM persons WHERE CAST(persons.`id` AS CHAR) LIKE :pid_like ORDER BY persons.`id` LIMIT 100";
            $stmtQuick = $pdo->prepare($sqlQuick);
            $stmtQuick->execute([':pid_like' => $searchId . '%']);
            $quickInfo = $stmtQuick->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } elseif (!empty($searchName)) {
            // Name contains search across available name columns
            $existingPersonNameCols = array_values(array_intersect($nameCols, $personsColumns));
            if (!empty($existingPersonNameCols)) {
                $likeParts = [];
                foreach ($existingPersonNameCols as $col) {
                    $likeParts[] = "persons.`" . str_replace("`", "``", $col) . "` LIKE :pname";
                }
                $whereQuick = 'WHERE ' . implode(' OR ', $likeParts);
                $sqlQuick = "SELECT persons.`id` AS id, $nameSelect FROM persons $whereQuick ORDER BY persons.`id` LIMIT 100";
                $stmtQuick = $pdo->prepare($sqlQuick);
                $stmtQuick->execute([':pname' => '%' . $searchName . '%']);
                $quickInfo = $stmtQuick->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        }
    }
    
    if (!$rows || count($rows) === 0) {
        // Fallback: return person info even if there is no ownership/plots
        $personOnly = null;
        if (!empty($personsColumns)) {
            // Build SELECT for persons using the same aliases as in the main query
            $personSelectForFallback = $personSelectParts; // includes person_id and aliased fields

            // Build WHERE for person lookup based on provided input
            $where = 'WHERE 1=1';
            $p = [];
            if (!empty($searchId) && in_array('id', $personsColumns, true)) {
                $where .= ' AND persons.`id` LIKE :pid_like';
                $p[':pid_like'] = $searchId . '%';
            } elseif (empty($searchId) && !empty($searchName)) {
                $candidateCols = ['name', 'full_name', 'owner_name', 'names', 'first_name', 'last_name'];
                $existingPersonNameCols = array_values(array_intersect($candidateCols, $personsColumns));
                if (!empty($existingPersonNameCols)) {
                    $likeParts = [];
                    foreach ($existingPersonNameCols as $col) {
                        $likeParts[] = "persons.`" . str_replace("`", "``", $col) . "` LIKE :pname";
                    }
                    $where .= ' AND (' . implode(' OR ', $likeParts) . ')';
                    $p[':pname'] = '%' . $searchName . '%';
                }
            }

            $sql2 = "SELECT " . implode(", ", $personSelectForFallback) . " FROM persons " . $where . " LIMIT 1";
            $stmt2 = $pdo->prepare($sql2);
            $stmt2->execute($p);
            $personOnly = $stmt2->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($personOnly) {
            echo json_encode([
                'success' => true,
                'record' => [
                    'id' => $personOnly['person_id'] ?? null,
                    'name' => ($personOnly['person_full_name'] ?? null) ?: ($personOnly['person_name'] ?? null) ?: ($personOnly['person_owner_name'] ?? null) ?: ($personOnly['person_names'] ?? null) ?: '',
                    'gsm' => $personOnly['person_phone'] ?? '',
                    'email' => $personOnly['person_email'] ?? '',
                    'address1' => $personOnly['person_address'] ?? '',
                    'address2' => $personOnly['person_contact_address'] ?? '',
                    'plots' => [],
                    'quickInfo' => $quickInfo
                ]
            ]);
            exit;
        } else {
            if (!empty($quickInfo)) {
                echo json_encode([
                    'success' => true,
                    'record' => [
                        'id' => null,
                        'name' => '',
                        'gsm' => '',
                        'email' => '',
                        'address1' => '',
                        'address2' => '',
                        'plots' => [],
                        'quickInfo' => $quickInfo
                    ]
                ]);
                exit;
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Записът не е намерен'
                ]);
                exit;
            }
        }
    }
    // The first row contains the ownership/person fields; collect plots from all rows
    $first = $rows[0];
    $plots = [];
    foreach ($rows as $r) {
        if (array_key_exists('plot_id', $r) && $r['plot_id'] !== null) {
            $plots[] = [
                'plot_id' => $r['plot_id'],
                'area' => $r['plot_area'] ?? null,
                'usage' => $r['plot_usage'] ?? null,
                'share' => $r['share'] ?? null,
                'total_share_area' => $r['total_share_area'] ?? null,
                'test' => $r['test'] ?? null,
            ];
        }
    }
    
    // Prepare response
    $response = [
        'success' => true,
        'record' => [
            'id' => ($first['id'] ?? null) ?: ($first['person_id'] ?? null),
            // Prefer person name fields if present, fallback to ownership
            'name' => ($first['person_full_name'] ?? null) ?: ($first['person_name'] ?? null) ?: ($first['person_owner_name'] ?? null) ?: ($first['person_names'] ?? null) ?: ($first['name'] ?? ''),
            'gsm' => ($first['person_phone'] ?? null) ?: ($first['phone'] ?? ''),
            'email' => ($first['person_email'] ?? null) ?: ($first['email'] ?? ''),
            'address1' => ($first['person_address'] ?? null) ?: ($first['address'] ?? ''),
            'address2' => ($first['person_contact_address'] ?? null) ?: ($first['contact_address'] ?? ''),
            'plots' => $plots,
            'quickInfo' => $quickInfo
        ]
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_details' => $e->getFile() . ':' . $e->getLine()
    ]);
} catch (Error $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage(),
        'error_details' => $e->getFile() . ':' . $e->getLine()
    ]);
}
?>
