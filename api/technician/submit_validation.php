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
    $findings = trim($_POST['observations']);
    $final_severity = $_POST['severity'];

    if (empty($findings)) {
        echo json_encode(['status' => 'error', 'message' => 'Please provide field observations.']);
        exit;
    }

    $conn->begin_transaction();

    try {
        // 1. UPDATE THE DAMAGE REPORT
        $query = "UPDATE damage_report 
                  SET Description = CONCAT(IFNULL(Description, ''), '\n\n--- TECH FINDINGS ---\n', ?), 
                      Damage_Severity = ?, 
                      Status = 'Inspected' 
                  WHERE Damage_Report_ID = ? AND Technician_ID = ?";
                
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ssii", $findings, $final_severity, $report_id, $tech_id);
        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            $check = $conn->query("SELECT * FROM damage_report WHERE Damage_Report_ID = $report_id AND Technician_ID = $tech_id");
            if($check->num_rows === 0) {
                throw new Exception("Report not found or not assigned to you.");
            }
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
            $stmtNotif = $conn->prepare("INSERT INTO notification (Farmer_ID, Message, Is_Read) VALUES (?, ?, 0)");
            $stmtNotif->bind_param("is", $farmer_id, $notifMsg);
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
            $imgStmt = $conn->prepare("INSERT INTO damage_report_images (Damage_Report_ID, Image_Path, Uploaded_By_Role) VALUES (?, ?, 'Technician')");
            
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
                            $imgStmt->bind_param("is", $report_id, $dbPath);
                            $imgStmt->execute();
                        }
                    }
                }
            }
            $imgStmt->close();
        }

        $conn->commit();
        echo json_encode(['status' => 'success', 'message' => 'Inspection submitted and Farmer notified!']);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Required fields missing.']);
}
?>