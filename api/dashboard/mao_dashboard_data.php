<?php
// File Location: api/dashboard/mao_dashboard_data.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['User_ID']) || !in_array($_SESSION['Role'], ['Admin', 'MAO'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

require_once '../config/db_connection.php';

try {
    $response = ['status' => 'success', 'stats' => [], 'recent_activity' => []];

    // 1. Fetch Basic Damage Report Stats
    $statQuery = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN Status = 'Inspected' THEN 1 ELSE 0 END) as validated
                  FROM damage_report 
                  WHERE Status != 'Archived'";
    $statResult = $conn->query($statQuery)->fetch_assoc();

    // 2. Fetch Awaiting Distribution Count from aid_distribution
    $aidQuery = "SELECT COUNT(*) as awaiting FROM aid_distribution WHERE Status = 'Pending Distribution'";
    $aidResult = $conn->query($aidQuery)->fetch_assoc();

    // 3. Fetch Success Rate (Claimed vs Total Allocations)
    $successQuery = "SELECT 
                        COUNT(*) as total_aid,
                        SUM(CASE WHEN Status = 'Claimed' THEN 1 ELSE 0 END) as claimed
                      FROM aid_distribution";
    $successResult = $conn->query($successQuery)->fetch_assoc();
    $totalAid = $successResult['total_aid'] ?? 0;
    $claimed = $successResult['claimed'] ?? 0;
    $percent = ($totalAid > 0) ? round(($claimed / $totalAid) * 100) : 0;

    $response['stats'] = [
        'total' => $statResult['total'] ?? 0,
        'awaiting_dist' => $aidResult['awaiting'] ?? 0,
        'validated' => $statResult['validated'] ?? 0,
        'assistance_percent' => $percent . '%'
    ];

    // 4. Fetch Recent Activity (Joined with 'users' table)
    $activityQuery = "SELECT 
                        f.Fullname as farmer_name,
                        dr.Status as status
                      FROM damage_report dr
                      JOIN users f ON dr.Farmer_ID = f.User_ID
                      WHERE dr.Status != 'Archived'
                      ORDER BY dr.Damage_Report_ID DESC 
                      LIMIT 5";
    $activityResult = $conn->query($activityQuery);
    
    while ($row = $activityResult->fetch_assoc()) {
        $response['recent_activity'][] = $row;
    }

    echo json_encode($response);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>