<?php
// File Location: api/masterfile/damage_report/update_report.php
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

    if (!empty($data->id) && !empty($data->crop_name) && !empty($data->damage_type)) {
        
        $report_id = (int)$data->id;
        $farmer_id = (int)$data->farmer_id; // Added Farmer_ID from your JS payload

        $conn->begin_transaction(); // Start transaction for safety

        // 1. Find the Crop_ID
        $findCrop = $conn->prepare("SELECT Crop_ID FROM damage_report WHERE Damage_Report_ID = ?");
        if (!$findCrop) throw new Exception("Select Prepare failed: " . $conn->error);
        
        $findCrop->bind_param("i", $report_id);
        $findCrop->execute();
        $findCrop->bind_result($crop_id);
        $fetched = $findCrop->fetch();
        $findCrop->close();
        
        if (!$fetched) { 
            echo json_encode(["status" => "error", "message" => "Original report not found in database."]); 
            exit; 
        }

        // 2. Format Crop Name
        $full_crop_name = trim($data->crop_name);
        if (!empty($data->crop_type)) {
            $full_crop_name .= " (" . trim($data->crop_type) . ")";
        }

        $area = !empty($data->area) ? (float)$data->area : 0.00;
        $planting_date = !empty($data->planting_date) ? $data->planting_date : null;

        // 3. Update Crop Table
        $queryCrop = "UPDATE crop SET Crop_Name = ?, Planting_Date = ?, Area_Hectares = ? WHERE Crop_ID = ?";
        $stmtCrop = $conn->prepare($queryCrop);
        if (!$stmtCrop) throw new Exception("Crop Update Error: " . $conn->error);
        
        $stmtCrop->bind_param("ssdi", $full_crop_name, $planting_date, $area, $crop_id);
        $stmtCrop->execute();
        $stmtCrop->close(); 

        // 4. Update Damage Report Table (Now includes Farmer_ID)
        $queryReport = "UPDATE damage_report SET Farmer_ID = ?, Damage_Type = ?, Damage_Severity = ?, Description = ? WHERE Damage_Report_ID = ?";
        $stmtReport = $conn->prepare($queryReport);
        if (!$stmtReport) throw new Exception("Report Update Error: " . $conn->error);
        
        $stmtReport->bind_param("isssi", $farmer_id, $data->damage_type, $data->severity, $data->description, $report_id);
        $stmtReport->execute();
        $stmtReport->close(); 

        $conn->commit(); // Save all changes

        echo json_encode(["status" => "success", "message" => "Damage Report updated successfully."]);

    } else {
        echo json_encode(["status" => "error", "message" => "Incomplete data. Crop name and damage type are required."]);
    }
    
} catch (Throwable $e) { 
    if (isset($conn)) $conn->rollback(); // Revert changes if an error occurs
    echo json_encode(["status" => "error", "message" => "System Error: " . $e->getMessage()]);
}
?>