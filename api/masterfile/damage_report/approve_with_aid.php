<?php
// File Location: api/admin/approve_with_aid.php
session_start();
header('Content-Type: application/json');

require_once '../../config/db_connection.php'; 

$admin_id = $_SESSION['User_ID'] ?? null;
if (!$admin_id) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);

if (isset($input['report_id'], $input['category'], $input['quantity'], $input['unit'])) {
    $report_id = (int)$input['report_id'];
    $cat = $input['category'];
    $qty = (float)$input['quantity'];
    $unit = $input['unit'];

    $conn->begin_transaction();
    
    try {
        // STEP 1: Get the Farmer_ID and Crop Name
        // We need the Farmer_ID for both the aid_distribution table and the notification
        $stmtInfo = $conn->prepare("SELECT dr.Farmer_ID, c.Crop_Name 
                                   FROM damage_report dr 
                                   LEFT JOIN crop c ON dr.Crop_ID = c.Crop_ID 
                                   WHERE dr.Damage_Report_ID = ?");
        $stmtInfo->bind_param("i", $report_id);
        $stmtInfo->execute();
        $info = $stmtInfo->get_result()->fetch_assoc();
        
        if (!$info) throw new Exception("Damage report not found.");
        
        $farmer_id = $info['Farmer_ID'];
        $crop_name = $info['Crop_Name'] ?? "Crop";
        $stmtInfo->close();

        // STEP 2: Update Damage Report Status to 'Approved'
        $stmt1 = $conn->prepare("UPDATE damage_report SET Status = 'Approved' WHERE Damage_Report_ID = ?");
        $stmt1->bind_param("i", $report_id);
        $stmt1->execute();

        // STEP 3: Create the Aid Distribution record 
        // Added Farmer_ID to the insert to match your DB schema
        $stmt2 = $conn->prepare("INSERT INTO aid_distribution (Damage_Report_ID, Farmer_ID, Category, Quantity, Unit, Distributed_By, Status) VALUES (?, ?, ?, ?, ?, ?, 'Pending Distribution')");
        $stmt2->bind_param("iisdsi", $report_id, $farmer_id, $cat, $qty, $unit, $admin_id); 
        $stmt2->execute();

        // STEP 4: Create the Farmer Notification
        $msg = "Good news! Your report for $crop_name (#REP-$report_id) has been approved. You can now claim $qty $unit of $cat at the MAO office.";
        $stmtNotif = $conn->prepare("INSERT INTO notification (Farmer_ID, Message, Is_Read) VALUES (?, ?, 0)");
        $stmtNotif->bind_param("is", $farmer_id, $msg);
        $stmtNotif->execute();

        // Commit all changes
        $conn->commit();

         // Log the action
        require_once '../../audit/log_action.php';
        logAction($conn, $admin_id, 'Admin',
            'Approve Report',
            "Report #$report_id approved. Aid: $qty $unit of $cat allocated to farmer #$farmer_id",
            'Damage Reports'
        );
        
        // Wipe output buffer to ensure clean JSON
        if (ob_get_length()) ob_clean();
        echo json_encode(['status' => 'success', 'message' => 'Report approved, aid allocated, and farmer notified.']);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    }
    
} else {
    echo json_encode(['status' => 'error', 'message' => 'Missing required aid allocation fields.']);
}
?>