<?php
// File Location: api/masterfile/damage_report/get_all.php
ini_set('display_errors', 0);
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['User_ID']) || !in_array($_SESSION['Role'], ['Admin', 'MAO'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

try {
    include_once '../../config/db_connection.php'; // Adjust path if needed

    // MAO's version of this same file always excludes Archived (MAO has no
    // archive view at all). Admin's damage_reports.html, on the other hand,
    // has a real "View Archive" toggle and an "Archived" status filter option
    // — both of which need this endpoint to actually return archived rows
    // when asked. Previously the WHERE clause was hardcoded and ignored the
    // request entirely, so both features silently always returned nothing.
    $showArchived = isset($_GET['view']) && $_GET['view'] === 'archived';
    $statusCondition = $showArchived ? "dr.Status = 'Archived'" : "dr.Status != 'Archived'";

    // Notice we now JOIN the 'users' table twice! Once for the Farmer, once for the Tech.
    $query = "
        SELECT 
            dr.Damage_Report_ID as id, 
            dr.Farmer_ID as farmer_id,
            f.Fullname as farmer, 
            c.Crop_Name as crop_name, 
            c.Planting_Date as planting_date,
            COALESCE(dr.Inspected_Area, dr.Affected_Area, c.Area_Hectares) as area,
            DATE(dr.Report_Date) as date,
            dr.Damage_Type as damage_type, 
            dr.Damage_Severity as severity, 
            dr.Status as status,
            dr.Description as description,
            t.Fullname as technician_name,
            v.Fullname as validated_by_name,
            ap.Fullname as approved_by_name,
            fl.Barangay as barangay_raw
        FROM damage_report dr
        LEFT JOIN users f ON dr.Farmer_ID = f.User_ID AND f.Role = 'Farmer'
        LEFT JOIN crop c ON dr.Crop_ID = c.Crop_ID
        LEFT JOIN users t ON dr.Technician_ID = t.User_ID AND t.Role = 'Technician'
        LEFT JOIN users v ON dr.Validated_By = v.User_ID
        LEFT JOIN users ap ON dr.Approved_By = ap.User_ID
        LEFT JOIN farm_location fl ON dr.Location_ID = fl.Location_ID
        WHERE $statusCondition
        ORDER BY dr.Last_Updated DESC, dr.Damage_Report_ID DESC
    "; // Sorted by Last_Updated (bumped automatically by MySQL's ON UPDATE
       // CURRENT_TIMESTAMP whenever any column on the row changes), not by ID,
       // so an old report that finally gets touched - MAO validates it,
       // technician sends it back, admin approves/rejects it - moves to the
       // top instead of staying stuck wherever it originally slotted in.
       // ID is only a tie-breaker for rows that share the same timestamp.
    
    $result = $conn->query($query);
    if (!$result) throw new Exception($conn->error);

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    echo json_encode(["status" => "success", "data" => $data]);
} catch (Throwable $e) {
    echo json_encode(["status" => "error", "message" => safeErrorMessage($e)]);
}
?>