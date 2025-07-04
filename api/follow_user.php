<?php
// follow_user.php
header('Content-Type: application/json');
session_start();
require '../db_connect.php';

if (!isset($_SESSION['user_id']) || !isset($_POST['followed_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized or missing data']);
    exit;
}

$follower_id = $_SESSION['user_id'];
$followed_id = (int)$_POST['followed_id'];

try {
    // Check if already following
    $stmt = $pdo->prepare("SELECT follow_id FROM follows WHERE follower_id = ? AND followed_id = ?");
    $stmt->execute([$follower_id, $followed_id]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Already following']);
        exit;
    }

    // Insert follow relationship
    $stmt = $pdo->prepare("INSERT INTO follows (follower_id, followed_id, created_at) VALUES (?, ?, NOW())");
    $stmt->execute([$follower_id, $followed_id]);

    echo json_encode(['success' => true, 'message' => 'Followed successfully']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}