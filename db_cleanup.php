<?php
require_once 'config.php';

$db = new App\Models\Database();

// Generate a random password
$password = bin2hex(random_bytes(8)); // 16 character hex string
$hashedPassword = password_hash($password, PASSWORD_BCRYPT);

echo "🧹 Database Cleanup Started...\n\n";

// 1. Delete all demo/test users (IDs 15-89)
echo "Deleting Demo/Test Users:\n";
$deleteIds = [
    16, 17, 18, 19, 20, 21, 22, 23, 24, 25,
    26, 27, 28, 29, 30, 31, 32, 33, 34, 35,
    36, 37, 38, 39, 40, 41, 42, 43, 44, 45,
    46, 47, 48, 49, 50, 51, 52, 53, 54, 55,
    56, 57, 58, 59, 60, 61, 62, 63, 64, 65,
    66, 67, 68, 69, 70, 71, 72, 73, 74, 75,
    76, 77, 78, 79, 80, 81, 82, 83, 84, 85,
    86, 87, 88, 89, 15
];

// First delete tasks associated with these users - with proper binding
$placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
$taskSql = "DELETE FROM tasks WHERE assignee_id IN ($placeholders) OR reporter_id IN ($placeholders)";
$allIds = array_merge($deleteIds, $deleteIds);

// Use PDO directly for IN clause with multiple values
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ':' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
    DB_USER,
    DB_PASSWORD
);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$taskStmt = $pdo->prepare($taskSql);
$taskStmt->execute($allIds);
echo "  ✓ Deleted tasks for demo users\n";

// Then delete the users
$userSql = "DELETE FROM users WHERE id IN ($placeholders)";
$userStmt = $pdo->prepare($userSql);
$userStmt->execute($deleteIds);
echo "  ✓ Deleted " . count($deleteIds) . " demo/test user accounts\n";

// 2. Update user ID 8 with email and password
echo "\nUpdating User ID 8:\n";
$updateStmt = $pdo->prepare("UPDATE users SET email = ?, password = ? WHERE id = 8");
$updateStmt->execute(['olexandrmatsuk@gmail.com', $hashedPassword]);
echo "  ✓ Email set to: olexandrmatsuk@gmail.com\n";
echo "  ✓ Password updated\n";

// Verify the update
$verifyStmt = $pdo->prepare("SELECT * FROM users WHERE id = 8");
$verifyStmt->execute();
$user8 = $verifyStmt->fetch(PDO::FETCH_ASSOC);

echo "\n" . str_repeat("=", 60) . "\n";
echo "✅ Database cleanup completed successfully!\n";
echo str_repeat("=", 60) . "\n\n";

echo "📧 Login Credentials:\n";
echo "   Email:    " . $user8['email'] . "\n";
echo "   Password: $password\n\n";

echo "💾 Use these credentials to log in to the application.\n";
echo "⚠️  Save the password - it won't be shown again.\n";
?>
