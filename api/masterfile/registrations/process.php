<?php
// File Location: api/masterfile/registrations/process.php
// Admin approves or rejects a farmer registration request.
//   approve -> the request becomes a Farmer account in `users` and is removed
//              from the waiting list; the applicant is emailed.
//   reject  -> the request is DELETED (no personal data is kept) and the
//              applicant is emailed the reason.
// Body: { request_id, action: "approve" | "reject", reason (reject only) }
ini_set('display_errors', 0);
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['User_ID']) || ($_SESSION['Role'] ?? '') !== 'Admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

try {
    include_once '../../config/db_connection.php';
    require_once '../../config/mailer.php';
    require_once '../../audit/log_action.php';

    $data   = json_decode(file_get_contents("php://input"), true) ?: [];
    $id     = (int)($data['request_id'] ?? 0);
    $action = $data['action'] ?? '';
    if ($id <= 0 || !in_array($action, ['approve', 'reject'], true)) {
        echo json_encode(['status' => 'error', 'message' => 'Missing request or action.']);
        exit;
    }

    $reason = '';
    if ($action === 'reject') {
        $reason = trim(preg_replace('/\s+/', ' ', strip_tags((string)($data['reason'] ?? ''))));
        if ($reason === '') {
            echo json_encode(['status' => 'error', 'message' => 'Please give a reason for rejecting this registration.']);
            exit;
        }
        if (mb_strlen($reason) > 300) $reason = mb_substr($reason, 0, 300);
    }

    $conn->begin_transaction();

    $st = $conn->prepare("SELECT * FROM registration_requests WHERE Request_ID = ? AND Email_Verified = 1 FOR UPDATE");
    $st->bind_param("i", $id);
    $st->execute();
    $req = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$req) throw new Exception("This registration request was already handled or no longer exists.");

    // users.Fullname (VARCHAR 100) is what every list and report shows, so it
    // keeps the familiar "First Middle Last Suffix" form.
    $fullname = mb_substr(trim(implode(' ', array_filter([$req['First_Name'], $req['Middle_Name'], $req['Last_Name'], $req['Suffix']]))), 0, 100);

    if ($action === 'approve') {
        $chk = $conn->prepare("SELECT User_ID FROM users WHERE Email = ? OR RSBA_No = ?");
        $chk->bind_param("ss", $req['Email'], $req['RSBA_No']);
        $chk->execute();
        if ($chk->get_result()->fetch_assoc()) throw new Exception("An account with this email or RSBSA number already exists. Reject this request as a duplicate.");
        $chk->close();

        $ins = $conn->prepare("INSERT INTO users
            (Role, Fullname, Last_Name, First_Name, Middle_Name, Suffix, Birth_Date, Gender, Civil_Status, Email, Password, Contact_Number, RSBA_No, STATUS, Email_Verified)
            VALUES ('Farmer', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', 1)");
        $ins->bind_param("ssssssssssss", $fullname, $req['Last_Name'], $req['First_Name'], $req['Middle_Name'], $req['Suffix'],
            $req['Birth_Date'], $req['Gender'], $req['Civil_Status'], $req['Email'], $req['Password'], $req['Contact_Number'], $req['RSBA_No']);
        $ins->execute();
        $newUserId = $conn->insert_id;
        $ins->close();
    }

    $del = $conn->prepare("DELETE FROM registration_requests WHERE Request_ID = ?");
    $del->bind_param("i", $id);
    $del->execute();
    $del->close();

    $conn->commit();

    if ($action === 'approve') {
        logAction($conn, $_SESSION['User_ID'], $_SESSION['Role'], 'Approve Registration',
            "Approved the farmer registration of {$fullname} (account #{$newUserId})", 'User Management');
        sendRegistrationApprovedEmail($req['Email'], $fullname); // best-effort
        echo json_encode(['status' => 'success', 'message' => 'Registration approved. The farmer can now log in.']);
    } else {
        logAction($conn, $_SESSION['User_ID'], $_SESSION['Role'], 'Reject Registration',
            "Rejected the farmer registration of {$fullname}: {$reason}", 'User Management');
        sendRegistrationRejectedEmail($req['Email'], $fullname, $reason); // best-effort
        echo json_encode(['status' => 'success', 'message' => 'Registration rejected. The applicant was emailed the reason and the request was removed.']);
    }

} catch (Throwable $e) {
    if (isset($conn)) $conn->rollback();
    $known = [
        'This registration request was already handled or no longer exists.',
        'An account with this email or RSBSA number already exists.',
    ];
    $isKnown = false;
    foreach ($known as $k) { if (strpos($e->getMessage(), $k) === 0) { $isKnown = true; break; } }
    echo json_encode(['status' => 'error', 'message' => $isKnown ? $e->getMessage() : safeErrorMessage($e)]);
}
?>
