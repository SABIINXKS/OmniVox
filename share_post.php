<?php
session_start();
require 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];

// Handle fetching chat users or searching users
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';

    try {
        if ($search) {
            // Search for users by username or name, excluding the current user
            $stmt = $pdo->prepare("
                SELECT user_id, username, name, profile_picture
                FROM users
                WHERE (username LIKE ? OR name LIKE ?) AND user_id != ?
                ORDER BY username ASC
                LIMIT 10
            ");
            $stmt->execute(["%$search%", "%$search%", $user_id]);
        } else {
            // Fetch users with whom the current user has chatted
            $stmt = $pdo->prepare("
                SELECT DISTINCT u.user_id, u.username, u.name, u.profile_picture
                FROM users u
                JOIN messages m ON u.user_id = m.sender_id OR u.user_id = m.receiver_id
                WHERE (m.sender_id = ? OR m.receiver_id = ?) AND u.user_id != ?
                ORDER BY (SELECT MAX(created_at) FROM messages WHERE sender_id = u.user_id OR receiver_id = u.user_id) DESC
                LIMIT 10
            ");
            $stmt->execute([$user_id, $user_id, $user_id]);
        }
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'users' => $users]);
    } catch (PDOException $e) {
        error_log("Error fetching users: " . $e->getMessage());
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit;
}

// Handle sharing a post
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['share_post'])) {
    $post_id = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
    $receiver_id = isset($_POST['receiver_id']) ? (int)$_POST['receiver_id'] : 0;

    if ($post_id <= 0 || $receiver_id <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid post or user ID']);
        exit;
    }

    try {
        // Fetch post details
        $stmt = $pdo->prepare("
            SELECT p.content, p.description, p.media_type, p.media_url, u.username
            FROM posts p
            JOIN users u ON p.user_id = u.user_id
            WHERE p.post_id = ?
        ");
        $stmt->execute([$post_id]);
        $post = $stmt->fetch();

        if (!$post) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Post not found']);
            exit;
        }

        // Create message content
        $message_content = "Shared post by @{$post['username']}: ";
        if ($post['description']) {
            $message_content .= $post['description'] . " ";
        }
        if ($post['media_url']) {
            $message_content .= "[{$post['media_type']}:{$post['media_url']}]";
        } else {
            $message_content .= $post['content'];
        }
        $message_content .= " (Post ID: {$post_id})";

        // Insert message
        $stmt = $pdo->prepare("
            INSERT INTO messages (sender_id, receiver_id, content, created_at, is_read)
            VALUES (?, ?, ?, NOW(), 0)
        ");
        $stmt->execute([$user_id, $receiver_id, $message_content]);

        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        error_log("Error sharing post: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit;
}
?>