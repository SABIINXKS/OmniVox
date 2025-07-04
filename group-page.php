<?php
session_start();
require_once 'db_connect.php';
require_once 'functions.php'; // Include the new functions.php file

// Handle logout
if (isset($_GET['logout']) && $_GET['logout'] === 'true') {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Fetch user profile
$stmt = $pdo->prepare("SELECT username, name, email, profile_picture FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();
if (!$user) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Get group_id from URL
if (!isset($_GET['group_id']) || !is_numeric($_GET['group_id'])) {
    header("Location: my-groups.php?error=invalid_group");
    exit;
}
$group_id = (int)$_GET['group_id'];

// Fetch group details
$stmt = $pdo->prepare("
    SELECT g.group_id, g.group_name, g.description, g.group_photo, g.created_at, g.creator_id,
           (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = g.group_id) AS member_count,
           (SELECT 1 FROM group_members gm WHERE gm.group_id = g.group_id AND gm.user_id = ?) AS is_member
    FROM groups g
    WHERE g.group_id = ?
");
$stmt->execute([$user_id, $group_id]);
$group = $stmt->fetch();
if (!$group) {
    header("Location: my-groups.php?error=invalid_group");
    exit;
}

// Fetch group creator details
$stmt = $pdo->prepare("SELECT username, profile_picture FROM users WHERE user_id = ?");
$stmt->execute([$group['creator_id']]);
$creator = $stmt->fetch();

// Handle joining a group
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['join_group'])) {
    $group_id = (int)$_POST['group_id'];
    $stmt = $pdo->prepare("SELECT group_id FROM groups WHERE group_id = ?");
    $stmt->execute([$group_id]);
    if (!$stmt->fetch()) {
        header("Location: group-page.php?group_id=$group_id&error=invalid_group");
        exit;
    }
    $stmt = $pdo->prepare("SELECT * FROM group_members WHERE group_id = ? AND user_id = ?");
    $stmt->execute([$group_id, $user_id]);
    if (!$stmt->fetch()) {
        $stmt = $pdo->prepare("INSERT INTO group_members (group_id, user_id, joined_at) VALUES (?, ?, NOW())");
        $stmt->execute([$group_id, $user_id]);
    }
    header("Location: group-page.php?group_id=$group_id");
    exit;
}

// Handle group deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_group']) && $group['creator_id'] == $user_id) {
    $stmt = $pdo->prepare("DELETE FROM group_members WHERE group_id = ?");
    $stmt->execute([$group_id]);
    $stmt = $pdo->prepare("DELETE FROM group_posts WHERE group_id = ?");
    $stmt->execute([$group_id]);
    $stmt = $pdo->prepare("DELETE FROM group_chat_messages WHERE group_id = ?");
    $stmt->execute([$group_id]);
    $stmt = $pdo->prepare("DELETE FROM post_likes WHERE post_id IN (SELECT post_id FROM group_posts WHERE group_id = ?)");
    $stmt->execute([$group_id]);
    $stmt = $pdo->prepare("DELETE FROM post_comments WHERE post_id IN (SELECT post_id FROM group_posts WHERE group_id = ?)");
    $stmt->execute([$group_id]);
    $stmt = $pdo->prepare("DELETE FROM groups WHERE group_id = ?");
    $stmt->execute([$group_id]);
    header("Location: my-groups.php");
    exit;
}

// Handle group editing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_group']) && $group['creator_id'] == $user_id) {
    $new_group_name = trim($_POST['group_name']);
    $new_description = trim($_POST['description']) ?: null;
    $group_photo = $group['group_photo'];
    if (empty($new_group_name)) {
        $error = "Group name is required.";
    } elseif (strlen($new_group_name) > 100) {
        $error = "Group name must be 100 characters or less.";
    } else {
        if (isset($_FILES['group_photo']) && $_FILES['group_photo']['error'] === UPLOAD_ERR_OK) {
            $file_tmp = $_FILES['group_photo']['tmp_name'];
            $file_name = $_FILES['group_photo']['name'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $allowed_exts = ['jpg', 'jpeg', 'png', 'gif'];
            if (!in_array($file_ext, $allowed_exts)) {
                $error = "Only JPG, JPEG, PNG, and GIF files are allowed.";
            } elseif ($_FILES['group_photo']['size'] > 5 * 1024 * 1024) {
                $error = "File size must be less than 5MB.";
            } else {
                $upload_dir = './group_photos/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                $new_file_name = $group_id . '_' . time() . '.' . $file_ext;
                $upload_path = $upload_dir . $new_file_name;
                if (move_uploaded_file($file_tmp, $upload_path)) {
                    if ($group_photo !== './group_photos/default_group.jpg' && file_exists($group_photo)) {
                        unlink($group_photo);
                    }
                    $group_photo = $upload_path;
                } else {
                    $error = "Failed to upload the group photo.";
                }
            }
        }
        if (empty($error)) {
            try {
                $stmt = $pdo->prepare("UPDATE groups SET group_name = ?, description = ?, group_photo = ? WHERE group_id = ?");
                $stmt->execute([$new_group_name, $new_description, $group_photo, $group_id]);
                header("Location: group-page.php?group_id=$group_id");
                exit;
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }
}

// Handle leaving group
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['leave_group']) && $group['creator_id'] != $user_id) {
    $stmt = $pdo->prepare("DELETE FROM group_members WHERE group_id = ? AND user_id = ?");
    $stmt->execute([$group_id, $user_id]);
    header("Location: my-groups.php");
    exit;
}

// Handle post creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_post']) && $group['is_member']) {
    $content = trim($_POST['post_content']);
    $media_url = null;
    if (!empty($_FILES['post_media']['name'])) {
        $upload_dir = 'group_posts/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        $file_name = basename($_FILES['post_media']['name']);
        $target_file = $upload_dir . time() . '_' . $file_name;
        $file_type = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'mp3', 'pdf'];
        if (in_array($file_type, $allowed_types) && move_uploaded_file($_FILES['post_media']['tmp_name'], $target_file)) {
            $media_url = $target_file;
        }
    }
    if (!empty($content) || $media_url) {
        $stmt = $pdo->prepare("INSERT INTO group_posts (group_id, user_id, content, media_url, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$group_id, $user_id, $content ?: null, $media_url]);
    }
    header("Location: group-page.php?group_id=$group_id");
    exit;
}

// Handle post deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_post']) && $group['is_member']) {
    $post_id = (int)$_POST['post_id'];
    $stmt = $pdo->prepare("SELECT user_id, media_url FROM group_posts WHERE post_id = ? AND group_id = ?");
    $stmt->execute([$post_id, $group_id]);
    $post = $stmt->fetch();
    if ($post && $post['user_id'] == $user_id) {
        if ($post['media_url'] && file_exists($post['media_url'])) {
            unlink($post['media_url']);
        }
        $stmt = $pdo->prepare("DELETE FROM post_likes WHERE post_id = ?");
        $stmt->execute([$post_id]);
        $stmt = $pdo->prepare("DELETE FROM post_comments WHERE post_id = ?");
        $stmt->execute([$post_id]);
        $stmt = $pdo->prepare("DELETE FROM group_posts WHERE post_id = ?");
        $stmt->execute([$post_id]);
    }
    header("Location: group-page.php?group_id=$group_id");
    exit;
}

// Handle chat message submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message']) && $group['is_member']) {
    $message_content = trim($_POST['message_content']);
    if (!empty($message_content)) {
        $stmt = $pdo->prepare("INSERT INTO group_chat_messages (group_id, user_id, content, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$group_id, $user_id, $message_content]);
    }
    header("Location: group-page.php?group_id=$group_id&tab=chat");
    exit;
}

// Handle liking a post
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['like_post']) && $group['is_member']) {
    $post_id = (int)$_POST['post_id'];
    $stmt = $pdo->prepare("SELECT * FROM post_likes WHERE post_id = ? AND user_id = ?");
    $stmt->execute([$post_id, $user_id]);
    if ($stmt->fetch()) {
        $stmt = $pdo->prepare("DELETE FROM post_likes WHERE post_id = ? AND user_id = ?");
        $stmt->execute([$post_id, $user_id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO post_likes (post_id, user_id, created_at) VALUES (?, ?, NOW())");
        $stmt->execute([$post_id, $user_id]);
    }
    header("Location: group-page.php?group_id=$group_id");
    exit;
}

// Handle commenting on a post (AJAX support)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment']) && $group['is_member']) {
    $post_id = (int)$_POST['post_id'];
    $content = trim($_POST['comment_content']);
    $parent_id = isset($_POST['parent_id']) && is_numeric($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
    if (!empty($content)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO post_comments (post_id, user_id, content, parent_id, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$post_id, $user_id, $content, $parent_id]);
            
            // Fetch the newly inserted comment for AJAX response
            $comment_id = $pdo->lastInsertId();
            $stmt = $pdo->prepare("
                SELECT pc.comment_id, pc.content, pc.created_at, pc.parent_id, u.username, u.user_id, u.profile_picture,
                       pu.username AS parent_username
                FROM post_comments pc
                JOIN users u ON pc.user_id = u.user_id
                LEFT JOIN post_comments pcp ON pc.parent_id = pcp.comment_id
                LEFT JOIN users pu ON pcp.user_id = pu.user_id
                WHERE pc.comment_id = ?
            ");
            $stmt->execute([$comment_id]);
            $new_comment = $stmt->fetch();
            
            // Return JSON for AJAX
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'comment' => [
                        'comment_id' => htmlspecialchars($new_comment['comment_id']),
                        'content' => htmlspecialchars($new_comment['content']),
                        'created_at' => date('M d, Y H:i', strtotime($new_comment['created_at'])),
                        'username' => htmlspecialchars($new_comment['username']),
                        'user_id' => $new_comment['user_id'],
                        'profile_picture' => htmlspecialchars($new_comment['profile_picture'] ?? './profile_pics/profile.jpg'),
                        'parent_id' => $new_comment['parent_id'],
                        'parent_username' => htmlspecialchars($new_comment['parent_username'] ?? ''),
                        'level' => $new_comment['parent_id'] ? 1 : 0
                    ]
                ]);
                exit;
            }
        } catch (PDOException $e) {
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit;
            }
            $error = "Failed to add comment: " . $e->getMessage();
        }
    }
    header("Location: group-page.php?group_id=$group_id");
    exit;
}

// Handle comment deletion (AJAX support)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_comment']) && $group['is_member']) {
    $comment_id = (int)$_POST['comment_id'];
    $post_id = (int)$_POST['post_id'];
    
    try {
        // Verify the comment exists and belongs to the user
        $stmt = $pdo->prepare("SELECT user_id FROM post_comments WHERE comment_id = ? AND post_id = ?");
        $stmt->execute([$comment_id, $post_id]);
        $comment = $stmt->fetch();
        
        if ($comment && $comment['user_id'] == $user_id) {
            // Delete the comment and its replies
            $stmt = $pdo->prepare("DELETE FROM post_comments WHERE comment_id = ? OR parent_id = ?");
            $stmt->execute([$comment_id, $comment_id]);
            
            // Get updated comment count
            $stmt = $pdo->prepare("SELECT COUNT(*) AS comment_count FROM post_comments WHERE post_id = ?");
            $stmt->execute([$post_id]);
            $comment_count = $stmt->fetchColumn();
            
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'comment_count' => $comment_count
                ]);
                exit;
            }
        } else {
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Unauthorized or comment not found']);
                exit;
            }
            $error = "You are not authorized to delete this comment.";
        }
    } catch (PDOException $e) {
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
        $error = "Failed to delete comment: " . $e->getMessage();
    }
    header("Location: group-page.php?group_id=$group_id");
    exit;
}

// Fetch group posts with like counts, user like status, and comment counts
$stmt = $pdo->prepare("
    SELECT gp.post_id, gp.content, gp.media_url, gp.created_at, u.username, u.user_id, u.profile_picture,
           (SELECT COUNT(*) FROM post_likes pl WHERE pl.post_id = gp.post_id) AS like_count,
           (SELECT 1 FROM post_likes pl WHERE pl.post_id = gp.post_id AND pl.user_id = ?) AS is_liked,
           (SELECT COUNT(*) FROM post_comments pc WHERE pc.post_id = gp.post_id) AS comment_count
    FROM group_posts gp
    JOIN users u ON gp.user_id = u.user_id
    WHERE gp.group_id = ?
    ORDER BY gp.created_at DESC
");
$stmt->execute([$user_id, $group_id]);
$group_posts = $stmt->fetchAll();

// Fetch comments for each post with parent username
$comments = [];
foreach ($group_posts as $post) {
    $stmt = $pdo->prepare("
        SELECT pc.comment_id, pc.content, pc.created_at, pc.parent_id, u.username, u.user_id, u.profile_picture,
               pu.username AS parent_username
        FROM post_comments pc
        JOIN users u ON pc.user_id = u.user_id
        LEFT JOIN post_comments pcp ON pc.parent_id = pcp.comment_id
        LEFT JOIN users pu ON pcp.user_id = pu.user_id
        WHERE pc.post_id = ?
        ORDER BY pc.created_at ASC
    ");
    $stmt->execute([$post['post_id']]);
    $comments[$post['post_id']] = $stmt->fetchAll();
}

// Build nested comment structure
foreach ($comments as $post_id => &$post_comments) {
    $post_comments = buildCommentTree($post_comments);
}

// Fetch group chat messages
$stmt = $pdo->prepare("
    SELECT gcm.message_id, gcm.content, gcm.created_at, u.username, u.profile_picture
    FROM group_chat_messages gcm
    JOIN users u ON gcm.user_id = u.user_id
    WHERE gcm.group_id = ?
    ORDER BY gcm.created_at ASC
");
$stmt->execute([$group_id]);
$chat_messages = $stmt->fetchAll();

// Determine active tab
$active_tab = isset($_GET['tab']) && $_GET['tab'] === 'chat' ? 'chat' : 'feed';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="./img/login.jpg">
    <link rel="stylesheet" href="./css/firstpage.css">
    <link rel="stylesheet" href="https://unicons.iconscout.com/release/v4.0.8/css/line.css">
    <title><?php echo htmlspecialchars($group['group_name']); ?> - OmniVox</title>
    <style>
        .middle .group-header {
            position: relative;
            margin-bottom: 20px;
        }
        .middle .group-header .group-photo {
            width: 100%;
            height: 330px;
            object-fit: cover;
            border-radius: 10px;
            margin-bottom: 10px;
        }
        .middle .group-header h2 {
            font-size: 1.5rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .middle .group-header p {
            color: #666;
            margin-bottom: 10px;
        }
        .middle .group-header .menu {
            position: absolute;
            top: 10px;
            right: 10px;
            cursor: pointer;
        }
        .middle .group-header .menu .dropdown {
            display: none;
            position: absolute;
            top: 30px;
            right: 0;
            background: white;
            border: 1px solid #e4e6eb;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            z-index: 100;
        }
        .middle .group-header .menu .dropdown.show {
            display: block;
        }
        .middle .group-header .menu .dropdown form {
            margin: 0;
        }
        .middle .group-header .menu .dropdown button {
            background: none;
            border: none;
            padding: 10px;
            width: 100%;
            text-align: left;
            cursor: pointer;
        }
        .middle .group-header .menu .dropdown button:hover {
            background-color: #f0f2f5;
        }
        .middle .group-header .join-btn {
            padding: 10px 20px;
            background-color: #1DA1F2;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 500;
        }
        .middle .group-header .join-btn:hover {
            background-color: #1991DA;
        }
        .middle .group-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        .middle .group-tabs button {
            padding: 10px 20px;
            border: none;
            border-radius: 20px;
            background-color: #e4e6eb;
            cursor: pointer;
            font-weight: 500;
        }
        .middle .group-tabs button.active {
            background-color: #1DA1F2;
            color: white;
        }
        .middle .feed .create-post {
            background: white;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .middle .feed .create-post form {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .middle .feed .create-post textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #e4e6eb;
            border-radius: 5px;
            resize: none;
        }
        .middle .feed .create-post .options {
            display: flex;
            gap: 10px;
        }
        .middle .feed .create-post .options label {
            cursor: pointer;
            padding: 5px 10px;
            background: #e4e6eb;
            border-radius: 5px;
        }
        .middle .feed .create-post .btn-primary {
            background-color: #1DA1F2;
            color: white;
            border: none;
            padding: 10px;
            border-radius: 5px;
            cursor: pointer;
        }
        .middle .feed .post {
            background: white;
            padding: 12px;
            border-radius: 10px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
            margin-bottom: 10px;
            position: relative;
            display: flex;
            gap: 10px;
        }
        .middle .feed .post .profile-photo {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            overflow: hidden;
            background-color: #f0f2f5;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .middle .feed .post .profile-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .middle .feed .post .post-body {
            flex: 1;
        }
        .middle .feed .post .post-body h5 {
            margin: 0;
            font-weight: 500;
            font-size: 1rem;
        }
        .middle .feed .post .post-body .text-muted {
            color: #666;
            font-size: 0.8rem;
        }
        .middle .feed .post .post-body p {
            margin: 8px 0;
            font-size: 0.95rem;
        }
        .middle .feed .post .post-body img,
        .middle .feed .post .post-body video,
        .middle .feed .post .post-body audio,
        .middle .feed .post .post-body a {
            max-width: 100%;
            border-radius: 5px;
            margin-top: 8px;
            display: block;
        }
        .post-actions {
            display: flex;
            gap: 15px;
            margin-top: 10px;
            padding-top: 8px;
            border-top: 1px solid #e4e6eb;
        }
        .post-actions button {
            background: none;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            color: #65676b;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.9rem;
        }
        .post-actions button:hover {
            background-color: #f0f2f5;
        }
        .post-actions button i {
            font-size: 1.1rem;
        }
        .post-actions .like-btn.liked {
            color: #e0245e;
        }
        .post-actions .like-btn.liked i {
            color: #e0245e;
        }
        .post-actions .like-btn span {
            font-size: 0.85rem;
        }
        .comment-section {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #e4e6eb;
            display: none;
        }
        .comment-section.show {
            display: block !important;
        }
        .comment-section .toggle-comments {
            background: none;
            border: none;
            color: #1DA1F2;
            cursor: pointer;
            font-size: 0.9rem;
            margin-bottom: 10px;
        }
        .comment-section .comment-form {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }
        .comment-section .comment-form textarea {
            flex: 1;
            padding: 8px;
            border: 1px solid #e4e6eb;
            border-radius: 5px;
            resize: none;
            font-size: 0.9rem;
        }
        .comment-section .comment-form button {
            background-color: #1DA1F2;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9rem;
        }
        .comment-section .comment {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
            padding-left: 10px;
            position: relative;
        }
        .comment-section .comment.reply {
            margin-left: 20px;
            border-left: 2px solid #e4e6eb;
            padding-left: 8px;
        }
        .comment-section .comment .profile-photo {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            overflow: hidden;
            background-color: #f0f2f5;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .comment-section .comment .profile-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .comment-section .comment .comment-body {
            flex: 1;
        }
        .comment-section .comment .comment-body h6 {
            margin: 0;
            font-size: 0.9rem;
            font-weight: 500;
        }
        .comment-section .comment .comment-body .reply-to {
            font-size: 0.8rem;
            color: #1DA1F2;
            margin: 2px 0;
        }
        .comment-section .comment .comment-body p {
            margin: 5px 0;
            font-size: 0.9rem;
        }
        .comment-section .comment .comment-body .text-muted {
            font-size: 0.8rem;
            color: #666;
        }
        .comment-section .comment .reply-btn,
        .comment-section .comment .delete-btn {
            background: none;
            border: none;
            color: #1DA1F2;
            cursor: pointer;
            font-size: 0.8rem;
            margin-right: 10px;
        }
        .comment-section .comment .delete-btn {
            color: #e0245e;
        }
        .comment-section .reply-form {
            display: none;
            margin-left: 20px;
            margin-top: 5px;
        }
        .comment-section .reply-form.show {
            display: flex;
        }
        .middle .feed .post .menu {
            position: absolute;
            top: 10px;
            right: 10px;
            cursor: pointer;
        }
        .middle .feed .post .menu .dropdown {
            display: none;
            position: absolute;
            top: 20px;
            right: 0;
            background: white;
            border: 1px solid #e4e6eb;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            z-index: 100;
        }
        .middle .feed .post .menu .dropdown.show {
            display: block;
        }
        .middle .feed .post .menu .dropdown form {
            margin: 0;
        }
        .middle .feed .post .menu .dropdown button {
            background: none;
            border: none;
            padding: 10px;
            width: 100%;
            text-align: left;
            cursor: pointer;
        }
        .middle .feed .post .menu .dropdown button:hover {
            background-color: #f0f2f5;
        }
        .middle .chat {
            background: white;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }
        .middle .chat .messages {
            max-height: 400px;
            overflow-y: auto;
            margin-bottom: 20px;
        }
        .middle .chat .message {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }
        .middle .chat .message.own {
            flex-direction: row-reverse;
        }
        .middle .chat .message .profile-photo {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            overflow: hidden;
            background-color: #f0f2f5;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .middle .chat .message .profile-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .middle .chat .message .message-body {
            background: #e4e6eb;
            padding: 10px;
            border-radius: 10px;
            max-width: 70%;
        }
        .middle .chat .message.own .message-body {
            background: #1DA1F2;
            color: white;
        }
        .middle .chat .message .message-body h5 {
            margin: 0;
            font-size: 0.9rem;
        }
        .middle .chat .message .message-body p {
            margin: 5px 0 0;
            font-size: 0.95rem;
        }
        .middle .chat .message .message-body .text-muted {
            font-size: 0.8rem;
            color: #666;
        }
        .middle .chat .message.own .message-body .text-muted {
            color: #ddd;
        }
        .middle .chat form {
            display: flex;
            gap: 10px;
        }
        .middle .chat form textarea {
            flex: 1;
            padding: 10px;
            border: 1px solid #e4e6eb;
            border-radius: 5px;
            resize: none;
        }
        .middle .chat form .btn-primary {
            background-color: #1DA1F2;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
        }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        .modal-content {
            background: white;
            padding: 20px;
            border-radius: 10px;
            width: 90%;
            max-width: 400px;
            text-align: center;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .modal-header h3 {
            margin: 0;
        }
        .modal-header .close {
            cursor: pointer;
            font-size: 1.5rem;
        }
        .modal-body p {
            margin-bottom: 20px;
        }
        .modal-body .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin: 0 10px;
        }
        .modal-body .btn-confirm {
            background-color: #1DA1F2;
            color: white;
        }
        .modal-body .btn-confirm.delete {
            background-color: #e0245e;
        }
        .modal-body .btn-cancel {
            background-color: #e4e6eb;
            color: #333;
        }
        .modal-body form {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .modal-body form input[type="text"],
        .modal-body form textarea {
            padding: 10px;
            border: 1px solid #e4e6eb;
            border-radius: 5px;
            width: 100%;
        }
        .modal-body form input[type="file"] {
            padding: 5px;
        }
        .modal-body form label {
            text-align: left;
            font-weight: bold;
        }
        .modal-body form small {
            color: #666;
            text-align: left;
            display: block;
            margin-top: 5px;
        }
        .modal-body .error-message {
            color: red;
            font-size: 0.9rem;
        }
        .hidden {
            display: none;
        }
        .modal.show {
            display: flex !important;
        }
        .error-message {
            color: red;
            text-align: center;
            margin-top: 10px;
        }
        .non-member-message {
            background: #f0f2f5;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="container">
            <h2 class="log">OmniVox</h2>
            <div class="search-bar"></div>
            <div class="profile-photo">
                <img src="<?php echo htmlspecialchars($user['profile_picture'] ?? './profile_pics/profile.jpg'); ?>" alt="Profile">
            </div>
        </div>
    </nav>

    <main>
        <div class="container">
            <!-- LEFT SIDEBAR -->
            <?php include 'left.php'; ?>

            <!-- MIDDLE SECTION -->
            <div class="middle">
                <div class="group-header">
                    <img src="<?php echo htmlspecialchars($group['group_photo'] ?? './group_photos/default_group.jpg'); ?>" alt="Group Photo" class="group-photo">
                    <h2><?php echo htmlspecialchars($group['group_name']); ?></h2>
                    <p><?php echo htmlspecialchars($group['description'] ?? 'No description available'); ?></p>
                    <p>Created by: <b><?php echo htmlspecialchars($creator['username']); ?></b> | Members: <?php echo $group['member_count']; ?></p>
                    <?php if (!$group['is_member']): ?>
                        <!-- <button class="join-btn" data-group-id="<?php echo htmlspecialchars($group['group_id']); ?>">Join Group</button> -->
                    <?php else: ?>
                        <div class="menu">
                            <i class="uil uil-ellipsis-v menu-toggle"></i>
                            <div class="dropdown">
                                <?php if ($group['creator_id'] == $user_id): ?>
                                    <button type="button" class="edit-group-btn" data-group-id="<?php echo $group['group_id']; ?>">Edit Group</button>
                                    <button type="button" class="delete-group-btn" data-group-id="<?php echo $group_id; ?>">Delete Group</button>
                                <?php else: ?>
                                    <form action="group-page.php?group_id=<?php echo $group_id; ?>" method="POST">
                                        <input type="hidden" name="leave_group" value="1">
                                        <button type="submit">Leave Group</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if (isset($_GET['error']) && $_GET['error'] === 'invalid_group'): ?>
                    <div class="error-message">The selected group does not exist.</div>
                <?php elseif (isset($error)): ?>
                    <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <?php if (!$group['is_member']): ?>
                    <div class="non-member-message">
                        <p>You are not a member of this group. Join to participate in the feed and chat.</p>
                    </div>
                <?php else: ?>
                    <div class="group-tabs">
                        <a href="group-page.php?group_id=<?php echo $group_id; ?>&tab=feed">
                            <button class="<?php echo $active_tab === 'feed' ? 'active' : ''; ?>">Feed</button>
                        </a>
                        <a href="group-page.php?group_id=<?php echo $group_id; ?>&tab=chat">
                            <button class="<?php echo $active_tab === 'chat' ? 'active' : ''; ?>">Chat</button>
                        </a>
                    </div>
                    <?php if ($active_tab === 'feed'): ?>
                        <div class="feed">
                            <div class="create-post">
                                <form action="group-page.php?group_id=<?php echo $group_id; ?>" method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="create_post" value="1">
                                    <textarea name="post_content" placeholder="What's on your mind?" rows="3"></textarea>
                                    <div class="options">
                                        <label><i class="uil uil-camera"></i> You can add a file <input type="file" name="post_media" accept=".jpg,.jpeg,.png,.gif,.mp4,.mp3,.pdf" style="display: none;"></label>
                                    </div>
                                    <button type="submit" class="btn btn-primary">Post</button>
                                </form>
                            </div>
                            <?php if (empty($group_posts)): ?>
                                <p>No posts available in this group.</p>
                            <?php else: ?>
                                <?php foreach ($group_posts as $post): ?>
                                    <div class="post">
                                        <div class="profile-photo">
                                            <img src="<?php echo htmlspecialchars($post['profile_picture'] ?? './profile_pics/profile.jpg'); ?>" alt="Profile">
                                        </div>
                                        <div class="post-body">
                                            <h5><?php echo htmlspecialchars($post['username']); ?></h5>
                                            <small class="text-muted"><?php echo date('M d, Y', strtotime($post['created_at'])); ?></small>
                                            <p><?php echo htmlspecialchars($post['content'] ?? ''); ?></p>
                                            <?php if ($post['media_url']): ?>
                                                <?php $ext = strtolower(pathinfo($post['media_url'], PATHINFO_EXTENSION)); ?>
                                                <?php if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])): ?>
                                                    <img src="<?php echo htmlspecialchars($post['media_url']); ?>" alt="Post Media">
                                                <?php elseif ($ext === 'mp4'): ?>
                                                    <video controls>
                                                        <source src="<?php echo htmlspecialchars($post['media_url']); ?>" type="video/mp4">
                                                        Your browser does not support the video tag.
                                                    </video>
                                                <?php elseif ($ext === 'mp3'): ?>
                                                    <audio controls>
                                                        <source src="<?php echo htmlspecialchars($post['media_url']); ?>" type="audio/mp3">
                                                        Your browser does not support the audio element.
                                                    </audio>
                                                <?php elseif ($ext === 'pdf'): ?>
                                                    <a href="<?php echo htmlspecialchars($post['media_url']); ?>" target="_blank">View PDF</a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <div class="post-actions">
                                                <form action="group-page.php?group_id=<?php echo $group_id; ?>" method="POST" class="like-form">
                                                    <input type="hidden" name="like_post" value="1">
                                                    <input type="hidden" name="post_id" value="<?php echo $post['post_id']; ?>">
                                                    <button type="submit" class="like-btn <?php echo $post['is_liked'] ? 'liked' : ''; ?>" data-post-id="<?php echo $post['post_id']; ?>">
                                                        <i class="uil uil-heart"></i>
                                                        <span><?php echo $post['like_count']; ?> Like<?php echo $post['like_count'] != 1 ? 's' : ''; ?></span>
                                                    </button>
                                                </form>
                                                <button class="comment-btn" data-post-id="<?php echo $post['post_id']; ?>" data-comment-count="<?php echo $post['comment_count']; ?>">
                                                    <i class="uil uil-comment"></i>
                                                    <span class="comment-text">Comment (<?php echo $post['comment_count']; ?>)</span>
                                                </button>
                                            </div>
                                            <div class="comment-section" id="comment-section-<?php echo $post['post_id']; ?>">
                                                <button type="button" class="toggle-comments">Hide Comments</button>
                                                <form class="comment-form" data-post-id="<?php echo $post['post_id']; ?>">
                                                    <input type="hidden" name="add_comment" value="1">
                                                    <input type="hidden" name="post_id" value="<?php echo $post['post_id']; ?>">
                                                    <textarea name="comment_content" placeholder="Write a comment..." rows="2" required></textarea>
                                                    <button type="submit">Post</button>
                                                </form>
                                                <?php
                                                if (isset($comments[$post['post_id']])) {
                                                    displayComments($comments[$post['post_id']], $post['post_id'], $group_id, $user_id);
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        <?php if ($post['user_id'] == $user_id): ?>
                                            <div class="menu">
                                                <i class="menu-toggle uil uil-ellipsis-v"></i>
                                                <div class="dropdown">
                                                    <form action="group-page.php?group_id=<?php echo $group_id; ?>" method="POST">
                                                        <input type="hidden" name="delete_post" value="1">
                                                        <input type="hidden" name="post_id" value="<?php echo $post['post_id']; ?>">
                                                        <button type="submit">Delete</button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="chat">
                            <div class="messages">
                                <?php if (empty($chat_messages)): ?>
                                    <p>No messages in this group chat yet.</p>
                                <?php else: ?>
                                    <?php foreach ($chat_messages as $msg): ?>
                                        <div class="message <?php echo $msg['username'] === $username ? 'own' : ''; ?>">
                                            <div class="profile-photo">
                                                <img src="<?php echo htmlspecialchars($msg['profile_picture'] ?? './profile_pics/profile.jpg'); ?>" alt="Profile">
                                            </div>
                                            <div class="message-body">
                                                <h5><?php echo htmlspecialchars($msg['username']); ?></h5>
                                                <p><?php echo htmlspecialchars($msg['content']); ?></p>
                                                <small class="text-muted"><?php echo date('M d, Y H:i', strtotime($msg['created_at'])); ?></small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <form action="group-page.php?group_id=<?php echo $group_id; ?>&tab=chat" method="POST">
                                <input type="hidden" name="send_message" value="1">
                                <textarea name="message_content" placeholder="Type a message..." rows="2" required></textarea>
                                <button type="submit" class="btn btn-primary">Send</button>
                            </form>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Join Group Confirmation Modal -->
    <div id="joinGroupModal" class="modal hidden">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Join Group</h3>
                <span class="close">×</span>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to join this group?</p>
                <form id="join-group-form" action="group-page.php" method="POST">
                    <input type="hidden" name="join_group" value="1">
                    <input type="hidden" id="join-group-id" name="group_id">
                    <button type="submit" class="btn btn-confirm">Yes</button>
                    <button type="button" class="btn btn-cancel" id="cancelJoinBtn">No</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Group Confirmation Modal -->
    <div id="deleteGroupModal" class="modal hidden">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Delete Group</h3>
                <!-- <span class="close">×</span> -->
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this group? This action cannot be undone.</p>
                <form id="delete-group-form" action="group-page.php?group_id=<?php echo $group_id; ?>" method="POST">
                    <input type="hidden" name="delete_group" value="1">
                    <button type="submit" class="btn btn-confirm delete">Confirm</button>
                    <button type="button" class="btn btn-cancel" id="cancelDeleteBtn">Cancel</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Group Modal -->
    <div id="editGroupModal" class="modal hidden">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Group</h3>
                <!-- <span class="close">×</span> -->
            </div>
            <div class="modal-body">
                <form id="edit-group-form" action="group-page.php?group_id=<?php echo $group_id; ?>" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="edit_group" value="1">
                    <label for="group_name">Group Name</label>
                    <input type="text" name="group_name" id="group_name" value="<?php echo htmlspecialchars($group['group_name']); ?>" required>
                    <label for="description">Description</label>
                    <textarea name="description" id="description"><?php echo htmlspecialchars($group['description'] ?? ''); ?></textarea>
                    <label for="group_photo">Group Photo</label>
                    <input type="file" name="group_photo" id="group_photo" accept=".jpg,.jpeg,.png,.gif">
                    <small class="text-muted">Recommended: 16:9 aspect ratio (e.g., 1920x1080)</small>
                    <button type="submit" class="btn btn-confirm">Save Changes</button>
                    <button type="button" class="btn btn-cancel" id="cancelEditBtn">Cancel</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Comment Modal -->
    <div id="deleteCommentModal" class="modal hidden">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Delete Comment</h3>
                <!-- <span class="close">×</span> -->
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this comment? This action cannot be undone.</p>
                <form id="delete-comment-form" action="group-page.php?group_id=<?php echo $group_id; ?>" method="POST">
                    <input type="hidden" name="delete_comment" value="1">
                    <input type="hidden" id="delete-comment-id" name="comment_id">
                    <input type="hidden" id="delete-post-id" name="post_id">
                    <button type="submit" class="btn btn-confirm delete">Confirm</button>
                    <button type="button" class="btn btn-cancel" id="cancelDeleteCommentBtn">Cancel</button>
                </form>
            </div>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Auto-scroll chat to bottom
    const chatMessages = document.querySelector('.chat .messages');
    if (chatMessages) {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    // Handle menu toggles for group header and posts
    document.querySelectorAll('.menu-toggle').forEach(toggle => {
        toggle.addEventListener('click', e => {
            e.preventDefault();
            const dropdown = toggle.nextElementSibling;
            const isVisible = dropdown.classList.contains('show');
            document.querySelectorAll('.dropdown.show').forEach(d => d.classList.remove('show'));
            if (!isVisible) dropdown.classList.add('show');
        });
    });

    // Close dropdowns when clicking outside
    document.addEventListener('click', e => {
        if (!e.target.closest('.menu')) {
            document.querySelectorAll('.dropdown.show').forEach(d => d.classList.remove('show'));
        }
    });

    // Handle comment section toggle with event delegation
    document.addEventListener('click', e => {
        const button = e.target.closest('.comment-btn');
        if (button) {
            e.preventDefault();
            const postId = button.getAttribute('data-post-id');
            console.log('Comment button clicked for post:', postId); // Debugging
            const commentSection = document.getElementById(`comment-section-${postId}`);
            console.log('Comment section:', commentSection); // Debugging
            if (commentSection) {
                const commentText = button.querySelector('.comment-text');
                const count = button.getAttribute('data-comment-count') || '0';
                const isShown = commentSection.classList.contains('show');
                commentSection.classList.toggle('show');
                commentText.textContent = isShown ? `Comment (${count})` : 'Hide Comments';
            } else {
                console.error(`Comment section not found for post ID: ${postId}`);
            }
        }
    });

    // Handle toggle comments button
    document.querySelectorAll('.toggle-comments').forEach(button => {
        button.addEventListener('click', () => {
            const commentSection = button.closest('.comment-section');
            const postId = commentSection.id.replace('comment-section-', '');
            const commentBtn = document.querySelector(`.comment-btn[data-post-id="${postId}"]`);
            commentSection.classList.remove('show');
            commentBtn.querySelector('.comment-text').textContent = `Comment (${commentBtn.getAttribute('data-comment-count')})`;
        });
    });

    // Handle reply buttons
    document.querySelectorAll('.reply-btn').forEach(button => {
        button.addEventListener('click', () => {
            const commentId = button.getAttribute('data-comment-id');
            const replyForm = document.querySelector(`#reply-form-${commentId}`);
            if (replyForm) {
                replyForm.classList.toggle('show');
            }
        });
    });

    // Handle Join Group modal
    const joinGroupModal = document.getElementById('joinGroupModal');
    const joinGroupForm = document.getElementById('join-group-form');
    const joinGroupId = document.getElementById('join-group-id');
    const cancelJoinBtn = document.getElementById('cancelJoinBtn');

    document.querySelectorAll('.join-btn').forEach(button => {
        button.addEventListener('click', e => {
            e.preventDefault();
            joinGroupId.value = button.getAttribute('data-group-id');
            joinGroupModal.classList.add('show');
            joinGroupModal.classList.remove('hidden');
        });
    });

    cancelJoinBtn.addEventListener('click', () => {
        joinGroupModal.classList.remove('show');
        joinGroupModal.classList.add('hidden');
    });

    // Handle Delete Group modal
    const deleteGroupModal = document.getElementById('deleteGroupModal');
    const cancelDeleteBtn = document.getElementById('cancelDeleteBtn');

    document.querySelectorAll('.delete-group-btn').forEach(button => {
        button.addEventListener('click', e => {
            e.preventDefault();
            deleteGroupModal.classList.add('show');
            deleteGroupModal.classList.remove('hidden');
            document.querySelectorAll('.dropdown.show').forEach(d => d.classList.remove('show'));
        });
    });

    cancelDeleteBtn.addEventListener('click', () => {
        deleteGroupModal.classList.remove('show');
        deleteGroupModal.classList.add('hidden');
    });

    // Handle Edit Group modal
    const editGroupModal = document.getElementById('editGroupModal');
    const cancelEditBtn = document.getElementById('cancelEditBtn');

    document.querySelectorAll('.edit-group-btn').forEach(button => {
        button.addEventListener('click', e => {
            e.preventDefault();
            editGroupModal.classList.add('show');
            editGroupModal.classList.remove('hidden');
            document.querySelectorAll('.dropdown.show').forEach(d => d.classList.remove('show'));
        });
    });

    cancelEditBtn.addEventListener('click', () => {
        editGroupModal.classList.remove('show');
        editGroupModal.classList.add('hidden');
    });

    // Handle Delete Comment modal
    const deleteCommentModal = document.getElementById('deleteCommentModal');
    const deleteCommentForm = document.getElementById('delete-comment-form');
    const deleteCommentId = document.getElementById('delete-comment-id');
    const deletePostId = document.getElementById('delete-post-id');
    const cancelDeleteCommentBtn = document.getElementById('cancelDeleteCommentBtn');

    document.querySelectorAll('.delete-btn').forEach(button => {
        button.addEventListener('click', e => {
            e.preventDefault();
            deleteCommentId.value = button.getAttribute('data-comment-id');
            deletePostId.value = button.getAttribute('data-post-id');
            deleteCommentModal.classList.add('show');
            deleteCommentModal.classList.remove('hidden');
        });
    });

    cancelDeleteCommentBtn.addEventListener('click', () => {
        deleteCommentModal.classList.remove('show');
        deleteCommentModal.classList.add('hidden');
    });

    // Handle AJAX comment submission
    document.querySelectorAll('.comment-form').forEach(form => {
        form.addEventListener('submit', e => {
            e.preventDefault();
            const formData = new FormData(form);
            const postId = form.getAttribute('data-post-id');
            const isReply = form.classList.contains('reply-form');
            const commentSection = document.getElementById(`comment-section-${postId}`);
            const commentBtn = document.querySelector(`.comment-btn[data-post-id="${postId}"]`);
            const scrollY = window.scrollY;

            fetch(`group-page.php?group_id=<?php echo $group_id; ?>`, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const comment = data.comment;
                    const commentDiv = document.createElement('div');
                    commentDiv.className = `comment ${comment.parent_id ? 'reply' : ''}`;
                    commentDiv.setAttribute('data-comment-id', comment.comment_id);
                    commentDiv.innerHTML = `
                        <div class="profile-photo">
                            <img src="${comment.profile_picture}" alt="Profile">
                        </div>
                        <div class="comment-body">
                            <h6>${comment.username}</h6>
                            ${comment.parent_username ? `<div class="reply-to">Replying to @${comment.parent_username}</div>` : ''}
                            <p>${comment.content}</p>
                            <small class="text-muted">${comment.created_at}</small>
                            <button class="reply-btn" data-comment-id="${comment.comment_id}">Reply</button>
                            ${comment.user_id == <?php echo $user_id; ?> ? `<button class="delete-btn" data-comment-id="${comment.comment_id}" data-post-id="${postId}">Delete</button>` : ''}
                            <form class="comment-form reply-form" id="reply-form-${comment.comment_id}" data-post-id="${postId}">
                                <input type="hidden" name="add_comment" value="1">
                                <input type="hidden" name="post_id" value="${postId}">
                                <input type="hidden" name="parent_id" value="${comment.comment_id}">
                                <textarea name="comment_content" placeholder="Type your reply..." rows="2" required></textarea>
                                <button type="submit">Post</button>
                            </form>
                        </div>
                    `;

                    if (isReply) {
                        const parentComment = commentSection.querySelector(`.comment[data-comment-id="${comment.parent_id}"]`);
                        parentComment.parentNode.insertBefore(commentDiv, parentComment.nextSibling);
                    } else {
                        const form = commentSection.querySelector('.comment-form:not(.reply-form)');
                        form.after(commentDiv);
                    }

                    let commentCount = parseInt(commentBtn.getAttribute('data-comment-count')) || 0;
                    commentCount++;
                    commentBtn.setAttribute('data-comment-count', commentCount);
                    commentBtn.querySelector('.comment-text').textContent = commentSection.classList.contains('show') 
                        ? 'Hide Comments' 
                        : `Comment (${commentCount})`;

                    form.querySelector('textarea').value = '';
                    if (isReply) form.classList.remove('show');
                    window.scrollTo(0, scrollY);

                    // Add event listeners to new buttons
                    commentDiv.querySelector('.reply-btn').addEventListener('click', () => {
                        const commentId = commentDiv.querySelector('.reply-btn').getAttribute('data-comment-id');
                        const replyForm = document.getElementById(`reply-form-${commentId}`);
                        if (replyForm.classList.contains('show')) {
                            replyForm.classList.remove('show');
                        } else {
                            document.querySelectorAll('.reply-form.show').forEach(f => f.classList.remove('show'));
                            replyForm.classList.add('show');
                        }
                    });

                    if (commentDiv.querySelector('.delete-btn')) {
                        commentDiv.querySelector('.delete-btn').addEventListener('click', e => {
                            e.preventDefault();
                            deleteCommentId.value = commentDiv.querySelector('.delete-btn').getAttribute('data-comment-id');
                            deletePostId.value = commentDiv.querySelector('.delete-btn').getAttribute('data-post-id');
                            deleteCommentModal.classList.add('show');
                            deleteCommentModal.classList.remove('hidden');
                        });
                    }

                    commentDiv.querySelector('.comment-form').addEventListener('submit', e => arguments.callee(e));
                } else {
                    console.error('Error adding comment:', data.error);
                }
            })
            .catch(error => console.error('Fetch error:', error));
        });
    });

    // Handle AJAX comment deletion
    deleteCommentForm.addEventListener('submit', e => {
        e.preventDefault();
        const formData = new FormData(deleteCommentForm);
        const postId = deletePostId.value;
        const commentId = deleteCommentId.value;
        const commentSection = document.getElementById(`comment-section-${postId}`);
        const commentBtn = document.querySelector(`.comment-btn[data-post-id="${postId}"]`);

        fetch(`group-page.php?group_id=<?php echo $group_id; ?>`, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const comment = commentSection.querySelector(`.comment[data-comment-id="${commentId}"]`);
                if (comment) {
                    let current = comment.nextElementSibling;
                    while (current && current.classList.contains('reply')) {
                        const next = current.nextElementSibling;
                        current.remove();
                        current = next;
                    }
                    comment.remove();
                }

                commentBtn.setAttribute('data-comment-count', data.comment_count);
                commentBtn.querySelector('.comment-text').textContent = commentSection.classList.contains('show') 
                    ? 'Hide Comments' 
                    : `Comment (${data.comment_count})`;

                deleteCommentModal.classList.remove('show');
                deleteCommentModal.classList.add('hidden');
            } else {
                console.error('Error deleting comment:', data.error);
            }
        })
        .catch(error => console.error('Fetch error:', error));
    });
});
</script>

</body>
</html>