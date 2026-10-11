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
    $requests = $result->fetch_all(MYSQLI_ASSOC);

    // Possible duplicates: someone who registers again with a new email and a
    // made-up RSBSA number gets past the RSBSA check, so point out existing
    // farmers (and other waiting requests) with the same name and birth date,
    // the same name where no birth date is on record (accounts made before
    // these fields existed), or the same mobile number. The Admin decides.
    $low   = fn($s) => mb_strtolower(trim(preg_replace('/\s+/', ' ', (string)$s)));
    $phone = fn($s) => substr(preg_replace('/\D/', '', (string)$s), -10);
    $hasWords = fn($full, $first, $last) => $first !== '' && $last !== ''
        && str_contains(' ' . $low($full) . ' ', ' ' . $first . ' ') && str_contains(' ' . $low($full) . ' ', ' ' . $last . ' ');

    $farmers = $conn->query("SELECT Fullname, Email, Last_Name, First_Name, Birth_Date, Contact_Number FROM users WHERE Role = 'Farmer'")->fetch_all(MYSQLI_ASSOC);
    $others  = array_map(fn($r) => ['Fullname' => trim($r['first_name'] . ' ' . $r['last_name']), 'Email' => $r['email'], 'Last_Name' => $r['last_name'],
        'First_Name' => $r['first_name'], 'Birth_Date' => $r['birth_date'], 'Contact_Number' => $r['contact'], 'id' => $r['id']], $requests);

    foreach ($requests as &$req) {
        $first = $low($req['first_name']); $last = $low($req['last_name']); $tel = $phone($req['contact']);
        $matches = [];
        foreach ([['farmer', $farmers], ['request', $others]] as [$kind, $list]) {
            foreach ($list as $p) {
                if ($kind === 'request' && (int)$p['id'] === (int)$req['id']) continue;
                $why = [];
                $sameName = ($p['Last_Name'] !== null && $p['Last_Name'] !== '')
                    ? ($low($p['Last_Name']) === $last && $low($p['First_Name']) === $first)
                    : $hasWords($p['Fullname'], $first, $last);
                if ($sameName && !empty($p['Birth_Date']) && $p['Birth_Date'] === $req['birth_date']) $why[] = 'same name and date of birth';
                elseif ($sameName && empty($p['Birth_Date']))                                           $why[] = 'same name';
                if ($tel !== '' && strlen($tel) === 10 && $phone($p['Contact_Number']) === $tel)       $why[] = 'same mobile number';
                if ($why) $matches[] = ['kind' => $kind, 'name' => $p['Fullname'], 'email' => $p['Email'], 'reasons' => $why];
            }
        }
        $req['duplicates'] = $matches;
    }
    unset($req);

    echo json_encode(['status' => 'success', 'data' => $requests]);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}
?>
