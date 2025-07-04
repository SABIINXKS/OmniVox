<?php
// unfollow_user.php
header('Content-Type: application/json');
require 'db_connect.php';

$follower_id = $_POST['follower_id'];
$followed_id = $_POST['followed_id'];

try {
    $stmt = $pdo->prepare("DELETE FROM follows WHERE follower_id = ? AND followed_id = ?");
    $stmt->execute([$follower_id, $followed_id]);
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>