<?php
// tests/integration.php
// Integration tests: these call CropGuard's real endpoints over HTTP, the same
// way the browser does, so each test checks several parts working together -
// the endpoint, the session, the database and the role checks.
//
// Needs XAMPP running (Apache + MySQL). Runs against the LOCAL site only.
//
// Run:  D:\Xampp\php\php.exe tests\integration.php
//
// Test accounts: two temporary accounts (*@example.test) are created directly
// in the local database at the start and deleted again at the end, together
// with the audit-log lines their logins produce. Nothing else is touched.

require __DIR__ . '/bootstrap.php';

const CG_BASE = 'http://localhost/da_system';

const FARMER_EMAIL   = 'cg.it.farmer@example.test';
const FARMER_PASS    = 'Farmer@Test2026';
const FARMER_CODE    = '482915';
const ADMIN_EMAIL    = 'cg.it.admin@example.test';
const ADMIN_PASS     = 'Admin@Test2026';
const REGISTER_EMAIL = 'cg.it.register@example.test';

// Local database only - same credentials as the XAMPP branch of
// api/config/db_connection.php.
mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli('localhost', 'root', '', 'crop_damage_system');
if ($db->connect_error) {
    fwrite(STDERR, "Cannot reach the local database - is MySQL running in XAMPP?\n");
    exit(1);
}
$db->set_charset('utf8mb4');

// A browser stand-in: keeps its own cookies, so each one is a separate session.
final class Client
{
    private $ch;

    public function __construct()
    {
        $this->ch = curl_init();
        curl_setopt_array($this->ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_COOKIEFILE     => '', // in-memory cookie storage
        ]);
    }

    public function get(string $path): array
    {
        curl_setopt($this->ch, CURLOPT_URL, CG_BASE . '/' . $path);
        curl_setopt($this->ch, CURLOPT_HTTPGET, true);
        return $this->send();
    }

    public function post(string $path, array $json): array
    {
        curl_setopt($this->ch, CURLOPT_URL, CG_BASE . '/' . $path);
        curl_setopt($this->ch, CURLOPT_POST, true);
        curl_setopt($this->ch, CURLOPT_POSTFIELDS, json_encode($json));
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        return $this->send();
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

function userRow(mysqli $db, string $email): ?array
{
    $stmt = $db->prepare('SELECT User_ID, Email_Verified, Failed_Login_Attempts FROM users WHERE Email = ?');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// Removes ONLY the test accounts (matched by their exact @example.test emails)
// and the audit-log lines those accounts produced.
function cleanupTestAccounts(mysqli $db): void
{
    $emails = [FARMER_EMAIL, ADMIN_EMAIL, REGISTER_EMAIL];
    foreach ($emails as $email) {
        $row = userRow($db, $email);
        if ($row) {
            $id = (int)$row['User_ID'];
            $stmt = $db->prepare('DELETE FROM system_logs WHERE User_ID = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
        }
        $stmt = $db->prepare('DELETE FROM users WHERE Email = ?');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $stmt->close();
    }
}

function createTestAccount(mysqli $db, string $role, string $name, string $email, string $password, int $verified, ?string $code): int
{
    $hash    = password_hash($password, PASSWORD_DEFAULT);
    $expires = $code ? date('Y-m-d H:i:s', strtotime('+5 minutes')) : null;
    $stmt = $db->prepare(
        "INSERT INTO users (Role, Fullname, Email, Password, Contact_Number, STATUS, Email_Verified, Verification_Token, Verification_Token_Expires)
         VALUES (?, ?, ?, ?, '09171234567', 'Active', ?, ?, ?)"
    );
    $stmt->bind_param('ssssiss', $role, $name, $email, $hash, $verified, $code, $expires);
    $stmt->execute();
    $id = $db->insert_id;
    $stmt->close();
    return $id;
}

// Fresh start (also clears leftovers if a previous run was interrupted), and
// guaranteed cleanup when the run ends - even if a test fails.
cleanupTestAccounts($db);
register_shutdown_function(fn() => cleanupTestAccounts($db));

$farmerId = createTestAccount($db, 'Farmer', 'IT Test Farmer', FARMER_EMAIL, FARMER_PASS, 0, FARMER_CODE);
createTestAccount($db, 'Admin', 'IT Test Admin', ADMIN_EMAIL, ADMIN_PASS, 1, null);

$guest  = new Client();
$farmer = new Client();
$admin  = new Client();

test('it101 registration rejects a blank name', function () use ($guest) {
    $res = $guest->post('api/auth/register.php', [
        'full_name' => '   ', 'contact_number' => '09171234567',
        'email' => REGISTER_EMAIL, 'password' => 'Palay@2026',
    ]);
    assertSame('error', $res['status'], 'status');
    assertContains('full name', $res['message'], 'message');
});

test('it102 registration rejects an invalid mobile number', function () use ($guest) {
    $res = $guest->post('api/auth/register.php', [
        'full_name' => 'Juan Dela Cruz', 'contact_number' => '12345',
        'email' => REGISTER_EMAIL, 'password' => 'Palay@2026',
    ]);
    assertSame('error', $res['status'], 'status');
    assertContains('mobile number', $res['message'], 'message');
});

test('it103 registration rejects a weak password', function () use ($guest, $db) {
    $res = $guest->post('api/auth/register.php', [
        'full_name' => 'Juan Dela Cruz', 'contact_number' => '09171234567',
        'email' => REGISTER_EMAIL, 'password' => 'short',
    ]);
    assertSame('error', $res['status'], 'status');
    assertNull(userRow($db, REGISTER_EMAIL), 'no account was created');
});

test('it104 unverified account is blocked at login', function () use ($farmer) {
    $res = $farmer->post('api/auth/login.php', ['email' => FARMER_EMAIL, 'password' => FARMER_PASS]);
    assertSame('error', $res['status'], 'status');
    assertContains('verify your email', $res['message'], 'message');
});

test('it105 wrong verification code is rejected', function () use ($guest, $db) {
    $res = $guest->post('api/auth/verify_code.php', ['email' => FARMER_EMAIL, 'code' => '000000']);
    assertSame('error', $res['status'], 'status');
    assertSame(0, (int)userRow($db, FARMER_EMAIL)['Email_Verified'], 'account still unverified');
});

test('it106 correct verification code activates the account', function () use ($guest, $db) {
    $res = $guest->post('api/auth/verify_code.php', ['email' => FARMER_EMAIL, 'code' => FARMER_CODE]);
    assertSame('success', $res['status'], 'status');
    assertSame(1, (int)userRow($db, FARMER_EMAIL)['Email_Verified'], 'account now verified');
});

test('it107 login returns a session and the farmer dashboard', function () use ($farmer, $farmerId) {
    $res = $farmer->post('api/auth/login.php', ['email' => FARMER_EMAIL, 'password' => FARMER_PASS]);
    assertSame('success', $res['status'], 'status');
    assertSame('views/farmers/index.html', $res['redirect'], 'redirect');

    $session = $farmer->get('api/auth/session_check.php');
    assertSame('active', $session['status'], 'session status');
    assertSame('Farmer', $session['role'], 'session role');
    assertSame($farmerId, (int)$session['user']['id'], 'session user');
});

test('it108 wrong password is rejected and counted', function () use ($db) {
    $attacker = new Client();
    $res = $attacker->post('api/auth/login.php', ['email' => FARMER_EMAIL, 'password' => 'Wrong@Pass1']);
    assertSame('error', $res['status'], 'status');
    assertSame('Invalid email or password', $res['message'], 'message');
    assertSame(1, (int)userRow($db, FARMER_EMAIL)['Failed_Login_Attempts'], 'failed attempt counted');
});

test('it109 guest cannot open an admin-only endpoint', function () use ($guest) {
    $res = $guest->get('api/masterfile/users/get_all.php');
    assertSame('error', $res['status'], 'status');
    assertSame('Unauthorized.', $res['message'], 'message');
});

test('it110 farmer session is refused by an admin-only endpoint', function () use ($farmer) {
    $res = $farmer->get('api/masterfile/users/get_all.php');
    assertSame('error', $res['status'], 'status');
    assertSame('Unauthorized.', $res['message'], 'message');
});

test('it111 admin session can query the user list endpoint', function () use ($admin) {
    $login = $admin->post('api/auth/login.php', ['email' => ADMIN_EMAIL, 'password' => ADMIN_PASS]);
    assertSame('success', $login['status'], 'admin login');
    assertSame('views/masterfile/dashboard.html', $login['redirect'], 'admin redirect');

    $res = $admin->get('api/masterfile/users/get_all.php');
    assertSame('success', $res['status'], 'status');
    assertTrue(in_array(FARMER_EMAIL, array_column($res['data'], 'email'), true), 'list includes the new farmer');
});

test('it112 logout ends the session', function () use ($farmer) {
    $res = $farmer->get('api/auth/logout.php');
    assertSame('success', $res['status'], 'logout');
    assertSame('inactive', $farmer->get('api/auth/session_check.php')['status'], 'session after logout');
});

TestRun::run('Tests\Feature\CropGuardIntegrationTest');
