<?php
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php';

if (!isset($_SESSION['User_ID'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

$report_id = isset($_GET['report_id']) ? (int)$_GET['report_id'] : 0;

if (!$report_id) {
    echo json_encode(['status' => 'error', 'message' => 'Missing report ID.']);
    exit;
}

$severity_multipliers = [
    'Minimal'    => 0.10,
    'Light'      => 0.30,
    'Low'        => 0.30,
    'Moderate'   => 0.50,
    'Severe'     => 0.80,
    'High'       => 0.80,
    'Total Loss' => 1.00,
];

try {
    $sql = "SELECT 
                dr.Damage_Report_ID,
                dr.Damage_Severity,
                dr.Damage_Type,
                c.Crop_Name,
                c.Area_Hectares,
                ct.Commodity_Type,
                ct.Standard_Production_Cost,
                ct.Growth_Duration,
                u.Fullname   as Farmer_Name,
                u.RSBA_No    as RSBSA_No
            FROM damage_report dr
            JOIN crop       c  ON dr.Crop_ID   = c.Crop_ID
            JOIN crop_type  ct ON c.Type_ID    = ct.Type_ID
            JOIN users      u  ON dr.Farmer_ID = u.User_ID
            WHERE dr.Damage_Report_ID = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $report_id);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$data) {
        echo json_encode(['status' => 'error', 'message' => 'Report not found.']);
        exit;
    }

    $severity            = $data['Damage_Severity'] ?? 'Minimal';
    $multiplier          = $severity_multipliers[$severity] ?? 0.10;
    $area                = (float)($data['Area_Hectares'] ?? 0);
    $cost_per_ha         = (float)($data['Standard_Production_Cost'] ?? 0);
    $total_production    = $area * $cost_per_ha;
    $estimated_loss      = $total_production * $multiplier;

    echo json_encode([
        'status'                 => 'success',
        'farmer_name'            => $data['Farmer_Name'],
        'rsbsa_no'               => $data['RSBSA_No'] ?? 'Not assigned',
        'crop_name'              => $data['Crop_Name'],
        'crop_type'              => $data['Commodity_Type'],
        'area_hectares'          => $area,
        'damage_type'            => $data['Damage_Type'],
        'damage_severity'        => $severity,
        'severity_multiplier'    => ($multiplier * 100) . '%',
        'standard_cost_per_ha'   => number_format($cost_per_ha, 2),
        'total_production_value' => number_format($total_production, 2),
        'estimated_loss'         => number_format($estimated_loss, 2),
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>