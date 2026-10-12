<?php
// api/config/photo_limit.php
//
// Panel revision: at most 5 photos per report for each side - the farmer's
// damage photos and the technician's evidence photos are counted separately.
// Used by dashboard/submit_report.php, technician/submit_validation.php and
// technician/update_inspection.php; the pages stop at 5 as well, this is the
// check that holds if that is bypassed.

if (!defined('CG_MAX_PHOTOS_PER_REPORT')) {
    define('CG_MAX_PHOTOS_PER_REPORT', 5);
}

if (!function_exists('cg_photo_limit_error')) {
    // $field is the $_FILES key ('images' or 'evidencePhotos'); $role is the
    // Uploaded_By_Role whose photos already on the report count towards it.
    // Returns null when the new photos fit, otherwise a message for the user.
    function cg_photo_limit_error($conn, int $reportId, string $role, string $field) {
        $new = 0;
        if (isset($_FILES[$field]['error']) && is_array($_FILES[$field]['error'])) {
            foreach ($_FILES[$field]['error'] as $err) {
                if ($err !== UPLOAD_ERR_NO_FILE) $new++;
            }
        }
        if ($new === 0) return null;

        $have = 0;
        if ($reportId > 0) {
            $st = $conn->prepare("SELECT COUNT(*) AS n FROM damage_report_images WHERE Damage_Report_ID = ? AND Uploaded_By_Role = ?");
            $st->bind_param("is", $reportId, $role);
            $st->execute();
            $have = (int)$st->get_result()->fetch_assoc()['n'];
            $st->close();
        }

        $max = CG_MAX_PHOTOS_PER_REPORT;
        if ($have + $new <= $max) return null;
        $left = max(0, $max - $have);
        return "You can attach up to {$max} photos per report. This report already has {$have}, so you can add {$left} more.";
    }
}
?>
