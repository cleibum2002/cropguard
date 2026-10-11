<?php
// File Location: api/technician/inspection_rules.php
// Rules shared by submit_validation.php (first inspection) and
// update_inspection.php (editing it before the Admin decides), so the two can
// never disagree.

// Same four levels the farmer chooses from (views/farmers/index.html), so the
// farmer's report and the technician's finding can be compared directly.
// calculate_cost.php: Low 30%, Moderate 50%, High 80%, Total Loss 100%.
const TECH_SEVERITIES = ['Low', 'Moderate', 'High', 'Total Loss'];

// The damaged area the technician measured on site, in hectares.
// Returns [area, null] when valid, or [null, message] when not.
// It must be more than 0 and no larger than the registered farm (when the
// report is tied to a farm; walk-in reports filed by the Admin may not be).
function checkDamagedArea($conn, int $reportId, $raw) {
    $raw = trim((string)$raw);
    if ($raw === '' || !is_numeric($raw) || (float)$raw <= 0) {
        return [null, 'Please enter the damaged area in hectares (more than 0).'];
    }
    $area = round((float)$raw, 2);

    $st = $conn->prepare("SELECT fl.Area_Size FROM damage_report dr LEFT JOIN farm_location fl ON fl.Location_ID = dr.Location_ID WHERE dr.Damage_Report_ID = ?");
    $st->bind_param("i", $reportId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if ($row && $row['Area_Size'] !== null && $area > (float)$row['Area_Size']) {
        return [null, 'The damaged area cannot be larger than the farm (' . (float)$row['Area_Size'] . ' ha).'];
    }
    if ($area > 100000) {
        return [null, 'Please enter a realistic damaged area in hectares.'];
    }
    return [$area, null];
}
?>
