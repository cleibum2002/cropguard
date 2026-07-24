<?php
// File Location: api/dashboard/barangay_heatmap_data.php
// Purpose: Feeds the Region View (barangay-colored) map on the MAO dashboard.
// Unlike heatmap_data.php, this endpoint:
//   1. Requires an MAO/Admin login (the original heatmap_data.php had no auth check at all)
//   2. Includes 'Pending' reports so MAO sees a location as soon as the farmer submits it
//   3. Returns one row per report (not pre-summed) - grouping by barangay is done in the
//      browser using the boundary shapes in assets/geo/balingasag_barangays.json
ob_start();
session_start();
header('Content-Type: application/json');

// Role check - only MAO and Admin should see this
if (!isset($_SESSION['User_ID']) || !in_array($_SESSION['Role'], ['MAO', 'Admin'])) {
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

require_once '../config/db_connection.php';

try {
    // Show every report that isn't Rejected/Archived, including Pending ones,
    // so MAO sees the pin the moment a farmer submits (this was the original ask).
    // Latitude/Longitude bounds check filters out invalid placeholder coordinates
    // (e.g. Latitude > 90 or Longitude > 180, which cannot be real GPS values).
    $sql = "SELECT
                dr.Damage_Report_ID,
                dr.Damage_Type,
                dr.Damage_Severity,
                dr.Status,
                dr.Report_Date,
                u.Fullname   AS Farmer_Name,
                fl.Barangay  AS Reported_Barangay_Text,
                fl.Latitude,
                fl.Longitude
            FROM damage_report dr
            JOIN farm_location fl ON dr.Location_ID = fl.Location_ID
            LEFT JOIN users u     ON dr.Farmer_ID    = u.User_ID
            WHERE dr.Status NOT IN ('Rejected', 'Archived')
              AND fl.Latitude  IS NOT NULL AND fl.Latitude  BETWEEN -90  AND 90
              AND fl.Longitude IS NOT NULL AND fl.Longitude BETWEEN -180 AND 180";

    $result = $conn->query($sql);
    if (!$result) throw new Exception("Query failed: " . $conn->error);

    // Severity -> intensity (0 to 1). Covers BOTH vocabularies currently used in the
    // system: the Farmer form (Low/Moderate/High/Total Loss) and the Encoder form
    // (Minimal/Light/Moderate/Severe/Total Loss). Equivalent tiers get the same weight.
    $severity_weight = [
        'Minimal'    => 0.2,
        'Low'        => 0.2,
        'Light'      => 0.35,
        'Moderate'   => 0.5,
        'Severe'     => 0.8,
        'High'       => 0.8,
        'Total Loss' => 1.0,
    ];

    $points = [];
    while ($row = $result->fetch_assoc()) {
        $severity = $row['Damage_Severity'];
        $points[] = [
            'report_id'   => (int)$row['Damage_Report_ID'],
            'lat'         => (float)$row['Latitude'],
            'lng'         => (float)$row['Longitude'],
            'severity'    => $severity,
            'intensity'   => $severity_weight[$severity] ?? 0.1,
            'status'      => $row['Status'],
            'damage_type' => $row['Damage_Type'],
            'farmer_name' => $row['Farmer_Name'],
            'report_date' => $row['Report_Date'],
        ];
    }

    if (ob_get_length()) ob_clean();
    echo json_encode(['status' => 'success', 'points' => $points]);

} catch (Exception $e) {
    if (ob_get_length()) ob_clean();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}