<?php
// File Location: api/dashboard/submit_report.php
ob_start(); 
session_start();
header('Content-Type: application/json');

try {
    require_once '../config/db_connection.php';

    $farmer_id = $_SESSION['user_id'] ?? $_SESSION['User_ID'] ?? $_SESSION['id'] ?? null;
    if (!$farmer_id) throw new Exception('Session expired. Please login again.');

    if (empty($_POST['damage_type']) || empty($_POST['severity'])) {
        if (ob_get_length()) ob_clean();
        echo json_encode(['status' => 'error', 'message' => 'Damage type and severity are required.']);
        exit;
    }

    $conn->begin_transaction();
    
    // Sanitize incoming text data
    $report_id     = !empty($_POST['id']) ? (int)$_POST['id'] : null;
    $location_id   = !empty($_POST['location_id']) ? (int)$_POST['location_id'] : null;
    $crop_id       = !empty($_POST['crop_id']) ? (int)$_POST['crop_id'] : null; 
    $type_id       = !empty($_POST['type_id']) ? (int)$_POST['type_id'] : null;
    $planting_date = !empty($_POST['planting_date']) ? $_POST['planting_date'] : null;
    $area          = !empty($_POST['area']) ? (float)$_POST['area'] : 0.00;
    $damage_type   = $_POST['damage_type'];
    $severity      = $_POST['severity'];
    $description   = $_POST['description'] ?? '';

    // Geotagging data
    $geo_lat = !empty($_POST['geo_lat']) ? (float)$_POST['geo_lat'] : null;
    $geo_lng = !empty($_POST['geo_lng']) ? (float)$_POST['geo_lng'] : null;

    if ($location_id && $geo_lat && $geo_lng) {
        $sql_geo = "UPDATE farm_location SET Latitude = ?, Longitude = ? WHERE Location_ID = ?";
        $stmt_geo = $conn->prepare($sql_geo);
        $stmt_geo->bind_param("ddi", $geo_lat, $geo_lng, $location_id);
        $stmt_geo->execute();
    }
    
    // Update Crop Details
    if ($crop_id) {
        $sql_crop = "UPDATE crop SET Type_ID = ?, Planting_Date = ?, Area_Hectares = ? WHERE Crop_ID = ?";
        $stmt_crop = $conn->prepare($sql_crop);
        $stmt_crop->bind_param("isdi", $type_id, $planting_date, $area, $crop_id);
        $stmt_crop->execute();
    }

    // 4. INSERT OR UPDATE (FIXED: Added Type_ID to INSERT)
    if ($report_id) {
        $sql_report = "UPDATE damage_report 
                       SET Location_ID = ?, Crop_ID = ?, Damage_Type = ?, Damage_Severity = ?, Description = ? 
                       WHERE Damage_Report_ID = ? AND Farmer_ID = ?";
        $stmt_report = $conn->prepare($sql_report);
        $stmt_report->bind_param("iisssii", $location_id, $crop_id, $damage_type, $severity, $description, $report_id, $farmer_id);
        $stmt_report->execute();
        $success_message = 'Report successfully updated!';
    } else {
        // FIXED: Included Type_ID here so it isn't empty
        $sql_report = "INSERT INTO damage_report (Farmer_ID, Location_ID, Crop_ID, Damage_Type, Damage_Severity, Description, Status) 
                       VALUES (?, ?, ?, ?, ?, ?, 'Pending')";
        $stmt_report = $conn->prepare($sql_report);
        $stmt_report->bind_param("iiisss", $farmer_id, $location_id, $crop_id, $damage_type, $severity, $description);
        
        if (!$stmt_report->execute()) throw new Exception("Insert failed: " . $stmt_report->error);
        $report_id = $conn->insert_id;
        $success_message = 'Report submitted successfully!';

        // Notify Admin staff of the new report
        $farmer_name = $_SESSION['Fullname'] ?? 'A farmer';
        $admin_msg = "New damage report (#REP-{$report_id}) submitted by {$farmer_name} — awaiting review.";
        $stmt_admin_notif = $conn->prepare("INSERT INTO admin_notification (Target_Role, Message, Related_Report_ID) VALUES ('Admin', ?, ?)");
        $stmt_admin_notif->bind_param("si", $admin_msg, $report_id);
        $stmt_admin_notif->execute();
        $stmt_admin_notif->close();

        // Notify MAO staff too — they're the ones who validate Pending reports
        $mao_msg = "New damage report (#REP-{$report_id}) submitted by {$farmer_name} — needs your validation.";
        $mao_redirect = 'report_management.html';
        $stmt_mao_notif = $conn->prepare("INSERT INTO admin_notification (Target_Role, Message, Related_Report_ID, Redirect_URL) VALUES ('MAO', ?, ?, ?)");
        $stmt_mao_notif->bind_param("sis", $mao_msg, $report_id, $mao_redirect);
        $stmt_mao_notif->execute();
        $stmt_mao_notif->close();
    }

    // 5. PROCESS IMAGE UPLOADS
    if (isset($_FILES['images']) && is_array($_FILES['images']['name'])) {
        $upload_dir = '../../uploads/reports/'; 
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

        for ($i = 0; $i < count($_FILES['images']['name']); $i++) {
            if ($_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
                $file_ext = strtolower(pathinfo($_FILES['images']['name'][$i], PATHINFO_EXTENSION));
                if (in_array($file_ext, ['jpg', 'jpeg', 'png'])) {
                    $new_file_name = 'rep_' . $report_id . '_' . uniqid() . '.' . $file_ext;
                    if (move_uploaded_file($_FILES['images']['tmp_name'][$i], $upload_dir . $new_file_name)) {
                        $db_path = 'uploads/reports/' . $new_file_name;
                        $lat = !empty($_POST["geo_lat_$i"]) ? (float)$_POST["geo_lat_$i"] : null;
                        $lng = !empty($_POST["geo_lng_$i"]) ? (float)$_POST["geo_lng_$i"] : null;
                        
                        $stmt_img = $conn->prepare("INSERT INTO damage_report_images (Damage_Report_ID, Image_Path, geo_lat, geo_lng) VALUES (?, ?, ?, ?)");
                        $stmt_img->bind_param("isdd", $report_id, $db_path, $lat, $lng);
                        $stmt_img->execute();
                    }
                }
            }
        }
    }

    $conn->commit();
    echo json_encode(['status' => 'success', 'message' => $success_message]);

} catch (Exception $e) {
    if (isset($conn)) $conn->rollback();
    if (ob_get_length()) ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'System Error: ' . $e->getMessage()]);
}
?>