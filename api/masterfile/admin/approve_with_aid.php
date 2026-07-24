<?php
// File Location: api/admin/approve_with_aid.php
session_start();
header('Content-Type: application/json');
require_once '../../config/db_connection.php'; 

$admin_id = $_SESSION['User_ID'] ?? 1;
$input = json_decode(file_get_contents("php://input"), true);

if (isset($input['report_id'], $input['category'], $input['quantity'], $input['unit'])) {
    $report_id = (int)$input['report_id'];
    $cat = $input['category'];
    $qty = (float)$input['quantity'];
    $unit = $input['unit'];

    $conn->begin_transaction();
    
    try {
        // 1. Fetch the Farmer_ID from the report
        $stmtInfo = $conn->prepare("SELECT Farmer_ID FROM damage_report WHERE Damage_Report_ID = ?");
        $stmtInfo->bind_param("i", $report_id);
        $stmtInfo->execute();
        $res = $stmtInfo->get_result()->fetch_assoc();
        $farmer_id = $res['Farmer_ID'] ?? null;
        $stmtInfo->close();

        if (!$farmer_id) throw new Exception("Farmer not found for this report.");

        // 2. Update Report Status
        $stmt1 = $conn->prepare("UPDATE damage_report SET Status = 'Approved' WHERE Damage_Report_ID = ?");
        $stmt1->bind_param("i", $report_id);
        $stmt1->execute();

        // 3. Insert into Aid Distribution (Now including Farmer_ID)
        $stmt2 = $conn->prepare("INSERT INTO aid_distribution (Damage_Report_ID, Farmer_ID, Category, Quantity, Unit, Distributed_By, Status) VALUES (?, ?, ?, ?, ?, ?, 'Pending Distribution')");
        $stmt2->bind_param("iisdsi", $report_id, $farmer_id, $cat, $qty, $unit, $admin_id); 
        $stmt2->execute();

        // 4. Create the Notification
        $msg = "Success! Your report #REP-$report_id is approved. $qty $unit of $cat is ready for pickup.";
        $stmtNotif = $conn->prepare("INSERT INTO notification (Farmer_ID, Message, Is_Read) VALUES (?, ?, 0)");
        $stmtNotif->bind_param("is", $farmer_id, $msg);
        $stmtNotif->execute();

        $conn->commit();
        echo json_encode(['status' => 'success', 'message' => 'Approved and Farmer Notified!']);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => 'Error: ' . $e->getMessage()]);
    }
}
?>