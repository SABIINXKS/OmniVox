<?php
session_start();
require '../db_connect.php';

header('Content-Type: application/json');

// Check if user is authenticated
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Check if required parameters are provided
if (!isset($_POST['user_id']) || !isset($_POST['message'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

$user_id = (int)$_POST['user_id'];
$message = trim($_POST['message']);
$actor_id = $_SESSION['user_id'];

// Validate parameters
if ($user_id <= 0 || empty($message)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

// Don't send notification to yourself
if ($user_id === $actor_id) {
    echo json_encode(['success' => true, 'message' => 'No self-notification needed']);
    exit;
}

try {
    // Check if the target user exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    
    if ($stmt->fetchColumn() == 0) {
        echo json_encode(['success' => false, 'message' => 'Target user not found']);
        exit;
    }
    
    // Add notification
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, actor_id, message, created_at, is_read) VALUES (?, ?, ?, NOW(), 0)");
    $success = $stmt->execute([$user_id, $actor_id, $message]);
    
    if ($success) {
        echo json_encode(['success' => true, 'message' => 'Notification added successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to add notification']);
    }
    
} catch (PDOException $e) {
    error_log("Error adding notification: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?>