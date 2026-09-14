<?php
// File Location: api/technician/delete_evidence_image.php
//
// Deletes one technician-uploaded evidence photo. Deliberately narrow:
// - Only images the requesting technician themself uploaded (Uploaded_By_Role
//   = 'Technician' on a report actually assigned to them) can be removed - a
//   technician must never be able to delete a farmer's original evidence.
// - Only while the report is still 'Inspected', same edit window as
//   update_inspection.php - once Admin has approved/rejected, the evidence
//   that decision was based on is locked.
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php';

$tech_id = $_SESSION['User_ID'] ?? null;
if (!$tech_id || ($_SESSION['Role'] ?? '') !== 'Technician') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

$data = json_decode(file_get_contents("php://input"));
if (empty($data->image_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing image ID.']);
    exit;
}

$image_id = (int)$data->image_id;

try {
    $find = $conn->prepare(
        "SELECT i.Image_Path
         FROM damage_report_images i
         JOIN damage_report dr ON dr.Damage_Report_ID = i.Damage_Report_ID
         WHERE i.Image_ID = ? AND i.Uploaded_By_Role = 'Technician'
           AND dr.Technician_ID = ? AND dr.Status = 'Inspected'"
    );
    $find->bind_param("ii", $image_id, $tech_id);
    $find->execute();
    $row = $find->get_result()->fetch_assoc();
    $find->close();

    if (!$row) {
        throw new Exception("Photo not found, not yours, or this report can no longer be edited.");
    }

    $del = $conn->prepare("DELETE FROM damage_report_images WHERE Image_ID = ?");
    $del->bind_param("i", $image_id);
    $del->execute();
    $del->close();

    // Best-effort file cleanup - the DB row is the source of truth, so a
    // missing/already-gone file here must not fail the request.
    $fullPath = '../../' . $row['Image_Path'];
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }

    echo json_encode(['status' => 'success', 'message' => 'Photo removed.']);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}
?>
