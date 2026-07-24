<?php
// File: api/auth/register.php
header('Content-Type: application/json');
require_once '../config/db_connection.php'; // Adjust this path if your config is located elsewhere

try {
    // 1. Get the JSON payload
    $data = json_decode(file_get_contents("php://input"));

    // 2. Validate inputs
    if (empty($data->full_name) || empty($data->contact_number) || empty($data->email) || empty($data->password)) {
        echo json_encode(['status' => 'error', 'message' => 'Please fill in all required fields.']);
        exit;
    }

    // 3. Clean inputs to prevent SQL injection
    $name = $conn->real_escape_string($data->full_name);
    $contact = $conn->real_escape_string($data->contact_number);
    $email = $conn->real_escape_string($data->email);
    // Hash the password securely!
    $hashed_pass = password_hash($data->password, PASSWORD_DEFAULT);

    // 4. Check if the email is already registered
    $check_stmt = $conn->prepare("SELECT Farmer_ID FROM Farmer WHERE Email = ?");
    $check_stmt->bind_param("s", $email);
    $check_stmt->execute();
    $result = $check_stmt->get_result();

    if ($result->num_rows > 0) {
        echo json_encode(['status' => 'error', 'message' => 'This email is already registered.']);
        $check_stmt->close();
        exit;
    }
    $check_stmt->close();

    // 5. Insert the new Farmer
    // Since we removed Farm_Location and RSBA_No, we only insert the human details
    $insert_stmt = $conn->prepare("INSERT INTO Farmer (Full_Name, Contact_Number, Email, Password) VALUES (?, ?, ?, ?)");
    // Bind the hashed password instead
    $insert_stmt->bind_param("ssss", $name, $contact, $email, $hashed_pass);

    if ($insert_stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Registration successful']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database error: Could not create account.']);
    }

    $insert_stmt->close();

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Server Error: ' . $e->getMessage()]);
}

$conn->close();
?>