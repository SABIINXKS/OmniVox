<?php
session_start();
require 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['mark_as_read']) || !isset($_POST['sender_id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$receiver_id = $_SESSION['user_id'];
$sender_id = $_POST['sender_id'];

try {
    $stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE receiver_id = ? AND sender_id = ? AND is_read = 0");
    $stmt->execute([$receiver_id, $sender_id]);
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log("Error marking messages as read: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}