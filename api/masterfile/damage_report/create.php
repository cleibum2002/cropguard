<?php
ini_set('display_errors', 0);
session_start();
header('Content-Type: application/json');

// Admin only: walk-in reports are filed by the Admin on a farmer's behalf.
if (!isset($_SESSION['User_ID']) || ($_SESSION['Role'] ?? '') !== 'Admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

try {
    include_once '../../config/db_connection.php'; 
    
    $raw_data = file_get_contents("php://input");
    $data = json_decode($raw_data);

    if (!$data) { 
        echo json_encode(["status" => "error", "message" => "No data received."]); 
        exit; 
    }

    if (!empty($data->farmer_id) && !empty($data->type_id) && !empty($data->damage_type)) {

        $type_id = (int)$data->type_id;

        // The walk-in now picks a registered crop, so the report links to a real
        // Standard_Production_Cost and its estimated relief can be computed -
        // free-typed crops had no Type_ID, so calculate_cost.php's join found
        // nothing and the estimate was always blank. Resolve the crop here and
        // fail clearly if it isn't a valid, active registered crop.
        $ctStmt = $conn->prepare("SELECT Crop_Name FROM crop_type WHERE Type_ID = ? AND Status = 'Active'");
        $ctStmt->bind_param("i", $type_id);
        $ctStmt->execute();
        $ct = $ctStmt->get_result()->fetch_assoc();
        $ctStmt->close();
        if (!$ct) {
            echo json_encode(["status" => "error", "message" => "Please choose a valid registered crop."]);
            exit;
        }
        // crop.Crop_Name is varchar(50) but crop_type.Crop_Name is varchar(100);
        // trim to fit the column it's being copied into.
        $full_crop_name = mb_substr($ct['Crop_Name'], 0, 50);

        $area = !empty($data->area) ? (float)$data->area : 0.00;
        // A walk-in report isn't tied to a registered farm, so there's no farm
        // size to compare against - just require a sensible positive number.
        if ($area <= 0) {
            echo json_encode(["status" => "error", "message" => "Affected area must be greater than 0."]);
            exit;
        }
        if ($area > 100000) {
            echo json_encode(["status" => "error", "message" => "Affected area looks too large - please check the value."]);
            exit;
        }
        $planting_date = !empty($data->planting_date) ? $data->planting_date : null;
        // A crop can't have been planted in the future (same rule as submit_report.php).
        if ($planting_date !== null) {
            $pd = DateTime::createFromFormat('Y-m-d', $planting_date);
            if (!$pd || $pd->format('Y-m-d') !== $planting_date) {
                echo json_encode(["status" => "error", "message" => "Please enter a valid planting date."]);
                exit;
            }
            if ($planting_date > date('Y-m-d')) {
                echo json_encode(["status" => "error", "message" => "The planting date cannot be in the future."]);
                exit;
            }
        }
        $farmer_id = (int)$data->farmer_id;

        // The walk-in dropdown lists farmers, but the request could name any id,
        // so verify server-side that this is a real, active registered farmer -
        // not an Admin/MAO/Technician, a deactivated account, or a ghost id.
        // And a walk-in must meet the same RSBSA requirement as a self-filed
        // report, so the identity check isn't bypassed by filing in person.
        $fStmt = $conn->prepare("SELECT STATUS, RSBA_No FROM users WHERE User_ID = ? AND Role = 'Farmer'");
        $fStmt->bind_param("i", $farmer_id);
        $fStmt->execute();
        $f = $fStmt->get_result()->fetch_assoc();
        $fStmt->close();
        if (!$f || $f['STATUS'] !== 'Active') {
            echo json_encode(["status" => "error", "message" => "Please choose a valid, active registered farmer."]);
            exit;
        }
        if (empty($f['RSBA_No']) || !preg_match('/^\d{2}-\d{2}-\d{2}-\d{3}-\d{6}$/', $f['RSBA_No'])) {
            echo json_encode(["status" => "error", "message" => "This farmer has no valid RSBSA number on file. Please set it in Farmer Management before filing a report for them."]);
            exit;
        }

        // Type_ID set so the crop links to crop_type - this is what makes the
        // estimated relief computable for a walk-in report.
        $queryCrop = "INSERT INTO crop (Crop_Name, Type_ID, Planting_Date, Area_Hectares, Farmer_ID) VALUES (?, ?, ?, ?, ?)";
        $stmtCrop = $conn->prepare($queryCrop);
        if (!$stmtCrop) throw new Exception("Crop Insert Error: " . $conn->error);

        $stmtCrop->bind_param("sisdi", $full_crop_name, $type_id, $planting_date, $area, $farmer_id);
        $stmtCrop->execute();
        $crop_id = $conn->insert_id;
        $stmtCrop->close();

        // Entered directly at 'Validated' status (skips the MAO review step a
        // farmer-submitted report would need) - the staff member entering it is
        // effectively the one validating it, so they're recorded as such.
        $validated_by = $_SESSION['User_ID'];
        $queryReport = "INSERT INTO damage_report (Farmer_ID, Crop_ID, Affected_Area, Damage_Type, Damage_Severity, Farmer_Reported_Severity, Description, Status, Validated_By) VALUES (?, ?, ?, ?, ?, ?, ?, 'Validated', ?)";
        $stmtReport = $conn->prepare($queryReport);
        if (!$stmtReport) throw new Exception("Report Insert Error: " . $conn->error);

        // Affected_Area is stored on the report itself (see submit_report.php).
        // Farmer_Reported_Severity permanently snapshots the original value — Damage_Severity
        // gets overwritten later once a technician inspects the farm.
        $stmtReport->bind_param("iidssssi", $farmer_id, $crop_id, $area, $data->damage_type, $data->severity, $data->severity, $data->description, $validated_by);
        
        if ($stmtReport->execute()) {
            $new_report_id = $conn->insert_id;
            require_once '../../audit/log_action.php';
            logAction($conn, $_SESSION['User_ID'], $_SESSION['Role'],
                'Submit Report',
                "Walk-in damage report #{$new_report_id} entered for farmer #{$farmer_id} ({$data->damage_type}, {$data->severity}, {$area} ha)",
                'Damage Reports'
            );
            echo json_encode(["status" => "success", "message" => "Damage Report successfully submitted."]);
        } else {
            echo json_encode(["status" => "error", "message" => "Failed to submit report."]);
        }
        $stmtReport->close();
    } else {
        echo json_encode(["status" => "error", "message" => "Incomplete data. Farmer, crop, and damage type are required."]);
    }
} catch (Throwable $e) { 
    echo json_encode(["status" => "error", "message" => safeErrorMessage($e)]);
}
?>