<?php
// get_followers_count.php
header('Content-Type: application/json');
require 'db_connect.php';

$user_id = $_GET['user_id'];

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM follows WHERE followed_id = ?");
    $stmt->execute([$user_id]);
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['count' => $count['count']]);
} catch (PDOException $e) {
    echo json_encode(['count' => 0, 'message' => $e->getMessage()]);
}
?>