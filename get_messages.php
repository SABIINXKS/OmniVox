<?php
session_start();
require 'db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$user_id = $_SESSION['user_id'];
$receiver_id = isset($_GET['receiver_id']) ? (int)$_GET['receiver_id'] : 0;

if ($receiver_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid receiver ID']);
    exit;
}

try {
    // Fetch user info
    $stmt = $pdo->prepare("SELECT user_id, username, profile_picture FROM users WHERE user_id = ?");
    $stmt->execute([$receiver_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }

    // Fetch messages
    $stmt = $pdo->prepare("
        SELECT m.message_id, m.sender_id, m.receiver_id, m.content, m.created_at, m.is_read
        FROM messages m
        WHERE (m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)
        ORDER BY m.created_at ASC
    ");
    $stmt->execute([$user_id, $receiver_id, $receiver_id, $user_id]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'user' => $user,
        'messages' => $messages
    ]);
} catch (PDOException $e) {
    error_log("Get messages error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>