<?php
ini_set('display_errors', 0);
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['User_ID']) || !in_array($_SESSION['Role'], ['Admin', 'MAO'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

try {
    include_once '../../config/db_connection.php';

    $query = "SELECT 
                Type_ID        AS id,
                Crop_Name      AS name,
                Commodity_Type AS type,
                Growth_Duration AS period,
                Standard_Production_Cost AS prod_cost,
                Description    AS description,
                Status         AS status
              FROM crop_type
              WHERE Status = 'Active'
              ORDER BY Crop_Name ASC";

    $result = $conn->query($query);

    if (!$result) {
        throw new Exception("Query failed: " . $conn->error);
    }

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    echo json_encode(["status" => "success", "data" => $data]);

} catch (Throwable $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>