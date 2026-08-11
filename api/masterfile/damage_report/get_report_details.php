<?php
// File Location: api/masterfile/damage_report/get_report_details.php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['User_ID']) || !in_array($_SESSION['Role'], ['Admin', 'MAO', 'Technician'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

require_once '../../config/db_connection.php'; 

if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    try {
        // Query to get report and farm details
        $query = "SELECT dr.*, 
                         u.Fullname AS FarmerName, u.RSBA_No,
                         fl.Barangay, fl.Area_Size AS Total_Farm_Area, fl.Land_Type,
                         c.Crop_Name, c.Area_Hectares AS Affected_Area, c.Planting_Date, c.Expected_Harvest
                  FROM damage_report dr
                  LEFT JOIN users u ON dr.Farmer_ID = u.User_ID
                  LEFT JOIN farm_location fl ON dr.Location_ID = fl.Location_ID
                  LEFT JOIN crop c ON dr.Crop_ID = c.Crop_ID
                  WHERE dr.Damage_Report_ID = $id";
                  
        $result = $conn->query($query);
        
        if (!$result) {
            echo json_encode(['status' => 'error', 'message' => 'DB Error on Report: ' . $conn->error]);
            exit;
        }
        
        $report = $result->fetch_assoc();
        
        if ($report) {
            // Fetch the images INCLUDING geotag data
            // UPDATED: Selecting geo_lat and geo_lng
            $imgQuery = "SELECT Image_Path, geo_lat, geo_lng, Uploaded_By_Role FROM damage_report_images WHERE Damage_Report_ID = $id";
            $imgResult = $conn->query($imgQuery);
            
            $images = [];
            if ($imgResult) {
                while ($row = $imgResult->fetch_assoc()) {
                    // Returning as an object so the frontend can access path, lat, lng, and who uploaded it
                    $images[] = [
                        'path'        => '../../' . $row['Image_Path'],
                        'geo_lat'     => $row['geo_lat'],
                        'geo_lng'     => $row['geo_lng'],
                        'uploaded_by' => $row['Uploaded_By_Role'] ?? 'Farmer'
                    ];
                }
            } else {
                $report['Description'] .= "\n\n[WARNING: Image fetch error: " . $conn->error . "]";
            }
            
            echo json_encode(['status' => 'success', 'report' => $report, 'images' => $images]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Report not found.']);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'No ID provided.']);
}
?>