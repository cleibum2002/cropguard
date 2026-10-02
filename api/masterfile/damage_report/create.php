<?php
ini_set('display_errors', 0);
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['User_ID']) || !in_array($_SESSION['Role'], ['Admin', 'MAO'])) {
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

    if (!empty($data->farmer_id) && !empty($data->crop_name) && !empty($data->damage_type)) {
        
        $full_crop_name = trim($data->crop_name);
        if (!empty($data->crop_type)) {
            $full_crop_name .= " (" . trim($data->crop_type) . ")";
        }
        // crop.Crop_Name is varchar(50) - reject an over-long combination with a
        // clear message instead of a generic DB error or a silent truncation.
        if (mb_strlen($full_crop_name) > 50) {
            echo json_encode(["status" => "error", "message" => "The crop name and type are too long together (maximum 50 characters). Please shorten them."]);
            exit;
        }

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
        $farmer_id = (int)$data->farmer_id;

        $queryCrop = "INSERT INTO crop (Crop_Name, Planting_Date, Area_Hectares, Farmer_ID) VALUES (?, ?, ?, ?)";
        $stmtCrop = $conn->prepare($queryCrop);
        if (!$stmtCrop) throw new Exception("Crop Insert Error: " . $conn->error);
        
        $stmtCrop->bind_param("ssdi", $full_crop_name, $planting_date, $area, $farmer_id);
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
            echo json_encode(["status" => "success", "message" => "Damage Report successfully submitted."]);
        } else {
            echo json_encode(["status" => "error", "message" => "Failed to submit report."]);
        }
        $stmtReport->close();
    } else {
        echo json_encode(["status" => "error", "message" => "Incomplete data. Farmer, Crop name, and damage type required."]);
    }
} catch (Throwable $e) { 
    echo json_encode(["status" => "error", "message" => safeErrorMessage($e)]);
}
?>