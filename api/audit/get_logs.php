<?php
// File Location: api/audit/get_logs.php
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php';

if (!isset($_SESSION['User_ID']) || $_SESSION['Role'] !== 'Admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

try {
    // Pagination
    $page     = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $per_page = isset($_GET['per_page']) ? max(1, min(100, (int)$_GET['per_page'])) : 15;
    $offset   = ($page - 1) * $per_page;

    // Filters — all optional, combined with AND
    $conditions = [];

    if (!empty($_GET['module'])) {
        $module = $conn->real_escape_string($_GET['module']);
        $conditions[] = "sl.Module = '$module'";
    }

    if (!empty($_GET['role'])) {
        $role = $conn->real_escape_string($_GET['role']);
        $conditions[] = "sl.User_Role = '$role'";
    }

    if (!empty($_GET['date_from'])) {
        $dateFrom = $conn->real_escape_string($_GET['date_from']);
        $conditions[] = "DATE(sl.Created_At) >= '$dateFrom'";
    }

    if (!empty($_GET['date_to'])) {
        $dateTo = $conn->real_escape_string($_GET['date_to']);
        $conditions[] = "DATE(sl.Created_At) <= '$dateTo'";
    }

    // Free-text search — matches against user name, description, action, or IP address.
    // Each word must match somewhere (in any order), so "angel login" finds Angel's logins.
    if (!empty($_GET['search'])) {
        foreach (preg_split('/\s+/', trim($_GET['search'])) as $word) {
            if ($word === '') continue;
            $w = $conn->real_escape_string($word);
            $conditions[] = "(u.Fullname LIKE '%$w%' OR sl.Description LIKE '%$w%' OR sl.Action LIKE '%$w%' OR sl.IP_Address LIKE '%$w%' OR sl.User_Role LIKE '%$w%' OR sl.Module LIKE '%$w%')";
        }
    }

    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    // Total count for this filter set (used for pagination AND for the stat cards,
    // so the cards reflect the whole filtered result set, not just the current page)
    $countQuery = "SELECT COUNT(*) as cnt FROM system_logs sl LEFT JOIN users u ON sl.User_ID = u.User_ID $where";
    $countResult = $conn->query($countQuery);
    if (!$countResult) throw new Exception($conn->error);
    $total_count = (int)$countResult->fetch_assoc()['cnt'];
    $total_pages = max(1, ceil($total_count / $per_page));

    // Stat cards — computed across the FULL filtered set, not just this page
    $statsQuery = "SELECT
                      SUM(CASE WHEN sl.Action = 'Login' AND DATE(sl.Created_At) = CURDATE() THEN 1 ELSE 0 END) as logins_today,
                      SUM(CASE WHEN sl.Action IN ('Submit Report', 'Edit Report') THEN 1 ELSE 0 END) as reports_submitted,
                      SUM(CASE WHEN sl.Action IN ('Mark as Claimed', 'Approve Report') THEN 1 ELSE 0 END) as aid_actions
                    FROM system_logs sl
                    LEFT JOIN users u ON sl.User_ID = u.User_ID
                    $where";
    $statsResult = $conn->query($statsQuery);
    if (!$statsResult) throw new Exception($conn->error);
    $statsRow = $statsResult->fetch_assoc();

    // The actual page of rows to display
    $query = "SELECT 
                sl.Log_ID,
                sl.Action,
                sl.Description,
                sl.Module,
                sl.IP_Address,
                sl.Created_At,
                u.Fullname   as user_name,
                sl.User_Role as user_role
              FROM system_logs sl
              LEFT JOIN users u ON sl.User_ID = u.User_ID
              $where
              ORDER BY sl.Created_At DESC
              LIMIT $per_page OFFSET $offset";

    $result = $conn->query($query);
    if (!$result) throw new Exception($conn->error);

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    echo json_encode([
        'status'      => 'success',
        'data'        => $data,
        'page'        => $page,
        'per_page'    => $per_page,
        'total_count' => $total_count,
        'total_pages' => $total_pages,
        'stats'       => [
            'total_actions'     => $total_count,
            'logins_today'      => (int)($statsRow['logins_today'] ?? 0),
            'reports_submitted' => (int)($statsRow['reports_submitted'] ?? 0),
            'aid_actions'       => (int)($statsRow['aid_actions'] ?? 0)
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}
?>