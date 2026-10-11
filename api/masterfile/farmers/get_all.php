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

    // Personal details (from the 2026-10-11 registration form; empty for
    // farmers who registered before it) and activity counts for the View window.
    $query = "SELECT
                u.User_ID        as id,
                u.Fullname       as fullname,
                u.Email          as email,
                u.Contact_Number as contact,
                u.RSBA_No        as rsbsa_no,
                u.STATUS         as status,
                u.Last_Name      as last_name,
                u.First_Name     as first_name,
                u.Middle_Name    as middle_name,
                u.Suffix         as suffix,
                u.Birth_Date     as birth_date,
                TIMESTAMPDIFF(YEAR, u.Birth_Date, CURDATE()) as age,
                u.Gender         as gender,
                u.Civil_Status   as civil_status,
                (SELECT COUNT(*) FROM farm_location fl WHERE fl.Farmer_ID = u.User_ID AND fl.Is_Archived = 0) as farms,
                (SELECT COUNT(*) FROM damage_report dr WHERE dr.Farmer_ID = u.User_ID AND dr.Status <> 'Archived') as reports,
                (SELECT COUNT(*) FROM damage_report dr WHERE dr.Farmer_ID = u.User_ID AND dr.Status = 'Approved') as approved
              FROM users u
              WHERE u.Role = 'Farmer'
              ORDER BY u.Fullname ASC";

    $result = $conn->query($query);
    if (!$result) throw new Exception($conn->error);

    $data = [];
    while ($row = $result->fetch_assoc()) { $data[] = $row; }

    echo json_encode(["status" => "success", "data" => $data]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => safeErrorMessage($e)]);
}
?>