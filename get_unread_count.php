<?php
session_start();
require 'db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['unread_count' => 0]);
    exit;
}

$user_id = $_SESSION['user_id'];

try {
    // Fetch unread messages count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_count 
        FROM messages 
        WHERE receiver_id = ? AND is_read = 0
    ");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch();
    
    echo json_encode([
        'unread_count' => (int)$result['unread_count']
    ]);
    
} catch (PDOException $e) {
    error_log("Error fetching unread count: " . $e->getMessage());
    echo json_encode(['unread_count' => 0]);
}
?>