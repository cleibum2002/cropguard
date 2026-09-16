<?php
// File Location: api/technician/update_inspection.php
//
// Lets a technician correct their own already-submitted findings/severity and
// add more evidence photos, for as long as the report is still sitting in
// 'Inspected' (i.e. Admin hasn't approved or rejected it yet). Once Admin has
// acted on it, aid may already be allocated off the recorded severity, so
// editing is locked - same "locked after a decision" rule already used for
// the MAO verification panel in report_management.html.
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php';

$tech_id = $_SESSION['User_ID'] ?? null;
if (!$tech_id || ($_SESSION['Role'] ?? '') !== 'Technician') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

if (!isset($_POST['id'], $_POST['observations'], $_POST['severity'])) {
    echo json_encode(['status' => 'error', 'message' => 'Required fields missing.']);
    exit;
}

$report_id = (int)$_POST['id'];
$findings  = strip_tags(trim($_POST['observations']));
$severity  = $_POST['severity'];

if ($findings === '') {
    echo json_encode(['status' => 'error', 'message' => 'Please provide field observations.']);
    exit;
}

$allowed_severities = ['Minimal', 'Light', 'Moderate', 'Severe', 'Total Loss'];
if (!in_array($severity, $allowed_severities)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid severity value.']);
    exit;
}

$conn->begin_transaction();

try {
    // Ownership + edit-window check. FOR UPDATE so a concurrent Admin decision
    // can't slip in between this check and the write below.
    $check = $conn->prepare("SELECT Description, Status FROM damage_report WHERE Damage_Report_ID = ? AND Technician_ID = ? FOR UPDATE");
    $check->bind_param("ii", $report_id, $tech_id);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$row) {
        throw new Exception("Report not found or not assigned to you.");
    }
    if ($row['Status'] !== 'Inspected') {
        throw new Exception("This report has already been decided on by the Admin and can no longer be edited.");
    }

    // Replace the existing "--- TECH FINDINGS ---" section rather than
    // appending a second one, which would break every place that splits this
    // column on that exact marker (report_management.html, damage_reports.html,
    // this page's own read-only viewer).
    $techMarker = '--- TECH FINDINGS ---';
    $rawDescription = $row['Description'] ?? '';
    $farmerPart = $rawDescription;
    if (strpos($rawDescription, $techMarker) !== false) {
        $farmerPart = rtrim(explode($techMarker, $rawDescription, 2)[0]);
    }
    $newDescription = $farmerPart . "\n\n" . $techMarker . "\n" . $findings;

    $update = $conn->prepare("UPDATE damage_report SET Description = ?, Damage_Severity = ? WHERE Damage_Report_ID = ? AND Technician_ID = ?");
    $update->bind_param("ssii", $newDescription, $severity, $report_id, $tech_id);
    $update->execute();
    $update->close();

    // New photos are additive - same validated-upload logic as submit_validation.php.
    $uploadDir = '../../uploads/evidence/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    if (isset($_FILES['evidencePhotos']) && !empty($_FILES['evidencePhotos']['name'][0])) {
        $fileCount = count($_FILES['evidencePhotos']['name']);
        $imgStmt = $conn->prepare("INSERT INTO damage_report_images (Damage_Report_ID, Image_Path, Uploaded_By_Role) VALUES (?, ?, 'Technician')");

        for ($i = 0; $i < $fileCount; $i++) {
            $tmpName   = $_FILES['evidencePhotos']['tmp_name'][$i];
            $fileName  = $_FILES['evidencePhotos']['name'][$i];
            $fileError = $_FILES['evidencePhotos']['error'][$i];

            if ($fileError === 0) {
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $allowedExtensions = ['jpg', 'jpeg', 'png'];
                $isValidImage = in_array($fileExtension, $allowedExtensions) && (getimagesize($tmpName) !== false);

                if ($isValidImage) {
                    $newFileName = uniqid('evd_' . $report_id . '_') . '.' . $fileExtension;
                    $destination = $uploadDir . $newFileName;

                    if (move_uploaded_file($tmpName, $destination)) {
                        $dbPath = 'uploads/evidence/' . $newFileName;
                        $imgStmt->bind_param("is", $report_id, $dbPath);
                        $imgStmt->execute();
                    }
                }
            }
        }
        $imgStmt->close();
    }

    $conn->commit();

    require_once '../audit/log_action.php';
    logAction($conn, $tech_id, 'Technician',
        'Edit Inspection',
        "Field findings/severity updated for Report #$report_id.",
        'Damage Reports'
    );

    echo json_encode(['status' => 'success', 'message' => 'Inspection updated.']);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}
?>
