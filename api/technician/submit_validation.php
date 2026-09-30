<?php
// File Location: api/technician/submit_validation.php
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php';

$tech_id = $_SESSION['User_ID'] ?? null;
if (!$tech_id) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

if (isset($_POST['id'], $_POST['observations'], $_POST['severity'])) {
    $report_id = (int)$_POST['id'];
    $findings = strip_tags(trim($_POST['observations']));
    $final_severity = $_POST['severity'];

    if (empty($findings)) {
        echo json_encode(['status' => 'error', 'message' => 'Please provide field observations.']);
        exit;
    }

    // Severity must be one of the technician's confirmed values - same list
    // api/technician/update_inspection.php enforces. Without this, the legacy
    // 'Low'/'High' values (still in the column's enum) or any other string
    // could be written.
    $allowed_severities = ['Minimal', 'Light', 'Moderate', 'Severe', 'Total Loss'];
    if (!in_array($final_severity, $allowed_severities, true)) {
        echo json_encode(['status' => 'error', 'message' => 'Please choose a valid severity level.']);
        exit;
    }

    $conn->begin_transaction();

    try {
        // 1. UPDATE THE DAMAGE REPORT. The Status = 'Validated' condition means a
        //    technician can only inspect a report that is currently assigned and
        //    awaiting inspection - not re-open one that has already been approved,
        //    rejected or inspected. (Corrections before approval go through
        //    update_inspection.php instead.)
        $query = "UPDATE damage_report
                  SET Description = CONCAT(IFNULL(Description, ''), '\n\n--- TECH FINDINGS ---\n', ?),
                      Damage_Severity = ?,
                      Status = 'Inspected',
                      Is_Inspected = 1
                  WHERE Damage_Report_ID = ? AND Technician_ID = ? AND Status = 'Validated'";

        $stmt = $conn->prepare($query);
        $stmt->bind_param("ssii", $findings, $final_severity, $report_id, $tech_id);
        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            // Distinguish "not yours" from "not in an inspectable state" so the
            // technician gets a message that actually explains it.
            $stmt->close();
            $check = $conn->prepare("SELECT Status FROM damage_report WHERE Damage_Report_ID = ? AND Technician_ID = ?");
            $check->bind_param("ii", $report_id, $tech_id);
            $check->execute();
            $row = $check->get_result()->fetch_assoc();
            $check->close();
            if (!$row) {
                throw new Exception("Report not found or not assigned to you.");
            }
            throw new Exception("This report is no longer awaiting your inspection (its status is now {$row['Status']}). Please refresh.");
        }
        $stmt->close();

        // 2. NEW: NOTIFY THE FARMER
        // First, get the Farmer_ID (and name, for the admin notification) for this report
        $getFarmer = $conn->prepare("SELECT dr.Farmer_ID, u.Fullname FROM damage_report dr JOIN users u ON dr.Farmer_ID = u.User_ID WHERE dr.Damage_Report_ID = ?");
        $getFarmer->bind_param("i", $report_id);
        $getFarmer->execute();
        $farmerRes = $getFarmer->get_result()->fetch_assoc();
        $farmer_id = $farmerRes['Farmer_ID'];
        $farmer_name = $farmerRes['Fullname'] ?? 'a farmer';
        $getFarmer->close();

        if ($farmer_id) {
            $notifMsg = "Field inspection completed for Report #REP-$report_id. Status updated to: Inspected.";
            $redirectUrl = 'index.html';
            $stmtNotif = $conn->prepare("INSERT INTO notification (Farmer_ID, Message, Redirect_URL, Is_Read) VALUES (?, ?, ?, 0)");
            $stmtNotif->bind_param("iss", $farmer_id, $notifMsg, $redirectUrl);
            $stmtNotif->execute();
            $stmtNotif->close();
        }

        // Notify Admin staff - report is now ready for review
        $admin_msg = "Report #REP-$report_id ({$farmer_name}) has been inspected and is ready for review.";
        $stmtAdminNotif = $conn->prepare("INSERT INTO admin_notification (Target_Role, Message, Related_Report_ID) VALUES ('Admin', ?, ?)");
        $stmtAdminNotif->bind_param("si", $admin_msg, $report_id);
        $stmtAdminNotif->execute();
        $stmtAdminNotif->close();

        // 3. PROCESS IMAGE UPLOADS
        $uploadDir = '../../uploads/evidence/'; 
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        if (isset($_FILES['evidencePhotos']) && !empty($_FILES['evidencePhotos']['name'][0])) {
            $fileCount = count($_FILES['evidencePhotos']['name']);
            $imgStmt = $conn->prepare("INSERT INTO damage_report_images (Damage_Report_ID, Image_Path, Uploaded_By_Role, Geo_Lat, Geo_Lng) VALUES (?, ?, 'Technician', ?, ?)");

            for ($i = 0; $i < $fileCount; $i++) {
                $tmpName = $_FILES['evidencePhotos']['tmp_name'][$i];
                $fileName = $_FILES['evidencePhotos']['name'][$i];
                $fileError = $_FILES['evidencePhotos']['error'][$i];

                if ($fileError === 0) {
                    $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    $allowedExtensions = ['jpg', 'jpeg', 'png'];

                    // Verify BOTH the extension AND that the file content is actually a real image
                    // (blocks a .php or other script renamed to look like a .jpg)
                    $isValidImage = in_array($fileExtension, $allowedExtensions) && (getimagesize($tmpName) !== false);

                    if ($isValidImage) {
                        $newFileName = uniqid('evd_' . $report_id . '_') . '.' . $fileExtension;
                        $destination = $uploadDir . $newFileName;

                        if (move_uploaded_file($tmpName, $destination)) {
                            $dbPath = 'uploads/evidence/' . $newFileName;
                            // $i is this loop's position over evidencePhotos[], which is
                            // the order the client appended them in - same convention
                            // submit_report.php uses for the farmer's photos.
                            $lat = !empty($_POST["geo_lat_$i"]) ? (float)$_POST["geo_lat_$i"] : null;
                            $lng = !empty($_POST["geo_lng_$i"]) ? (float)$_POST["geo_lng_$i"] : null;
                            $imgStmt->bind_param("isdd", $report_id, $dbPath, $lat, $lng);
                            $imgStmt->execute();
                        }
                    }
                }
            }
            $imgStmt->close();
        }

        $conn->commit();

        // Log the action
        require_once '../audit/log_action.php';
        logAction($conn, $tech_id, 'Technician',
            'Submit Inspection',
            "Field inspection submitted for Report #$report_id. Severity confirmed: $final_severity.",
            'Damage Reports'
        );

        echo json_encode(['status' => 'success', 'message' => 'Inspection submitted and Farmer notified!']);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Required fields missing.']);
}
?>