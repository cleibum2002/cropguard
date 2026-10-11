<?php
// File Location: api/masterfile/registrations/get_pending.php
// Farmer registration requests whose email is verified and that are waiting
// for the Admin's decision. Admin only. Never returns the password hash.
ini_set('display_errors', 0);
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['User_ID']) || ($_SESSION['Role'] ?? '') !== 'Admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

try {
    include_once '../../config/db_connection.php';

    $result = $conn->query("SELECT
            Request_ID     AS id,
            Last_Name      AS last_name,
            First_Name     AS first_name,
            Middle_Name    AS middle_name,
            Suffix         AS suffix,
            Birth_Date     AS birth_date,
            TIMESTAMPDIFF(YEAR, Birth_Date, CURDATE()) AS age,
            Gender         AS gender,
            Civil_Status   AS civil_status,
            Email          AS email,
            Contact_Number AS contact,
            RSBA_No        AS rsbsa_no,
            DATE_FORMAT(Verified_At, '%Y-%m-%d %H:%i') AS requested_at
        FROM registration_requests
        WHERE Email_Verified = 1
        ORDER BY Verified_At ASC, Request_ID ASC");
    if (!$result) throw new Exception($conn->error);

    echo json_encode(['status' => 'success', 'data' => $result->fetch_all(MYSQLI_ASSOC)]);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}
?>
