<?php
// File Location: api/dashboard/get_report.php
// This script retrieves the details of a specific damage report for editing on the dashboard form.
ob_start();
session_start();
header('Content-Type: application/json');

try {
    require_once '../config/db_connection.php';

    $farmer_id = $_SESSION['user_id'] ?? $_SESSION['User_ID'] ?? $_SESSION['id'] ?? null;
    if (!$farmer_id) throw new Exception('Unauthorized. Please log in.');

    $report_id = $_GET['id'] ?? null;
    if (!$report_id) throw new Exception('Report ID is required.');

    // Join damage_report and crop tables so we can get all the details for the form
    $sql = "SELECT d.Damage_Report_ID, d.Location_ID, d.Crop_ID, d.Damage_Type, d.Damage_Severity, d.Description, 
                   c.Type_ID, c.Planting_Date, c.Area_Hectares 
            FROM damage_report d
            LEFT JOIN crop c ON d.Crop_ID = c.Crop_ID
            WHERE d.Damage_Report_ID = ? AND d.Farmer_ID = ?";
            
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $report_id, $farmer_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) throw new Exception('Report not found or you do not have permission to edit it.');

    $report = $result->fetch_assoc();

    // The edit modal needs {id, path} pairs so it can both display existing
    // photos and let the farmer remove a specific one (removeExistingPhoto()
    // sends this id back as removed_image_ids[] on save). This was missing
    // entirely before, which is why every Edit open showed zero saved photos
    // regardless of what was actually in the database.
    $imgStmt = $conn->prepare("SELECT Image_ID, Image_Path FROM damage_report_images WHERE Damage_Report_ID = ?");
    $imgStmt->bind_param("i", $report_id);
    $imgStmt->execute();
    $imgResult = $imgStmt->get_result();
    $images = [];
    while ($imgRow = $imgResult->fetch_assoc()) {
        $images[] = [
            'id'   => (int)$imgRow['Image_ID'],
            'path' => '../../' . $imgRow['Image_Path'],
        ];
    }
    $imgStmt->close();
    $report['images'] = $images;

    if (ob_get_length()) ob_clean();
    echo json_encode(['status' => 'success', 'data' => $report]);

} catch (Exception $e) {
    if (ob_get_length()) ob_clean();
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}
?>