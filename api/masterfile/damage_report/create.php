<?php
ini_set('display_errors', 0);
header('Content-Type: application/json');

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

        $area = !empty($data->area) ? (float)$data->area : 0.00;
        $planting_date = !empty($data->planting_date) ? $data->planting_date : null;
        $farmer_id = (int)$data->farmer_id;

        $queryCrop = "INSERT INTO crop (Crop_Name, Planting_Date, Area_Hectares, Farmer_ID) VALUES (?, ?, ?, ?)";
        $stmtCrop = $conn->prepare($queryCrop);
        if (!$stmtCrop) throw new Exception("Crop Insert Error: " . $conn->error);
        
        $stmtCrop->bind_param("ssdi", $full_crop_name, $planting_date, $area, $farmer_id);
        $stmtCrop->execute();
        $crop_id = $conn->insert_id;
        $stmtCrop->close();

        $queryReport = "INSERT INTO damage_report (Farmer_ID, Crop_ID, Damage_Type, Damage_Severity, Description, Status) VALUES (?, ?, ?, ?, ?, 'Pending')";
        $stmtReport = $conn->prepare($queryReport);
        if (!$stmtReport) throw new Exception("Report Insert Error: " . $conn->error);
        
        $stmtReport->bind_param("iisss", $farmer_id, $crop_id, $data->damage_type, $data->severity, $data->description);
        
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
    echo json_encode(["status" => "error", "message" => "System Error: " . $e->getMessage()]);
}
?>