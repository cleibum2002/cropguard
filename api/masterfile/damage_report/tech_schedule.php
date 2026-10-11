<?php
// File Location: api/masterfile/damage_report/tech_schedule.php
// How many field visits a technician already has on each upcoming day, so the
// Admin's Assign Technician calendar can grey out full days (max 3 a day -
// enforced again in assign_technician.php). Admin only.
// GET ?technician_id=N[&report_id=R]  ->  { status, max_per_day, days: { "YYYY-MM-DD": count } }
// report_id (the report being assigned) is left out of the counts, same as
// assign_technician.php, so re-saving it doesn't count it twice.
ini_set('display_errors', 0);
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['User_ID']) || ($_SESSION['Role'] ?? '') !== 'Admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

try {
    include_once '../../config/db_connection.php';
    $techId = (int)($_GET['technician_id'] ?? 0);
    $reportId = (int)($_GET['report_id'] ?? 0);
    if ($techId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Missing technician.']);
        exit;
    }
    $st = $conn->prepare("SELECT DATE_FORMAT(Visit_Date, '%Y-%m-%d') AS d, COUNT(*) AS n
                          FROM damage_report
                          WHERE Technician_ID = ? AND Visit_Date >= CURDATE() AND Status <> 'Archived' AND Damage_Report_ID <> ?
                          GROUP BY Visit_Date");
    $st->bind_param("ii", $techId, $reportId);
    $st->execute();
    $days = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $days[$r['d']] = (int)$r['n'];
    $st->close();
    echo json_encode(['status' => 'success', 'max_per_day' => 3, 'days' => (object)$days]);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}
?>
