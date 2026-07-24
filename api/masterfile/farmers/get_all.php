<?php
ini_set('display_errors', 0);
header('Content-Type: application/json');

try {
    include_once '../../config/db_connection.php';

    $query = "SELECT 
                User_ID        as id,
                Fullname       as fullname,
                Email          as email,
                Contact_Number as contact,
                RSBA_No        as rsbsa_no,
                STATUS         as status
              FROM users
              WHERE Role = 'Farmer'
              ORDER BY Fullname ASC";

    $result = $conn->query($query);
    if (!$result) throw new Exception($conn->error);

    $data = [];
    while ($row = $result->fetch_assoc()) { $data[] = $row; }

    echo json_encode(["status" => "success", "data" => $data]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>