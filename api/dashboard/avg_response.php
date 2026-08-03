<?php
// File Location: api/dashboard/avg_response.php
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php';

if (!isset($_SESSION['User_ID']) || $_SESSION['Role'] !== 'Admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

$period = $_GET['period'] ?? 'daily';

try {
    if ($period === 'daily') {
        $sql = "SELECT 
                    DATE(Report_Date) as label,
                    ROUND(AVG(
                        DATEDIFF(NOW(), Report_Date)
                    ), 1) as avg_days
                FROM damage_report
                WHERE Status IN ('Inspected','Approved')
                AND Report_Date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY DATE(Report_Date)
                ORDER BY DATE(Report_Date) ASC";

    } elseif ($period === 'monthly') {
        $sql = "SELECT 
                    DATE_FORMAT(Report_Date, '%b %Y') as label,
                    ROUND(AVG(
                        DATEDIFF(NOW(), Report_Date)
                    ), 1) as avg_days
                FROM damage_report
                WHERE Status IN ('Inspected','Approved')
                AND Report_Date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                GROUP BY DATE_FORMAT(Report_Date, '%Y-%m')
                ORDER BY MIN(Report_Date) ASC";

    } else {
        $sql = "SELECT 
                    YEAR(Report_Date) as label,
                    ROUND(AVG(
                        DATEDIFF(NOW(), Report_Date)
                    ), 1) as avg_days
                FROM damage_report
                WHERE Status IN ('Inspected','Approved')
                GROUP BY YEAR(Report_Date)
                ORDER BY YEAR(Report_Date) ASC";
    }

    $result = $conn->query($sql);
    if (!$result) throw new Exception($conn->error);

    $labels = [];
    $values = [];
    while ($row = $result->fetch_assoc()) {
        $labels[] = $row['label'];
        $values[] = (float)($row['avg_days'] ?? 0);
    }

    echo json_encode(['status' => 'success', 'labels' => $labels, 'values' => $values]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}
?>