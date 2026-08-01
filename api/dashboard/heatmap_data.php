<?php
// api/dashboard/heatmap_data.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['User_ID']) || !in_array($_SESSION['Role'], ['Admin', 'MAO'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

require_once '../config/db_connection.php';

try {   
    // We only show reports that have been Inspected or Approved on the map
    // Also filter out invalid coordinates (Latitude must be between -90 and 90, Longitude between -180 and 180)
    $sql = "SELECT fl.Latitude, fl.Longitude, 
                SUM(CASE 
                    WHEN dr.Damage_Severity = 'Total Loss' THEN 1.0
                    WHEN dr.Damage_Severity = 'Severe' THEN 0.8
                    WHEN dr.Damage_Severity = 'High' THEN 0.8
                    WHEN dr.Damage_Severity = 'Moderate' THEN 0.5
                    WHEN dr.Damage_Severity = 'Light' THEN 0.35
                    WHEN dr.Damage_Severity = 'Minimal' THEN 0.2
                    WHEN dr.Damage_Severity = 'Low' THEN 0.2
                    ELSE 0.1 
                END) as Total_Intensity
            FROM damage_report dr
            JOIN farm_location fl ON dr.Location_ID = fl.Location_ID
            WHERE dr.Status NOT IN ('Rejected', 'Archived') 
            AND fl.Latitude IS NOT NULL
            AND fl.Latitude BETWEEN -90 AND 90
            AND fl.Longitude IS NOT NULL
            AND fl.Longitude BETWEEN -180 AND 180
            GROUP BY fl.Location_ID";

    $result = $conn->query($sql);
    $heatmap_data = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $heatmap_data[] = [
                (float)$row['Latitude'], 
                (float)$row['Longitude'], 
                (float)$row['Total_Intensity']
            ];
        }
    }
    echo json_encode(['status' => 'success', 'data' => $heatmap_data]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}