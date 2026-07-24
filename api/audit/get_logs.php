<?php
// File Location: api/audit/get_logs.php
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php';

if (!isset($_SESSION['User_ID']) || $_SESSION['Role'] !== 'Admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

try {
    $limit  = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    $module = isset($_GET['module']) ? $conn->real_escape_string($_GET['module']) : '';

    $where = $module ? "WHERE sl.Module = '$module'" : '';

    $query = "SELECT 
                sl.Log_ID,
                sl.Action,
                sl.Description,
                sl.Module,
                sl.IP_Address,
                sl.Created_At,
                u.Fullname   as user_name,
                sl.User_Role as user_role
              FROM system_logs sl
              LEFT JOIN users u ON sl.User_ID = u.User_ID
              $where
              ORDER BY sl.Created_At DESC
              LIMIT $limit";

    $result = $conn->query($query);
    if (!$result) throw new Exception($conn->error);

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    echo json_encode(['status' => 'success', 'data' => $data]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>