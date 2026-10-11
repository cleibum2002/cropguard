<?php
// File: api/auth/register.php
// Farmer sign-up. Since the 2026-10-11 revision this does NOT create an
// account: it files a request in `registration_requests`. The applicant
// verifies their email with a 6-digit code (verify_code.php), then the Admin
// approves or rejects it (api/masterfile/registrations/process.php). Only an
// approved request becomes a row in `users`; a rejected one is deleted.
header('Content-Type: application/json');
require_once '../config/db_connection.php'; // Adjust this path if your config is located elsewhere
require_once '../config/mailer.php';
require_once '../config/phone.php';

function validatePasswordStrength($password) {
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters long.';
    }
    if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};\'":\\\\|,.<>\/?~`]/', $password)) {
        return 'Password must include at least one special character (e.g. ! @ # $ %).';
    }
    return null;
}

function hashUserPassword($password) {
    // password_hash() with PASSWORD_DEFAULT (bcrypt) already returns a salted,
    // 60-character hash string - this just gives it the expected name.
    return password_hash($password, PASSWORD_DEFAULT);
}

function validateEmailFormat($email) {
    // Rejects structural errors specifically: missing @ symbol, or missing/incomplete domain extension.
    // filter_var's FILTER_VALIDATE_EMAIL already enforces both of these correctly (it's the
    // standard, RFC-822-aware way to do this in PHP) - this wrapper just gives it the name
    // your test plan checks for, and a clear message for each failure case.
    if (strpos($email, '@') === false) {
        return 'Email address must include an @ symbol.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Email address format is invalid (check the domain, e.g. name@example.com).';
    }
    return null;
}

// Letters (including ñ and accents), spaces, and . - ' only. strip_tags first:
// names are later shown to staff, and never legitimately contain markup.
function cleanNamePart($value, $minLetters) {
    $v = trim(preg_replace('/\s+/', ' ', strip_tags((string)$value)));
    if ($v === '') return ['', null];
    if (!preg_match("/^[\p{L}][\p{L} .'\-]*$/u", $v) || preg_match_all('/\p{L}/u', $v) < $minLetters || mb_strlen($v) > 50) {
        return [$v, false];
    }
    return [$v, true];
}

$SUFFIXES      = ['N/A', 'Jr.', 'Sr.', 'II', 'III', 'IV'];
$GENDERS       = ['Male', 'Female', 'Prefer not to say'];
$CIVIL_STATUS  = ['Single', 'Married', 'Widowed', 'Separated'];

function fail($message) {
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

try {
    $data = json_decode(file_get_contents("php://input"), true) ?: [];
    $get  = fn($k) => trim((string)($data[$k] ?? ''));

    // 1. Required fields
    foreach (['last_name', 'first_name', 'birth_date', 'gender', 'civil_status', 'email', 'contact_number', 'rsbsa_no', 'password'] as $k) {
        if ($get($k) === '') fail('Please fill in all required fields.');
    }

    // 2. Name parts
    [$last, $okLast]     = cleanNamePart($data['last_name'] ?? '', 2);
    [$first, $okFirst]   = cleanNamePart($data['first_name'] ?? '', 2);
    [$middle, $okMiddle] = cleanNamePart($data['middle_name'] ?? '', 1);
    if ($okLast !== true)  fail('Please enter a valid last name using letters only (spaces, periods, hyphens and apostrophes are allowed).');
    if ($okFirst !== true) fail('Please enter a valid first name using letters only (spaces, periods, hyphens and apostrophes are allowed).');
    if ($middle !== '' && $okMiddle !== true) fail('Please enter a valid middle name using letters only, or leave it blank.');
    $suffix = $get('suffix') === '' ? 'N/A' : $get('suffix');
    if (!in_array($suffix, $SUFFIXES, true)) fail('Please choose a suffix from the list.');

    // 3. Date of birth: a real date, at least 18 years old, not over 120.
    $birth = $get('birth_date');
    $bd = DateTime::createFromFormat('Y-m-d', $birth);
    if (!$bd || $bd->format('Y-m-d') !== $birth) fail('Please enter a valid date of birth.');
    $age = (int)$bd->diff(new DateTime('today'))->y;
    if ($bd > new DateTime('today')) fail('Date of birth cannot be in the future.');
    if ($age < 18)  fail('You must be at least 18 years old to register.');
    if ($age > 120) fail('Please enter a valid date of birth.');

    // 4. Choices
    if (!in_array($get('gender'), $GENDERS, true))            fail('Please choose a gender from the list.');
    if (!in_array($get('civil_status'), $CIVIL_STATUS, true)) fail('Please choose a civil status from the list.');

    // 5. Contact details and RSBSA
    $email = $get('email');
    $email_error = validateEmailFormat($email);
    if ($email_error) fail($email_error);

    $contact = normalizePhMobile($get('contact_number'));
    if ($contact === null) fail('Please enter a valid Philippine mobile number, for example 09171234567.');

    $rsbsa = $get('rsbsa_no');
    if (!preg_match('/^\d{2}-\d{2}-\d{2}-\d{3}-\d{6}$/', $rsbsa)) fail('Please enter your RSBSA number in the format ##-##-##-###-###### (e.g. 10-43-04-019-000123).');

    $password_error = validatePasswordStrength($data['password'] ?? '');
    if ($password_error) fail($password_error);

    // 6. Requests whose email was never verified are not kept for more than a day.
    $conn->query("DELETE FROM registration_requests WHERE Email_Verified = 0 AND Created_At < NOW() - INTERVAL 1 DAY");

    // 7. Not already a farmer / user
    $chk = $conn->prepare("SELECT User_ID FROM users WHERE Email = ?");
    $chk->bind_param("s", $email); $chk->execute();
    if ($chk->get_result()->fetch_assoc()) fail('This email is already registered.');
    $chk->close();

    $chk = $conn->prepare("SELECT User_ID FROM users WHERE RSBA_No = ?");
    $chk->bind_param("s", $rsbsa); $chk->execute();
    if ($chk->get_result()->fetch_assoc()) fail('This RSBSA number is already registered to another farmer.');
    $chk->close();

    // 8. Not already waiting for approval. An unverified earlier attempt with the
    //    same email is replaced, so a typo in the first try never blocks them.
    $chk = $conn->prepare("SELECT Request_ID, Email, Email_Verified FROM registration_requests WHERE Email = ? OR RSBA_No = ?");
    $chk->bind_param("ss", $email, $rsbsa); $chk->execute();
    $existing = $chk->get_result()->fetch_all(MYSQLI_ASSOC);
    $chk->close();
    foreach ($existing as $row) {
        if ((int)$row['Email_Verified'] === 1) {
            fail(strcasecmp($row['Email'], $email) === 0
                ? 'A registration with this email is already waiting for approval by the DA Admin.'
                : 'This RSBSA number already has a registration waiting for approval.');
        }
        if (strcasecmp($row['Email'], $email) !== 0) {
            fail('This RSBSA number already has a registration in progress. Please check the email used, or try again tomorrow.');
        }
    }
    foreach ($existing as $row) {
        $del = $conn->prepare("DELETE FROM registration_requests WHERE Request_ID = ? AND Email_Verified = 0");
        $del->bind_param("i", $row['Request_ID']); $del->execute(); $del->close();
    }

    // 9. File the request and email the code. The code is deliberately NOT
    //    returned in this response: it goes out by email only, which is what
    //    proves the applicant controls the address.
    $middleDb = $middle === '' ? null : $middle;
    $suffixDb = $suffix === 'N/A' ? null : $suffix;
    $hashed   = hashUserPassword($data['password']);
    $code     = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expires  = date('Y-m-d H:i:s', strtotime('+5 minutes'));
    $gender   = $get('gender');
    $civil    = $get('civil_status');

    $ins = $conn->prepare("INSERT INTO registration_requests
        (Last_Name, First_Name, Middle_Name, Suffix, Birth_Date, Gender, Civil_Status, Email, Contact_Number, RSBA_No, Password, Email_Verified, Verification_Token, Verification_Token_Expires)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)");
    $ins->bind_param("sssssssssssss", $last, $first, $middleDb, $suffixDb, $birth, $gender, $civil, $email, $contact, $rsbsa, $hashed, $code, $expires);
    if (!$ins->execute()) fail('Could not save your registration. Please try again.');
    $ins->close();

    sendVerificationEmail($email, $first . ' ' . $last, $code); // best-effort, result intentionally ignored
    echo json_encode(['status' => 'success', 'message' => 'Registration received! Please verify your email.']);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}

$conn->close();
?>
