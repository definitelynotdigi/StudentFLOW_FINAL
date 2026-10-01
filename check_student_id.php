<?php
header('Content-Type: application/json');

require_once __DIR__ . '/database.php';   // provides $pdo

$student_id = trim($_GET['student_id'] ?? '');

if ($student_id === '') {
    echo json_encode(['success' => false, 'message' => 'Student ID is required.']);
    exit();
}

// 1. Must exist in the admin master list
$stmt = $pdo->prepare("
    SELECT first_name, last_name, department, course, status
    FROM student_masterlist WHERE student_id = ? LIMIT 1
");
$stmt->execute([$student_id]);
$master = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$master) {
    echo json_encode([
        'success' => false,
        'message' => 'Student ID not found in Master List. Please contact Admin.'
    ]);
    exit();
}

// 2. Must not already have an active portal account
$stmt = $pdo->prepare("SELECT id FROM users WHERE student_id = ? LIMIT 1");
$stmt->execute([$student_id]);
if ($stmt->fetch()) {
    echo json_encode([
        'success' => false,
        'message' => 'Student ID Number (' . htmlspecialchars($student_id) . ') already has an active portal account.'
    ]);
    exit();
}

// 3. Return verified data
echo json_encode([
    'success' => true,
    'message' => 'Student ID verified in Master List.',
    'data' => [
        'first_name' => $master['first_name'],
        'last_name'  => $master['last_name'],
        'department' => $master['department'],
        'course'     => $master['course'],
        'status'     => $master['status'],
    ],
]);

?>