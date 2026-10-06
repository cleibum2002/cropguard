<?php
// tests/support.php
// Shared helpers for the HTTP test suites (everything except tests/unit.php):
//   - Client: a browser stand-in that keeps its own session cookie
//   - test accounts and practice data (farms, crops, reports, relief items)
//   - cleanup that removes ONLY what the tests created
//
// How test data is recognised, so real data is never touched:
//   - test accounts use the reserved domain @example.test (no real address can
//     use it)
//   - test crop types and relief items are named "TEST-CG ..."
//   - everything else (farms, reports, photos, aid, notifications, audit log
//     lines) is removed through those accounts' User_IDs or those reports' IDs
//
// Runs against the LOCAL XAMPP site and database only (see bootstrap.php).

require __DIR__ . '/bootstrap.php';

const CG_BASE       = 'http://localhost/da_system';
const TEST_PASSWORD = 'Test@Pass2026';
const TEST_PREFIX   = 'TEST-CG ';

// Balingasag, Misamis Oriental - so map tests land somewhere real.
const TEST_LAT = 8.7435;
const TEST_LNG = 124.7772;

// Same clock as the site (api/config/db_connection.php). XAMPP's PHP defaults
// to Europe/Berlin, which made test verification codes look already expired.
date_default_timezone_set('Asia/Manila');

function db(): mysqli
{
    static $db = null;
    if ($db === null) {
        mysqli_report(MYSQLI_REPORT_OFF);
        $db = @new mysqli('localhost', 'root', '', 'crop_damage_system');
        if ($db->connect_error) {
            fwrite(STDERR, "Cannot reach the local database - is MySQL running in XAMPP?\n");
            exit(1);
        }
        $db->set_charset('utf8mb4');
    }
    return $db;
}

// Runs a prepared query and returns all rows (or [] for writes).
function q(string $sql, string $types = '', ...$params): array
{
    $stmt = db()->prepare($sql);
    if (!$stmt) {
        throw new Exception('Test query failed to prepare: ' . db()->error);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        throw new Exception('Test query failed: ' . $stmt->error);
    }
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function row(string $sql, string $types = '', ...$params): ?array
{
    return q($sql, $types, ...$params)[0] ?? null;
}

function insertId(): int
{
    return (int)db()->insert_id;
}

// ── Browser stand-in ───────────────────────────────────────────────────────
final class Client
{
    private $ch;

    public function __construct()
    {
        $this->ch = curl_init();
        curl_setopt_array($this->ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_COOKIEFILE     => '', // in-memory cookie storage
        ]);
    }

    public function get(string $path): array
    {
        curl_setopt($this->ch, CURLOPT_URL, CG_BASE . '/' . $path);
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, []);
        curl_setopt($this->ch, CURLOPT_HTTPGET, true);
        return $this->send();
    }

    // JSON body - how most CropGuard endpoints are called.
    public function post(string $path, array $json): array
    {
        curl_setopt($this->ch, CURLOPT_URL, CG_BASE . '/' . $path);
        curl_setopt($this->ch, CURLOPT_POST, true);
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($this->ch, CURLOPT_POSTFIELDS, json_encode($json));
        return $this->send();
    }

    // Form + file upload body - how report and inspection forms are sent.
    // Files are given as ['images[0]' => '/path/to/file.jpg', ...].
    public function postForm(string $path, array $fields, array $files = []): array
    {
        foreach ($files as $field => $file) {
            $fields[$field] = new CURLFile($file[0], $file[1] ?? null, $file[2] ?? basename($file[0]));
        }
        curl_setopt($this->ch, CURLOPT_URL, CG_BASE . '/' . $path);
        curl_setopt($this->ch, CURLOPT_POST, true);
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, []);
        curl_setopt($this->ch, CURLOPT_POSTFIELDS, $fields);
        return $this->send();
    }

    // HTTP status of a POST, for checking that an endpoint no longer exists.
    public function postStatus(string $path, array $json): int
    {
        curl_setopt($this->ch, CURLOPT_URL, CG_BASE . '/' . $path);
        curl_setopt($this->ch, CURLOPT_POST, true);
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($this->ch, CURLOPT_POSTFIELDS, json_encode($json));
        curl_exec($this->ch);
        return (int)curl_getinfo($this->ch, CURLINFO_RESPONSE_CODE);
    }

    public function cookie(string $name): ?string
    {
        foreach (curl_getinfo($this->ch, CURLINFO_COOKIELIST) as $line) {
            $parts = explode("\t", $line);
            if (($parts[5] ?? null) === $name) {
                return $parts[6] ?? null;
            }
        }
        return null;
    }

    private function send(): array
    {
        $body = curl_exec($this->ch);
        if ($body === false) {
            throw new Exception('Could not reach ' . CG_BASE . ' - is Apache running in XAMPP? (' . curl_error($this->ch) . ')');
        }
        $data = json_decode(trim($body), true);
        if (!is_array($data)) {
            throw new Exception('Server did not return JSON: ' . substr(trim($body), 0, 150));
        }
        return $data;
    }
}

// Logs a Client in and fails the test straight away if that doesn't work.
function loginAs(Client $c, string $email, string $password = TEST_PASSWORD): array
{
    $res = $c->post('api/auth/login.php', ['email' => $email, 'password' => $password]);
    if (($res['status'] ?? '') !== 'success') {
        throw new Exception("Could not log in as $email: " . ($res['message'] ?? 'no message'));
    }
    return $res;
}

// ── Test accounts and practice data ────────────────────────────────────────
function emailFor(string $key): string
{
    return "cg.$key@example.test";
}

/**
 * Creates a test account directly in the database and returns its User_ID.
 * Options: verified (1), status ('Active'), rsbsa (valid for farmers),
 * code / expires (pending email verification), password.
 */
function makeUser(string $role, string $key, array $o = []): int
{
    $email    = emailFor($key);
    $name     = 'Test ' . ucwords(str_replace(['.', '_'], ' ', $key));
    $hash     = password_hash($o['password'] ?? TEST_PASSWORD, PASSWORD_DEFAULT);
    $verified = $o['verified'] ?? 1;
    $status   = $o['status'] ?? 'Active';
    // RSBA_No is UNIQUE, so each test farmer gets its own number, from a range
    // (000-0000-xxxxx) that real RSBSA numbers don't use.
    static $rsbsaSeq = 0;
    $rsbsa    = array_key_exists('rsbsa', $o) ? $o['rsbsa'] : ($role === 'Farmer' ? sprintf('000-0000-%05d', ++$rsbsaSeq) : null);
    $code     = $o['code'] ?? null;
    $expires  = $o['expires'] ?? ($code ? date('Y-m-d H:i:s', strtotime('+5 minutes')) : null);

    q("INSERT INTO users (Role, Fullname, Email, Password, Contact_Number, RSBA_No, STATUS, Email_Verified, Verification_Token, Verification_Token_Expires)
       VALUES (?, ?, ?, ?, '09171234567', ?, ?, ?, ?, ?)",
      'ssssssiss', $role, $name, $email, $hash, $rsbsa, $status, $verified, $code, $expires);
    return insertId();
}

function makeCropType(string $name, float $cost): int
{
    q("INSERT INTO crop_type (Crop_Name, Commodity_Type, Growth_Duration, Standard_Production_Cost, Status)
       VALUES (?, 'Rice', '115 Days', ?, 'Active')", 'sd', TEST_PREFIX . $name, $cost);
    return insertId();
}

function makeItem(string $name, int $stock): int
{
    q("INSERT INTO masterfile_items (item_name, category, current_stock, unit, price, status)
       VALUES (?, 'Seeds', ?, 'bags', 0, 'Active')", 'si', TEST_PREFIX . $name, $stock);
    return insertId();
}

function stockOf(int $itemId): float
{
    return (float)row('SELECT current_stock FROM masterfile_items WHERE id = ?', 'i', $itemId)['current_stock'];
}

/** Creates a farm plus its crop row (same as api/dashboard/submit_farm.php). Returns [Location_ID, Crop_ID]. */
function makeFarm(int $farmerId, int $typeId, float $areaHa, float $lat = TEST_LAT, float $lng = TEST_LNG): array
{
    q("INSERT INTO farm_location (Farmer_ID, Type_ID, Barangay, Area_Size, Latitude, Longitude, Land_Type, Cropping_Period)
       VALUES (?, ?, 'Poblacion', ?, ?, ?, 'Irrigated', 'Wet Season')", 'iiddd', $farmerId, $typeId, $areaHa, $lat, $lng);
    $locationId = insertId();
    q("INSERT INTO crop (Crop_Name, Type_ID, Area_Hectares, Farmer_ID, Location_ID)
       SELECT Crop_Name, Type_ID, ?, ?, ? FROM crop_type WHERE Type_ID = ?", 'diii', $areaHa, $farmerId, $locationId, $typeId);
    return [$locationId, insertId()];
}

/**
 * Inserts a damage report directly (for tests that start mid-journey).
 * Options: technician, visit_date, inspected.
 */
function makeReport(int $farmerId, int $locationId, int $cropId, float $areaHa, string $severity, string $status, array $o = []): int
{
    $tech      = $o['technician'] ?? null;
    $visit     = $o['visit_date'] ?? null;
    $inspected = $o['inspected'] ?? ($status === 'Inspected' || $status === 'Approved' ? 1 : 0);
    q("INSERT INTO damage_report (Farmer_ID, Location_ID, Crop_ID, Affected_Area, Damage_Type, Damage_Severity, Farmer_Reported_Severity, Description, Status, Technician_ID, Visit_Date, Is_Inspected)
       VALUES (?, ?, ?, ?, 'Flood', ?, ?, 'Test report', ?, ?, ?, ?)",
      'iiidsssisi', $farmerId, $locationId, $cropId, $areaHa, $severity, $severity, $status, $tech, $visit, $inspected);
    return insertId();
}

function reportRow(int $reportId): ?array
{
    return row('SELECT * FROM damage_report WHERE Damage_Report_ID = ?', 'i', $reportId);
}

// ── Test photos ────────────────────────────────────────────────────────────
// Tiny but genuine images (1x1 pixel), plus fakes for the rejection tests.
function testFile(string $kind): string
{
    static $made = [];
    if (isset($made[$kind])) {
        return $made[$kind];
    }
    $content = [
        'png'  => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='),
        'jpg'  => base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='),
        // A PHP script wearing a .jpg name - must be refused.
        'fake' => "<?php echo 'this should never run'; ?>",
        'txt'  => "Plain notes, not a photo.",
    ][$kind];
    $ext  = ['png' => 'png', 'jpg' => 'jpg', 'fake' => 'jpg', 'txt' => 'txt'][$kind];
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "cropguard_test_$kind.$ext";
    file_put_contents($path, $content);
    $made[$kind] = $path;
    register_shutdown_function(fn() => @unlink($path));
    return $path;
}

function imagesOf(int $reportId): array
{
    return q('SELECT * FROM damage_report_images WHERE Damage_Report_ID = ? ORDER BY Image_ID', 'i', $reportId);
}

// ── Cleanup ────────────────────────────────────────────────────────────────
function cleanupTestData(): void
{
    $ids = array_map('intval', array_column(q("SELECT User_ID FROM users WHERE Email LIKE '%@example.test'"), 'User_ID'));
    $typeIds = array_map('intval', array_column(q("SELECT Type_ID FROM crop_type WHERE Crop_Name LIKE 'TEST-CG %'"), 'Type_ID'));
    $itemIds = array_map('intval', array_column(q("SELECT id FROM masterfile_items WHERE item_name LIKE 'TEST-CG %'"), 'id'));

    if ($ids) {
        $in = implode(',', $ids);
        $reportIds = array_map('intval', array_column(q("SELECT Damage_Report_ID FROM damage_report WHERE Farmer_ID IN ($in) OR Technician_ID IN ($in)"), 'Damage_Report_ID'));
        if ($reportIds) {
            $rin = implode(',', $reportIds);
            // Photo files on disk first, then their rows.
            foreach (q("SELECT Image_Path FROM damage_report_images WHERE Damage_Report_ID IN ($rin)") as $img) {
                $file = CG_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $img['Image_Path']);
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            q("DELETE FROM damage_report_images WHERE Damage_Report_ID IN ($rin)");
            q("DELETE FROM aid_distribution WHERE Damage_Report_ID IN ($rin)");
            q("DELETE FROM admin_notification WHERE Related_Report_ID IN ($rin)");
            q("DELETE FROM damage_report WHERE Damage_Report_ID IN ($rin)");
        }
        q("DELETE FROM aid_distribution WHERE Farmer_ID IN ($in)");
        q("DELETE FROM notification WHERE Farmer_ID IN ($in)");
        q("DELETE FROM crop WHERE Farmer_ID IN ($in)");
        q("DELETE FROM farm_location WHERE Farmer_ID IN ($in)");
        q("DELETE FROM system_logs WHERE User_ID IN ($in)");
        q("DELETE FROM users WHERE User_ID IN ($in)");
    }
    if ($typeIds) {
        q('DELETE FROM crop_type WHERE Type_ID IN (' . implode(',', $typeIds) . ')');
    }
    if ($itemIds) {
        q('DELETE FROM masterfile_items WHERE id IN (' . implode(',', $itemIds) . ')');
    }
}

// Fresh start (clears leftovers if an earlier run was interrupted) and a
// guaranteed cleanup when this run ends, even if a test fails.
cleanupTestData();
register_shutdown_function('cleanupTestData');
