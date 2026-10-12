<?php
// File Location: api/admin/approve_with_aid.php
session_start();
header('Content-Type: application/json');

require_once '../../config/db_connection.php';

// Role check was missing here while every sibling file in this folder had it.
// Without it, any logged-in user - including a Farmer - could approve a report,
// allocate aid to themselves and deduct real inventory stock, with the audit
// entry below hardcoded to say "Admin".
$admin_id = $_SESSION['User_ID'] ?? null;
// Admin only: approving aid belongs to the DA Region X Admin. The MAO validates
// reports and releases approved aid, but never approves it.
if (!$admin_id || ($_SESSION['Role'] ?? '') !== 'Admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);

// One report can be given several relief items ("Add another item" in Damage
// Reports). They used to arrive as one request per item: the first request
// approved the report, so every later one was refused with "can no longer be
// approved" and only the first item was ever allocated. Now all items arrive
// together as `allocations` and are saved in ONE transaction - all of them or
// none. The single-item shape (category/quantity/unit/item_id at the top
// level) is still accepted; the Admin Dashboard's approval sends that.
$report_id = (int)($input['report_id'] ?? 0);
if (isset($input['allocations']) && is_array($input['allocations'])) {
    $allocations = array_values($input['allocations']);
} elseif (isset($input['category'], $input['quantity'], $input['unit'])) {
    $allocations = [[
        'item_id'  => $input['item_id'] ?? null,
        'category' => $input['category'],
        'quantity' => $input['quantity'],
        'unit'     => $input['unit'],
    ]];
} else {
    $allocations = [];
}

if (!$report_id || !$allocations) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required aid allocation fields.']);
    exit;
}

// Validate every item before anything is touched.
foreach ($allocations as $i => $a) {
    // Required. This used to be optional "for backward compatibility", and the
    // Dashboard's approval call left it out - so the server skipped the whole
    // stock block below and approved the aid without ever deducting inventory.
    // Every approval must name the inventory item it draws from, or stock and
    // the aid ledger silently drift apart.
    $item_id = !empty($a['item_id']) ? (int)$a['item_id'] : null;
    if (!$item_id) {
        echo json_encode(['status' => 'error', 'message' => 'Please select the relief item this aid is drawn from.']);
        exit;
    }
    $qty = (float)($a['quantity'] ?? 0);
    if ($qty <= 0 || floor($qty) != $qty) {
        echo json_encode(['status' => 'error', 'message' => 'Aid quantity must be a whole number greater than zero.']);
        exit;
    }
    $allocations[$i] = [
        'item_id'  => $item_id,
        'category' => (string)($a['category'] ?? ''),
        'quantity' => $qty,
        'unit'     => (string)($a['unit'] ?? ''),
    ];
}

$conn->begin_transaction();

try {
    // STEP 1: Get the Farmer_ID and Crop Name
    // We need the Farmer_ID for both the aid_distribution table and the notification
    $stmtInfo = $conn->prepare("SELECT dr.Farmer_ID, c.Crop_Name
                               FROM damage_report dr
                               LEFT JOIN crop c ON dr.Crop_ID = c.Crop_ID
                               WHERE dr.Damage_Report_ID = ?");
    $stmtInfo->bind_param("i", $report_id);
    $stmtInfo->execute();
    $info = $stmtInfo->get_result()->fetch_assoc();
    $stmtInfo->close();

    if (!$info) throw new Exception("Damage report not found.");

    $farmer_id = $info['Farmer_ID'];
    $crop_name = $info['Crop_Name'] ?? "Crop";

    // STEP 2: Update Damage Report Status to 'Approved'.
    // Only a report that hasn't been finalised yet can be approved. The
    // status condition makes this atomic and idempotent: if the report is
    // already Approved/Rejected/Archived (e.g. a second click, or a second
    // tab), this UPDATE matches 0 rows and we abort the whole transaction -
    // so no stock is deducted and nothing is double-counted.
    $stmt1 = $conn->prepare("UPDATE damage_report SET Status = 'Approved', Approved_By = ? WHERE Damage_Report_ID = ? AND Status IN ('Pending','Validated','Inspected')");
    $stmt1->bind_param("ii", $admin_id, $report_id);
    $stmt1->execute();
    if ($stmt1->affected_rows === 0) {
        throw new Exception("This report can no longer be approved - it may already be approved, rejected or archived. Please refresh and check.");
    }
    $stmt1->close();

    // STEP 3: For each item, re-verify stock server-side (never trust the
    // frontend's own check), reserve it, and record it in the aid ledger. The
    // same item listed twice is checked against the stock left after the first.
    $stmtStock  = $conn->prepare("SELECT current_stock FROM masterfile_items WHERE id = ? FOR UPDATE");
    $stmtDeduct = $conn->prepare("UPDATE masterfile_items SET current_stock = current_stock - ? WHERE id = ?");
    $stmtAid    = $conn->prepare("INSERT INTO aid_distribution (Damage_Report_ID, Farmer_ID, Category, Item_ID, Quantity, Unit, Distributed_By, Status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending Distribution')");
    $parts = [];
    foreach ($allocations as $a) {
        $item_id = $a['item_id']; $qty = $a['quantity']; $cat = $a['category']; $unit = $a['unit'];

        $stmtStock->bind_param("i", $item_id);
        $stmtStock->execute();
        $stockRow = $stmtStock->get_result()->fetch_assoc();
        if (!$stockRow) throw new Exception("Selected inventory item no longer exists.");
        if ((float)$stockRow['current_stock'] < $qty) {
            throw new Exception("Not enough stock remaining for this item." . (count($allocations) > 1 ? " ($cat)" : ''));
        }

        $stmtDeduct->bind_param("di", $qty, $item_id);
        $stmtDeduct->execute();

        $stmtAid->bind_param("iisidsi", $report_id, $farmer_id, $cat, $item_id, $qty, $unit, $admin_id);
        $stmtAid->execute();

        $parts[] = "$qty $unit of $cat";
    }
    $stmtStock->close(); $stmtDeduct->close(); $stmtAid->close();

    $aidText = count($parts) > 1
        ? implode(', ', array_slice($parts, 0, -1)) . ' and ' . end($parts)
        : $parts[0];

    // STEP 4: Create the Farmer Notification (one, listing every item)
    $msg = "Good news! Relief aid has been allocated for your $crop_name report (#REP-$report_id). You can now claim $aidText at the MAO office.";
    $redirectUrl = 'index.html';
    $stmtNotif = $conn->prepare("INSERT INTO notification (Farmer_ID, Message, Redirect_URL, Is_Read) VALUES (?, ?, ?, 0)");
    $stmtNotif->bind_param("iss", $farmer_id, $msg, $redirectUrl);
    $stmtNotif->execute();

    // STEP 4b: Notify MAO — aid is now sitting in their queue waiting to be handed out
    $mao_msg = "Aid for report #REP-$report_id ($aidText) has been allocated and is ready for distribution.";
    $mao_redirect = 'inventory_aid.html';
    $stmtMaoNotif = $conn->prepare("INSERT INTO admin_notification (Target_Role, Message, Related_Report_ID, Redirect_URL) VALUES ('MAO', ?, ?, ?)");
    $stmtMaoNotif->bind_param("sis", $mao_msg, $report_id, $mao_redirect);
    $stmtMaoNotif->execute();

    // Commit all changes
    $conn->commit();

    // Log the action
    require_once '../../audit/log_action.php';
    // Real role from the session (only Admin can reach this point now).
    logAction($conn, $admin_id, $_SESSION['Role'],
        'Approve Report',
        "Report #$report_id approved. Aid: $aidText allocated to farmer #$farmer_id",
        'Damage Reports'
    );

    // Wipe output buffer to ensure clean JSON
    if (ob_get_length()) ob_clean();
    echo json_encode(['status' => 'success', 'message' => 'Report approved, aid allocated, and farmer notified.']);

} catch (Exception $e) {
    $conn->rollback();
    // The reasons thrown above are written for the Admin and are safe to show;
    // anything else (a database error) still goes through safeErrorMessage().
    $userMessages = [
        'Selected inventory item no longer exists.',
        'Not enough stock remaining for this item.',
        'Damage report not found.',
        'This report can no longer be approved - it may already be approved, rejected or archived. Please refresh and check.',
    ];
    $isUserMessage = false;
    foreach ($userMessages as $m) {
        if (strpos($e->getMessage(), $m) === 0) { $isUserMessage = true; break; }
    }
    echo json_encode(['status' => 'error', 'message' => $isUserMessage ? $e->getMessage() : safeErrorMessage($e)]);
}
?>
