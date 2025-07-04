<?php
session_start();
require 'db_connect.php';

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle logout
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    if (isset($_COOKIE['session_id'])) {
        setcookie('session_id', '', time() - 3600, '/');
    }
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
try {
    $stmt = $pdo->prepare("SELECT username, name, email, profile_picture FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    if (!$user) {
        session_destroy();
        header("Location: index.php");
        exit;
    }
} catch (PDOException $e) {
    error_log("Error fetching user: " . $e->getMessage());
    header("Location: index.php");
    exit;
}

// Validate CSRF token for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['logout'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
}

// Handle post creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_content'])) {
    $description = trim($_POST['post_content']);
    $content = $description;
    $media_type = null;
    $media_url = null;
    $error = null;

    if (!empty($_FILES['media']['name'])) {
        $upload_dir = 'Uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        $file_name = basename($_FILES['media']['name']);
        $target_file = $upload_dir . time() . '_' . $file_name;
        $file_type = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        $allowed_types = [
            'image' => ['jpg', 'jpeg', 'png', 'gif'],
            'video' => ['mp4', 'webm', 'ogg'],
            'document' => ['doc', 'docx', 'pdf'],
            'audio' => ['mp3', 'wav']
        ];

        foreach ($allowed_types as $type => $extensions) {
            if (in_array($file_type, $extensions)) {
                $media_type = $type;
                break;
            }
        }

        if ($media_type && move_uploaded_file($_FILES['media']['tmp_name'], $target_file)) {
            $media_url = $target_file;
            if ($media_type === 'document' || $media_type === 'audio') {
                $content = $file_name;
            }
        } else {
            $error = "Invalid or unsupported file type.";
        }
    }

    if (!empty($content) || $media_url) {
        try {
            $stmt = $pdo->prepare("INSERT INTO posts (user_id, content, description, media_type, media_url, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$user_id, $content, $description ?: null, $media_type, $media_url]);
            $post_id = $pdo->lastInsertId();

            // Fetch new post data
            $stmt = $pdo->prepare("
                SELECT p.post_id, p.user_id, p.content, p.description, p.media_type, p.media_url, p.created_at, 
                       u.username, u.profile_picture,
                       (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.post_id) AS like_count,
                       (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.post_id AND l.user_id = ?) AS is_liked_by_user,
                       (SELECT GROUP_CONCAT(u2.username ORDER BY l2.created_at ASC SEPARATOR ',') FROM likes l2 
                        JOIN users u2 ON l2.user_id = u2.user_id 
                        WHERE l2.post_id = p.post_id 
                        LIMIT 3) AS liker_usernames,
                       (SELECT GROUP_CONCAT(u2.profile_picture ORDER BY l2.created_at ASC SEPARATOR ',') FROM likes l2 
                        JOIN users u2 ON l2.user_id = u2.user_id 
                        WHERE l2.post_id = p.post_id 
                        LIMIT 3) AS liker_profile_pictures,
                       (SELECT COUNT(*) FROM bookmarks b WHERE b.post_id = p.post_id AND b.user_id = ?) AS is_bookmarked
                FROM posts p
                JOIN users u ON p.user_id = u.user_id
                WHERE p.post_id = ?
            ");
            $stmt->execute([$user_id, $user_id, $post_id]);
            $new_post = $stmt->fetch();

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'post' => [
                    'post_id' => $new_post['post_id'],
                    'user_id' => $new_post['user_id'],
                    'content' => htmlspecialchars($new_post['content']),
                    'description' => htmlspecialchars($new_post['description'] ?? ''),
                    'media_type' => $new_post['media_type'],
                    'media_url' => $new_post['media_url'],
                    'created_at' => $new_post['created_at'],
                    'username' => htmlspecialchars($new_post['username']),
                    'profile_picture' => htmlspecialchars($new_post['profile_picture'] ?? './profile_pics/profile.jpg'),
                    'like_count' => (int)$new_post['like_count'],
                    'is_liked_by_user' => (int)$new_post['is_liked_by_user'],
                    'liker_usernames' => $new_post['liker_usernames'] ?? '',
                    'liker_profile_pictures' => $new_post['liker_profile_pictures'] ?? '',
                    'is_bookmarked' => (int)$new_post['is_bookmarked']
                ]
            ]);
            exit;
        } catch (PDOException $e) {
            error_log("Error creating post: " . $e->getMessage());
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Database error while creating post']);
            exit;
        }
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $error ?? "Post content or media is required."
        ]);
        exit;
    }
}

// Handle post editing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_post'])) {
    $post_id = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
    $new_description = trim($_POST['new_description']);
    if ($post_id <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid post ID']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("SELECT user_id FROM posts WHERE post_id = ?");
        $stmt->execute([$post_id]);
        $post = $stmt->fetch();
        if ($post && $post['user_id'] == $user_id) {
            $stmt = $pdo->prepare("UPDATE posts SET description = ? WHERE post_id = ?");
            $stmt->execute([$new_description ?: null, $post_id]);
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Unauthorized or post not found']);
        }
    } catch (PDOException $e) {
        error_log("Error editing post: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Database error while editing post']);
    }
    exit;
}

// Handle post deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_post'])) {
    $post_id = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
    if ($post_id <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid post ID']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("SELECT user_id, media_url FROM posts WHERE post_id = ?");
        $stmt->execute([$post_id]);
        $post = $stmt->fetch();
        if ($post && $post['user_id'] == $user_id) {
            if ($post['media_url'] && file_exists($post['media_url'])) {
                unlink($post['media_url']);
            }
            $stmt = $pdo->prepare("DELETE FROM likes WHERE post_id = ?");
            $stmt->execute([$post_id]);
            $stmt = $pdo->prepare("DELETE FROM bookmarks WHERE post_id = ?");
            $stmt->execute([$post_id]);
            $stmt = $pdo->prepare("DELETE FROM comments WHERE post_id = ?");
            $stmt->execute([$post_id]);
            $stmt = $pdo->prepare("DELETE FROM posts WHERE post_id = ?");
            $success = $stmt->execute([$post_id]);
            header('Content-Type: application/json');
            echo json_encode(['success' => $success, 'error' => $success ? null : 'Failed to delete post']);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Unauthorized or post not found']);
        }
    } catch (PDOException $e) {
        error_log("Error deleting post: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Database error while deleting post']);
    }
    exit;
}

// Handle liking/unliking a post
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['like_post'])) {
    $post_id = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
    if ($post_id <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid post ID']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("SELECT post_id FROM posts WHERE post_id = ?");
        $stmt->execute([$post_id]);
        if (!$stmt->fetch()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Post not found']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT 1 FROM likes WHERE user_id = ? AND post_id = ?");
        $stmt->execute([$user_id, $post_id]);
        $existing_like = $stmt->fetch();
        if ($existing_like) {
            $stmt = $pdo->prepare("DELETE FROM likes WHERE user_id = ? AND post_id = ?");
            $stmt->execute([$user_id, $post_id]);
            $is_liked = false;
        } else {
            $stmt = $pdo->prepare("INSERT INTO likes (user_id, post_id, created_at) VALUES (?, ?, NOW())");
            $stmt->execute([$user_id, $post_id]);
            $is_liked = true;
        }
        $stmt = $pdo->prepare("
            SELECT 
                (SELECT COUNT(*) FROM likes l WHERE l.post_id = ?) AS like_count,
                (SELECT GROUP_CONCAT(u2.username ORDER BY l2.created_at ASC SEPARATOR ',') FROM likes l2 
                 JOIN users u2 ON l2.user_id = u2.user_id 
                 WHERE l2.post_id = ? 
                 LIMIT 3) AS liker_usernames,
                (SELECT GROUP_CONCAT(u2.profile_picture ORDER BY l2.created_at ASC SEPARATOR ',') FROM likes l2 
                 JOIN users u2 ON l2.user_id = u2.user_id 
                 WHERE l2.post_id = ? 
                 LIMIT 3) AS liker_profile_pictures
        ");
        $stmt->execute([$post_id, $post_id, $post_id]);
        $like_data = $stmt->fetch();
        header('Content-Type', 'application/json');
        echo json_encode([
            'success' => true,
            'like_count' => (int)$like_data['like_count'],
            'liker_usernames' => $like_data['liker_usernames'] ?? '',
            'liker_profile_pics' => $like_data['liker_profile_pictures'] ?? '',
            'is_liked' => $is_liked
        ]);
    } catch (PDOException $e) {
        error_log("Error handling like: " . $e->getMessage() . " | Post ID: $post_id | User ID: $user_id");
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit();
}

// Handle bookmarking/unbookmarking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bookmark_post'])) {
    $post_id = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
    if ($post_id <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid post ID']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM bookmarks WHERE user_id = ? AND post_id = ?");
        $stmt->execute([$user_id, $post_id]);
        $existing_bookmark = $stmt->fetch();
        if ($existing_bookmark) {
            $stmt = $pdo->prepare("DELETE FROM bookmarks WHERE user_id = ? AND post_id = ?");
            $stmt->execute([$user_id, $post_id]);
            $is_bookmarked = false;
        } else {
            $stmt = $pdo->prepare("INSERT INTO bookmarks (user_id, post_id, created_at) VALUES (?, ?, NOW())");
            $stmt->execute([$user_id, $post_id]);
            $is_bookmarked = true;
        }
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'is_bookmarked' => $is_bookmarked
        ]);
    } catch (PDOException $e) {
        error_log("Error handling bookmark: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit;
}

// Handle comment creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment'])) {
    $post_id = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
    $content = trim($_POST['comment_content']);
    $parent_comment_id = !empty($_POST['parent_comment_id']) ? (int)$_POST['parent_comment_id'] : null;

    if ($post_id <= 0 || empty($content)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid post ID or comment content']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT post_id FROM posts WHERE post_id = ?");
        $stmt->execute([$post_id]);
        if (!$stmt->fetch()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid post ID']);
            exit;
        }
        
        $parent_username = null;
        if ($parent_comment_id) {
            $stmt = $pdo->prepare("SELECT c.comment_id, u.username FROM comments c JOIN users u ON c.user_id = u.user_id WHERE c.comment_id = ? AND c.post_id = ?");
            $stmt->execute([$parent_comment_id, $post_id]);
            $parent_comment = $stmt->fetch();
            if (!$parent_comment) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Invalid parent comment ID']);
                exit;
            }
            $parent_username = $parent_comment['username'];
        }
        
        $stmt = $pdo->prepare("INSERT INTO comments (post_id, user_id, parent_comment_id, content, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$post_id, $user_id, $parent_comment_id, $content]);
        $comment_id = $pdo->lastInsertId();

        $stmt = $pdo->prepare("
            SELECT c.comment_id, c.post_id, c.user_id, c.parent_comment_id, c.content, c.created_at, 
                   u.username, u.profile_picture
            FROM comments c
            JOIN users u ON c.user_id = u.user_id
            WHERE c.comment_id = ?
        ");
        $stmt->execute([$comment_id]);
        $new_comment = $stmt->fetch();

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'comment' => [
                'comment_id' => $new_comment['comment_id'],
                'post_id' => $new_comment['post_id'],
                'user_id' => $new_comment['user_id'],
                'parent_comment_id' => $new_comment['parent_comment_id'],
                'content' => htmlspecialchars($new_comment['content']),
                'created_at' => $new_comment['created_at'],
                'username' => htmlspecialchars($new_comment['username']),
                'profile_picture' => htmlspecialchars($new_comment['profile_picture'] ?? './profile_pics/profile.jpg'),
                'parent_username' => $parent_username ? htmlspecialchars($parent_username) : null
            ]
        ]);
    } catch (PDOException $e) {
        error_log("Error creating comment: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit;
}

// Handle comment deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_comment'])) {
    $comment_id = isset($_POST['comment_id']) ? (int)$_POST['comment_id'] : 0;
    if ($comment_id <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid comment ID']);
        exit;
    }
    try {
        // Pārbaudām, vai komentārs pieder lietotājam
        $stmt = $pdo->prepare("SELECT user_id FROM comments WHERE comment_id = ?");
        $stmt->execute([$comment_id]);
        $comment = $stmt->fetch();
        
        if ($comment && $comment['user_id'] == $user_id) {
            // Vispirms dzēšam visus atbildes komentārus
            $stmt = $pdo->prepare("DELETE FROM comments WHERE parent_comment_id = ?");
            $stmt->execute([$comment_id]);
            
            // Tad dzēšam galveno komentāru
            $stmt = $pdo->prepare("DELETE FROM comments WHERE comment_id = ?");
            $success = $stmt->execute([$comment_id]);
            
            header('Content-Type: application/json');
            echo json_encode(['success' => $success, 'error' => $success ? null : 'Failed to delete comment']);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Unauthorized or comment not found']);
        }
    } catch (PDOException $e) {
        error_log("Error deleting comment: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit;
}

// Handle get post owner
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_post_owner'])) {
    $post_id = isset($_GET['post_id']) ? (int)$_GET['post_id'] : 0;
    
    if ($post_id <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid post ID']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT u.username 
            FROM posts p 
            JOIN users u ON p.user_id = u.user_id 
            WHERE p.post_id = ?
        ");
        $stmt->execute([$post_id]);
        $result = $stmt->fetch();
        
        if ($result) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'username' => htmlspecialchars($result['username'])
            ]);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Post not found']);
        }
    } catch (PDOException $e) {
        error_log("Error fetching post owner: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit;
}

// Fetch posts
try {
    $stmt = $pdo->prepare("
        SELECT p.post_id, p.user_id, p.content, p.description, p.media_type, p.media_url, p.created_at, 
               u.username, u.profile_picture,
               (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.post_id) AS like_count,
               (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.post_id AND l.user_id = ?) AS is_liked_by_user,
               (SELECT GROUP_CONCAT(u2.username ORDER BY l2.created_at ASC SEPARATOR ',') FROM likes l2 
                JOIN users u2 ON l2.user_id = u2.user_id 
                WHERE l2.post_id = p.post_id LIMIT 3) AS liker_usernames,
               (SELECT GROUP_CONCAT(u2.profile_picture ORDER BY l2.created_at ASC SEPARATOR ',') FROM likes l2 
                JOIN users u2 ON l2.user_id = u2.user_id 
                WHERE l2.post_id = p.post_id LIMIT 3) AS liker_profile_pictures,
               (SELECT COUNT(*) FROM bookmarks b WHERE b.post_id = p.post_id AND b.user_id = ?) AS is_bookmarked,
               (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.post_id) AS comment_count
        FROM posts p
        JOIN users u ON p.user_id = u.user_id
        ORDER BY p.created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$user_id, $user_id]);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $comments = [];
    foreach ($posts as $post) {
        $stmt = $pdo->prepare("
            SELECT c.comment_id, c.post_id, c.user_id, c.parent_comment_id, c.content, c.created_at, 
                   u.username, u.profile_picture,
                   (SELECT u2.username FROM comments c2 JOIN users u2 ON c2.user_id = u2.user_id WHERE c2.comment_id = c.parent_comment_id) AS parent_username
            FROM comments c
            JOIN users u ON c.user_id = u.user_id
            WHERE c.post_id = ?
            ORDER BY c.created_at ASC
        ");
        $stmt->execute([$post['post_id']]);
        $comments[$post['post_id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Error fetching posts/comments: " . $e->getMessage());
    $posts = [];
    $comments = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="./img/login.jpg">
    <link rel="stylesheet" href="./css/firstpage.css">
    <link rel="stylesheet" href="https://unicons.iconscout.com/release/v4.0.8/css/line.css">
    <title>OmniVox</title>
    <style>
        #sharePostModal .modal-content {
            width: 400px;
            max-height: 80vh;
            overflow-y: auto;
        }
        #sharePostModal .search-bar {
            margin-bottom: 10px;
        }
        #sharePostModal .search-bar input {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 20px;
            font-size: 14px;
        }
        #sharePostModal .user-list {
            max-height: 60vh;
            overflow-y: auto;
        }
        #sharePostModal .user-item {
            display: flex;
            align-items: center;
            padding: 10px;
            border-bottom: 1px solid #eee;
            cursor: pointer;
        }
        #sharePostModal .user-item:hover {
            background-color: #f5f5f5;
        }
        #sharePostModal .user-item .profile-photo {
            width: 40px;
            height: 40px;
            margin-right: 10px;
        }
        #sharePostModal .user-item .user-info h4 {
            margin: 0;
            font-size: 14px;
        }
        #sharePostModal .user-item .user-info p {
            margin: 0;
            font-size: 12px;
            color: #888;
        }
        .notification-count:empty, .notification-count[data-count="0"] {
            display: none;
        }
        .like-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .replying-to {
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
            font-style: italic;
        }
        .replying-to .username {
            color: #007bff;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <nav>
        <div class="container">
            <h2 class="log">Online</h2>
            <div class="search-bar"></div>
            <div class="profile-photo">
                <img src="<?php echo htmlspecialchars($user['profile_picture'] ?? './profile_pics/profile.jpg'); ?>" alt="Profile">
            </div>
        </div>
    </nav>

    <main>
        <div class="container">
            <?php include 'left.php'; ?>
            <div class="middle">
                <div class="create-post">
                    <div class="profile-photo">
                        <img src="<?php echo htmlspecialchars($user['profile_picture'] ?? './profile_pics/profile.jpg'); ?>" alt="Profile">
                    </div>
                    <div class="create-post-content">
                        <input type="text" placeholder="Share what's on your mind, <?php echo htmlspecialchars($user['name'] ?? $user['username']); ?>..." onclick="document.getElementById('createPostModal').classList.remove('hidden')">
                        <p class="file-name"></p>
                    </div>
                </div>

                <div id="createPostModal" class="modal hidden">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h3>Create a post</h3>
                            <span class="close">×</span>
                        </div>
                        <div class="modal-body">
                            <div class="user-info">
                                <div class="profile-photo">
                                    <img src="<?php echo htmlspecialchars($user['profile_picture'] ?? './profile_pics/profile.jpg'); ?>" alt="Profile">
                                </div>
                                <div class="username">
                                    <h4><?php echo htmlspecialchars($user['name'] ?? $user['username']); ?></h4>
                                    <p class="text-muted">@<?php echo htmlspecialchars($user['username']); ?></p>
                                </div>
                            </div>
                            <?php if (isset($error)): ?>
                                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
                            <?php endif; ?>
                            <form id="create-post-form" action="firstpage.php" method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <textarea name="post_content" placeholder="Share what's on your mind, <?php echo htmlspecialchars($user['name'] ?? $user['username']); ?>..." rows="4"></textarea>
                                <input type="file" name="media" id="media-upload" style="display: none;" accept="">
                                <div class="post-actions">
                                    <i class="uil uil-image" data-type="image" title="Upload Image"></i>
                                    <i class="uil uil-video" data-type="video" title="Upload Video"></i>
                                    <i class="uil uil-paperclip" data-type="document" title="Upload Document"></i>
                                    <i class="uil uil-headphones-alt" data-type="audio" title="Upload Audio"></i>
                                </div>
                                <button type="submit" class="btn btn-primary">Post</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div id="sharePostModal" class="modal hidden">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h3>Share Post</h3>
                            <span class="close">×</span>
                        </div>
                        <div class="modal-body">
                            <div class="search-bar">
                                <input type="text" id="share-user-search" placeholder="Search users...">
                            </div>
                            <div class="user-list" id="share-user-list"></div>
                        </div>
                    </div>
                </div>

                <div class="feeds">
                    <?php foreach ($posts as $post): ?>
                        <div class="feed" data-post-id="<?php echo $post['post_id']; ?>">
                            <div class="head">
                                <div class="user">
                                    <div class="profile-photo">
                                        <img src="<?php echo htmlspecialchars($post['profile_picture'] ?? './profile_pics/profile.jpg'); ?>" alt="Profile">
                                    </div>
                                    <div class="info">
                                        <h3><?php echo htmlspecialchars($post['username']); ?></h3>
                                        <small><?php echo str_replace(',', '', date('M d, Y H:i', strtotime($post['created_at']))); ?></small>
                                    </div>
                                </div>
                                <?php if ($post['user_id'] == $user_id): ?>
                                    <span class="edit">
                                        <i class="uil uil-ellipsis-h"></i>
                                        <div class="edit-dropdown hidden">
                                            <div class="edit-option" data-post-id="<?php echo $post['post_id']; ?>">Edit</div>
                                            <button class="delete-option" data-post-id="<?php echo $post['post_id']; ?>">Delete</button>
                                        </div>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <?php if ($post['media_type']): ?>
                                <div class="photo">
                                    <?php if ($post['media_type'] === 'image'): ?>
                                        <img src="<?php echo htmlspecialchars($post['media_url']); ?>" alt="Post image">
                                    <?php elseif ($post['media_type'] === 'video'): ?>
                                        <video autoplay muted loop controls>
                                            <source src="<?php echo htmlspecialchars($post['media_url']); ?>" type="video/mp4">
                                        </video>
                                    <?php elseif ($post['media_type'] === 'document' || $post['media_type'] === 'audio'): ?>
                                        <div class="file-post">
                                            <p class="file-name"><?php echo htmlspecialchars($post['content']); ?></p>
                                            <a href="<?php echo htmlspecialchars($post['media_url']); ?>" download class="btn btn-primary">Download</a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <div class="action-buttons">
                                <div class="interaction-buttons">
                                    <span class="like-btn" 
                                          data-post-id="<?php echo $post['post_id']; ?>" 
                                          data-like-count="<?php echo $post['like_count']; ?>" 
                                          data-likers="<?php echo htmlspecialchars($post['liker_usernames'] ?? ''); ?>" 
                                          data-liker-pics="<?php echo htmlspecialchars($post['liker_profile_pictures'] ?? ''); ?>">
                                        <i class="uil uil-heart<?php echo $post['is_liked_by_user'] ? ' liked' : ''; ?>"></i>
                                        <span class="like-count" style="display: none;"><?php echo $post['like_count'] > 0 ? $post['like_count'] : ''; ?></span>
                                    </span>
                                    <span><i class="uil uil-comment-dots"></i></span>
                                    <span><i class="uil uil-share"></i></span>
                                </div>
                                <div class="bookmark">
                                    <span class="bookmark-btn" data-post-id="<?php echo $post['post_id']; ?>">
                                        <i class="uil uil-bookmark<?php echo $post['is_bookmarked'] ? ' bookmarked' : ''; ?>"></i>
                                    </span>
                                </div>
                            </div>

                            <div class="liked-by">
                                <div class="liker-info" style="<?php echo $post['like_count'] > 0 ? 'display: flex;' : 'display: none;'; ?>">
                                    <?php if ($post['like_count'] > 0): ?>
                                        <div class="liker-avatars">
                                            <?php
                                            $liker_pics = explode(',', $post['liker_profile_pictures'] ?? '');
                                            for ($i = 0; $i < min(count($liker_pics), 3); $i++):
                                            ?>
                                                <div class="profile-photo avatar-<?php echo $i + 1; ?>">
                                                    <img src="<?php echo htmlspecialchars($liker_pics[$i] ?: './profile_pics/profile.jpg'); ?>" alt="Liker Profile">
                                                </div>
                                            <?php endfor; ?>
                                        </div>
                                        <p>
                                            <?php
                                            $liker_names = explode(',', $post['liker_usernames'] ?? '');
                                            if ($post['like_count'] == 1) {
                                                echo 'Liked by <b>' . htmlspecialchars($liker_names[0]) . '</b>';
                                            } elseif ($post['like_count'] == 2) {
                                                echo 'Liked by <b>' . htmlspecialchars($liker_names[0]) . '</b> and <b>' . htmlspecialchars($liker_names[1]) . '</b>';
                                            } else {
                                                echo 'Liked by <b>' . htmlspecialchars($liker_names[0]) . '</b> and <b>' . ($post['like_count'] - 1) . ' others</b>';
                                            }
                                            ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (!empty($post['description'])): ?>
                                <div class="description">
                                    <p><b><?php echo htmlspecialchars($post['username']); ?></b>: <?php echo htmlspecialchars($post['description']); ?></p>
                                </div>
                            <?php endif; ?>

                            <div class="comment-section" style="display: none;">
                                <div class="comment-input">
                                    <div class="profile-photo">
                                        <img src="<?php echo htmlspecialchars($user['profile_picture'] ?? './profile_pics/profile.jpg'); ?>" alt="Profile">
                                    </div>
                                    <form class="comment-form" data-post-id="<?php echo $post['post_id']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="add_comment" value="1">
                                        <input type="hidden" name="post_id" value="<?php echo $post['post_id']; ?>">
                                        <textarea name="comment_content" placeholder="Write a comment..." rows="2"></textarea>
                                        <button type="submit" class="btn btn-primary">Comment</button>
                                    </form>
                                </div>
                                <div class="comment-list">
                                    <?php
                                    $post_comments = isset($comments[$post['post_id']]) ? $comments[$post['post_id']] : [];
                                    $top_level_comments = array_filter($post_comments, fn($c) => is_null($c['parent_comment_id']));
                                    foreach ($top_level_comments as $comment):
                                        $replies = array_filter($post_comments, fn($c) => $c['parent_comment_id'] == $comment['comment_id']);
                                    ?>
                                        <div class="comment" data-comment-id="<?php echo $comment['comment_id']; ?>">
                                            <div class="comment-content">
                                                <div class="profile-photo">
                                                    <img src="<?php echo htmlspecialchars($comment['profile_picture'] ?? './profile_pics/profile.jpg'); ?>" alt="Profile">
                                                </div>
                                                <div class="comment-info">
                                                    <?php if ($comment['parent_username']): ?>
                                                        <div class="replying-to">
                                                            Replying to <span class="username">@<?php echo htmlspecialchars($comment['parent_username']); ?></span>
                                                        </div>
                                                    <?php endif; ?>
                                                    <h4><?php echo htmlspecialchars($comment['username']); ?></h4>
                                                    <small><?php echo str_replace(',', '', date('M d, Y H:i', strtotime($comment['created_at']))); ?></small>
                                                    <p><?php echo htmlspecialchars($comment['content']); ?></p>
                                                    <div class="comment-actions">
                                                        <span class="reply-btn" data-username="<?php echo htmlspecialchars($comment['username']); ?>">Reply</span>
                                                        <?php if ($comment['user_id'] == $user_id): ?>
                                                            <span class="delete-comment-btn" data-comment-id="<?php echo $comment['comment_id']; ?>">Delete</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="replies">
                                                <?php foreach ($replies as $reply): ?>
                                                    <div class="comment reply" data-comment-id="<?php echo $reply['comment_id']; ?>">
                                                        <div class="comment-content">
                                                            <div class="profile-photo">
                                                                <img src="<?php echo htmlspecialchars($reply['profile_picture'] ?? './profile_pics/profile.jpg'); ?>" alt="Profile">
                                                            </div>
                                                            <div class="comment-info">
                                                                <?php if ($reply['parent_username']): ?>
                                                                    <div class="replying-to">
                                                                        Replying to <span class="username">@<?php echo htmlspecialchars($reply['parent_username']); ?></span>
                                                                    </div>
                                                                <?php endif; ?>
                                                                <h4><?php echo htmlspecialchars($reply['username']); ?></h4>
                                                                <small><?php echo str_replace(',', '', date('M d, Y H:i', strtotime($reply['created_at']))); ?></small>
                                                                <p><?php echo htmlspecialchars($reply['content']); ?></p>
                                                                <div class="comment-actions">
                                                                    <span class="reply-btn" data-username="<?php echo htmlspecialchars($reply['username']); ?>">Reply</span>
                                                                    <?php if ($reply['user_id'] == $user_id): ?>
                                                                        <span class="delete-comment-btn" data-comment-id="<?php echo $reply['comment_id']; ?>">Delete</span>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="reply-form hidden" data-parent-comment-id="<?php echo $reply['comment_id']; ?>" data-parent-username="<?php echo htmlspecialchars($reply['username']); ?>">
                                                            <div class="replying-to">
                                                                Replying to <span class="username">@<?php echo htmlspecialchars($reply['username']); ?></span>
                                                            </div>
                                                            <form class="comment-form" data-post-id="<?php echo $post['post_id']; ?>">
                                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                                <input type="hidden" name="add_comment" value="1">
                                                                <input type="hidden" name="post_id" value="<?php echo $post['post_id']; ?>">
                                                                <input type="hidden" name="parent_comment_id" value="<?php echo $reply['comment_id']; ?>">
                                                                <textarea name="comment_content" placeholder="Write a reply..." rows="2"></textarea>
                                                                <button type="submit" class="btn btn-primary">Reply</button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="reply-form hidden" data-parent-comment-id="<?php echo $comment['comment_id']; ?>" data-parent-username="<?php echo htmlspecialchars($comment['username']); ?>">
                                                <div class="replying-to">
                                                    Replying to <span class="username">@<?php echo htmlspecialchars($comment['username']); ?></span>
                                                </div>
                                                <form class="comment-form" data-post-id="<?php echo $post['post_id']; ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="add_comment" value="1">
                                                    <input type="hidden" name="post_id" value="<?php echo $post['post_id']; ?>">
                                                    <input type="hidden" name="parent_comment_id" value="<?php echo $comment['comment_id']; ?>">
                                                    <textarea name="comment_content" placeholder="Write a reply..." rows="2"></textarea>
                                                    <button type="submit" class="btn btn-primary">Reply</button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="comment-count text-muted"><?php echo $post['comment_count'] > 0 ? "View all {$post['comment_count']} comments" : 'No comments yet'; ?></div>
                            </div>

                            <?php if ($post['user_id'] == $user_id): ?>
                                <div id="editPostModal-<?php echo $post['post_id']; ?>" class="modal hidden">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h3>Edit Post Description</h3>
                                            <span class="close">×</span>
                                        </div>
                                        <div class="modal-body">
                                            <form class="edit-post-form" data-post-id="<?php echo $post['post_id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="post_id" value="<?php echo $post['post_id']; ?>">
                                                <input type="hidden" name="edit_post" value="1">
                                                <textarea name="new_description" rows="4" placeholder="Edit your description..."><?php echo htmlspecialchars($post['description'] ?? ''); ?></textarea>
                                                <button type="submit" class="btn btn-primary">Save</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </main>

    <script>
        window.currentUser = {
            id: <?php echo json_encode($user_id); ?>,
            username: <?php echo json_encode($user['username']); ?>,
            profile_picture: <?php echo json_encode($user['profile_picture'] ?? './profile_pics/profile.jpg'); ?>
        };

        document.addEventListener('DOMContentLoaded', function() {
            function formatDate(date) {
                const options = {
                    month: 'short',
                    day: '2-digit',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: false
                };
                return date.toLocaleString('en-US', options)
                    .replace(/,/, '')
                    .replace(/(\w{3} \d{2} \d{4}), (\d{2}:\d{2})/, '$1 $2');
            }

            function updateLikedBySection(likeBtn, likeCount, likerUsernames, likerProfilePics, likedBy) {
                const usernames = (likerUsernames || '').split(',').filter(Boolean);
                const profilePics = (likerProfilePics || '').split(',').filter(Boolean);
                if (likeCount > 0) {
                    let avatarsHTML = '';
                    for (let i = 0; i < Math.min(usernames.length, 3); i++) {
                        avatarsHTML += `
                            <div class="profile-photo avatar-${i + 1}">
                                <img src="${profilePics[i] || './profile_pics/profile.jpg'}" alt="Liker Profile">
                            </div>
                        `;
                    }
                    let text = '';
                    if (likeCount === 1) {
                        text = `Liked by <b>${usernames[0]}</b>`;
                    } else if (likeCount === 2) {
                        text = `Liked by <b>${usernames[0]}</b> and <b>${usernames[1]}</b>`;
                    } else {
                        text = `Liked by <b>${usernames[0]}</b> and <b>${likeCount - 1} others</b>`;
                    }
                    likedBy.innerHTML = `
                        <div class="liker-avatars">${avatarsHTML}</div>
                        <p>${text}</p>
                    `;
                    likedBy.style.display = 'flex';
                } else {
                    likedBy.innerHTML = '';
                    likedBy.style.display = 'none';
                }
            }

            // Like button handler
            document.body.addEventListener('click', function(e) {
                const likeBtn = e.target.closest('.like-btn');
                if (!likeBtn || likeBtn.classList.contains('disabled')) return;

                e.preventDefault();
                const postId = likeBtn.getAttribute('data-post-id');
                if (!postId || isNaN(postId)) {
                    console.error('Invalid post ID:', postId);
                    alert('Invalid post ID');
                    return;
                }
                let likeCount = parseInt(likeBtn.getAttribute('data-like-count'));
                const originalLikerUsernames = likeBtn.getAttribute('data-likers');
                const originalLikerProfilePics = likeBtn.getAttribute('data-liker-pics');
                let likerUsernames = (originalLikerUsernames || '').split(',').filter(Boolean);
                let likerProfilePics = (originalLikerProfilePics || '').split(',').filter(Boolean);
                const likeIcon = likeBtn.querySelector('i');
                const likedBy = likeBtn.closest('.feed').querySelector('.liked-by .liker-info');
                const isLiked = likeIcon.classList.contains('liked');
                const currentUser = window.currentUser.username;
                const currentUserPic = window.currentUser.profile_picture;

                // Optimistic UI update
                if (isLiked) {
                    likeIcon.classList.remove('liked');
                    likeCount--;
                    likerUsernames = likerUsernames.filter(name => name !== currentUser);
                    likerProfilePics = likerProfilePics.filter(pic => pic !== currentUserPic);
                } else {
                    likeIcon.classList.add('liked');
                    likeCount++;
                    if (!likerUsernames.includes(currentUser)) {
                        likerUsernames.unshift(currentUser);
                        likerProfilePics.unshift(currentUserPic);
                    }
                }

                likeBtn.setAttribute('data-like-count', likeCount);
                likeBtn.setAttribute('data-likers', likerUsernames.join(','));
                likeBtn.setAttribute('data-liker-pics', likerProfilePics.join(','));
                updateLikedBySection(likeBtn, likeCount, likerUsernames.join(','), likerProfilePics.join(','), likedBy);

                // Server update
                likeBtn.classList.add('disabled');
                fetch('firstpage.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `like_post=1&post_id=${encodeURIComponent(postId)}&csrf_token=<?php echo urlencode($_SESSION['csrf_token']); ?>`
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! Status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    likeBtn.classList.remove('disabled');
                    if (data.success) {
                        likeBtn.setAttribute('data-like-count', data.like_count);
                        likeBtn.setAttribute('data-likers', data.liker_usernames);
                        likeBtn.setAttribute('data-liker-pics', data.liker_profile_pics);
                        likeIcon.classList.toggle('liked', data.is_liked);
                        updateLikedBySection(likeBtn, data.like_count, data.liker_usernames, data.liker_profile_pics, likedBy);
                    } else {
                        likeIcon.classList.toggle('liked', isLiked);
                        likeCount = parseInt(likeBtn.getAttribute('data-like-count'));
                        updateLikedBySection(likeBtn, likeCount, originalLikerUsernames, originalLikerProfilePics, likedBy);
                        alert(data.error || 'Failed to update like');
                    }
                })
                .catch(error => {
                    likeBtn.classList.remove('disabled');
                    console.error('Error updating like:', error);
                    likeIcon.classList.toggle('liked', isLiked);
                    likeCount = parseInt(likeBtn.getAttribute('data-like-count'));
                    updateLikedBySection(likeBtn, likeCount, originalLikerUsernames, originalLikerProfilePics, likedBy);
                    alert(`Failed to update like: ${error.message}`);
                });
            });

            // Bookmark button handler
            document.body.addEventListener('click', function(e) {
                const bookmarkBtn = e.target.closest('.bookmark-btn');
                if (!bookmarkBtn) return;

                e.preventDefault();
                const postId = bookmarkBtn.getAttribute('data-post-id');
                if (!postId || isNaN(postId)) {
                    console.error('Invalid post ID:', postId);
                    alert('Invalid post ID');
                    return;
                }
                const bookmarkIcon = bookmarkBtn.querySelector('i');
                const isBookmarked = bookmarkIcon.classList.contains('bookmarked');

                if (isBookmarked) {
                    bookmarkIcon.classList.remove('bookmarked');
                } else {
                    bookmarkIcon.classList.add('bookmarked');
                }

                fetch('firstpage.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `bookmark_post=1&post_id=${encodeURIComponent(postId)}&csrf_token=<?php echo urlencode($_SESSION['csrf_token']); ?>`
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! Status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        bookmarkIcon.classList.toggle('bookmarked', data.is_bookmarked);
                    } else {
                        bookmarkIcon.classList.toggle('bookmarked', isBookmarked);
                        alert(data.error || 'Failed to update bookmark');
                    }
                })
                .catch(error => {
                    console.error('Error updating bookmark:', error);
                    bookmarkIcon.classList.toggle('bookmarked', isBookmarked);
                    alert(`Failed to update bookmark: ${error.message}`);
                });
            });

            // Post creation
            const postForm = document.getElementById('create-post-form');
            if (postForm) {
                postForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const formData = new FormData(this);
                    const modal = document.getElementById('createPostModal');
                    const errorDiv = modal.querySelector('.error-message');

                    fetch('firstpage.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`HTTP error! Status: ${response.status}`);
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            location.reload(); // Simple solution for post creation
                        } else {
                            if (!errorDiv) {
                                const newErrorDiv = document.createElement('div');
                                newErrorDiv.classList.add('error-message');
                                newErrorDiv.textContent = data.error;
                                modal.querySelector('.modal-body').insertBefore(newErrorDiv, postForm);
                            } else {
                                errorDiv.textContent = data.error;
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error creating post:', error);
                        alert(`Error creating post: ${error.message}`);
                    });
                });
            }

            // Post deletion
            document.body.addEventListener('click', function(e) {
                const deleteBtn = e.target.closest('.delete-option');
                if (!deleteBtn) return;

                e.preventDefault();
                const postId = deleteBtn.getAttribute('data-post-id');
                const feed = deleteBtn.closest('.feed');
                if (!postId || isNaN(postId)) {
                    console.error('Invalid post ID:', postId);
                    alert('Invalid post ID');
                    return;
                }

                if (confirm('Are you sure you want to delete this post?')) {
                    fetch('firstpage.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `delete_post=1&post_id=${encodeURIComponent(postId)}&csrf_token=<?php echo urlencode($_SESSION['csrf_token']); ?>`
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`HTTP error! Status: ${response.status}`);
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            feed.remove();
                        } else {
                            alert(data.error || 'Failed to delete post');
                        }
                    })
                    .catch(error => {
                        console.error('Error deleting post:', error);
                        alert(`Error deleting post: ${error.message}`);
                    });
                }
            });

            // Post editing - FIXED VERSION
            document.body.addEventListener('submit', function(e) {
                const editForm = e.target.closest('.edit-post-form');
                if (!editForm) return;

                e.preventDefault();
                const postId = editForm.getAttribute('data-post-id');
                if (!postId || isNaN(postId)) {
                    console.error('Invalid post ID:', postId);
                    alert('Invalid post ID');
                    return;
                }
                
                const formData = new FormData(editForm);
                const modal = editForm.closest('.modal');
                const newDescription = formData.get('new_description').trim();
                
                // Find the post element and its description
                const feed = document.querySelector(`.feed[data-post-id="${postId}"]`);
                if (!feed) {
                    console.error('Post element not found for ID:', postId);
                    alert('Post element not found');
                    return;
                }
                
                let descriptionDiv = feed.querySelector('.description');
                const username = feed.querySelector('.head .user .info h3').textContent;

                fetch('firstpage.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! Status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        // Update the description in the DOM
                        if (newDescription) {
                            // If there's a new description
                            if (descriptionDiv) {
                                // Update existing description
                                const descriptionP = descriptionDiv.querySelector('p');
                                if (descriptionP) {
                                    descriptionP.innerHTML = `<b>${username}</b>: ${newDescription}`;
                                }
                            } else {
                                // Create new description element if it doesn't exist
                                descriptionDiv = document.createElement('div');
                                descriptionDiv.innerHTML = `<p><b>${username}</b>: ${newDescription}</p>`;
                                
                                // Insert after liked-by section
                                const likedBySection = feed.querySelector('.liked-by');
                                if (likedBySection) {
                                    likedBySection.parentNode.insertBefore(descriptionDiv, likedBySection.nextSibling);
                                } else {
                                    // Insert before comment section if no liked-by section
                                    const commentSection = feed.querySelector('.comment-section');
                                    if (commentSection) {
                                        commentSection.parentNode.insertBefore(descriptionDiv, commentSection);
                                    } else {
                                        // Fallback: append after action-buttons
                                        const actionButtons = feed.querySelector('.action-buttons');
                                        if (actionButtons) {
                                            actionButtons.parentNode.insertBefore(descriptionDiv, actionButtons.nextSibling);
                                        }
                                    }
                                }
                            }
                        } else {
                            // If description is empty, remove the description element
                            if (descriptionDiv) {
                                descriptionDiv.remove();
                            }
                        }
                        
                        // Close the modal
                        modal.classList.add('hidden');
                        
                        // Show success message (optional)
                        console.log('Post description updated successfully');
                    } else {
                        alert(data.error || 'Failed to edit post');
                    }
                })
                .catch(error => {
                    console.error('Error editing post:', error);
                    alert(`Error editing post: ${error.message}`);
                });
            });

            // Toggle comment section
            document.body.addEventListener('click', function(e) {
                const commentBtn = e.target.closest('.uil-comment-dots');
                if (!commentBtn) return;

                const feed = commentBtn.closest('.feed');
                const commentSection = feed.querySelector('.comment-section');
                commentSection.style.display = commentSection.style.display === 'none' ? 'block' : 'none';
            });

            // Comment submission
            document.body.addEventListener('submit', function(e) {
                const commentForm = e.target.closest('.comment-form');
                if (!commentForm) return;

                e.preventDefault();
                const postId = commentForm.getAttribute('data-post-id');
                if (!postId || isNaN(postId)) {
                    console.error('Invalid post ID:', postId);
                    alert('Invalid post ID');
                    return;
                }
                const formData = new FormData(commentForm);
                const commentList = commentForm.closest('.comment-section').querySelector('.comment-list');
                const commentCountDiv = commentForm.closest('.comment-section').querySelector('.comment-count');
                const parentCommentId = formData.get('parent_comment_id');
                const replyContainer = parentCommentId ? commentForm.closest('.reply-form') : null;
                const isReply = !!parentCommentId;

                fetch('firstpage.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! Status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        const comment = data.comment;
                        const newComment = document.createElement('div');
                        newComment.classList.add('comment');
                        if (isReply) newComment.classList.add('reply');
                        newComment.setAttribute('data-comment-id', comment.comment_id);
                        
                        const replyingToHTML = comment.parent_username ? `
                            <div class="replying-to">
                                Replying to <span class="username">@${comment.parent_username}</span>
                            </div>
                        ` : '';
                        
                        newComment.innerHTML = `
                            <div class="comment-content">
                                <div class="profile-photo">
                                    <img src="${comment.profile_picture || './profile_pics/profile.jpg'}" alt="Profile">
                                </div>
                                <div class="comment-info">
                                    ${replyingToHTML}
                                    <h4>${comment.username}</h4>
                                    <small>${formatDate(new Date(comment.created_at))}</small>
                                    <p>${comment.content}</p>
                                    <div class="comment-actions">
                                        <span class="reply-btn" data-username="${comment.username}">Reply</span>
                                        ${comment.user_id === window.currentUser.id ? `<span class="delete-comment-btn" data-comment-id="${comment.comment_id}">Delete</span>` : ''}
                                    </div>
                                </div>
                            </div>
                            <div class="replies"></div>
                            <div class="reply-form hidden" data-parent-comment-id="${comment.comment_id}" data-parent-username="${comment.username}">
                                <div class="replying-to">
                                    Replying to <span class="username">@${comment.username}</span>
                                </div>
                                <form class="comment-form" data-post-id="${postId}">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="add_comment" value="1">
                                    <input type="hidden" name="post_id" value="${postId}">
                                    <input type="hidden" name="parent_comment_id" value="${comment.comment_id}">
                                    <textarea name="comment_content" placeholder="Write a reply..." rows="2"></textarea>
                                    <button type="submit" class="btn btn-primary">Reply</button>
                                </form>
                            </div>
                        `;
                        
                        if (isReply && parentCommentId) {
                            // Try to find the parent comment
                            let parentComment = commentList.querySelector(`.comment[data-comment-id="${parentCommentId}"]`);
                            
                            // If parent comment not found in main list, check in replies
                            if (!parentComment) {
                                parentComment = commentList.querySelector(`.comment.reply[data-comment-id="${parentCommentId}"]`);
                            }
                            
                            if (parentComment) {
                                let parentReplies = parentComment.querySelector('.replies');
                                
                                // If .replies container doesn't exist, create it
                                if (!parentReplies) {
                                    parentReplies = document.createElement('div');
                                    parentReplies.classList.add('replies');
                                    
                                    // Find the right place to insert replies container
                                    const commentContent = parentComment.querySelector('.comment-content');
                                    const replyForm = parentComment.querySelector('.reply-form');
                                    
                                    if (replyForm) {
                                        // Insert before reply form
                                        parentComment.insertBefore(parentReplies, replyForm);
                                    } else if (commentContent) {
                                        // Insert after comment content
                                        commentContent.parentNode.insertBefore(parentReplies, commentContent.nextSibling);
                                    } else {
                                        // Fallback: append to parent comment
                                        parentComment.appendChild(parentReplies);
                                    }
                                }
                                
                                parentReplies.appendChild(newComment);
                            } else {
                                // Fallback: add to main comment list if parent not found
                                console.warn(`Parent comment with ID ${parentCommentId} not found, adding to main list`);
                                commentList.appendChild(newComment);
                            }
                        } else {
                            // Top-level comment
                            commentList.appendChild(newComment);
                        }
                        
                        // Update comment count
                        const currentCount = parseInt(commentCountDiv.textContent.match(/\d+/)?.[0] || 0);
                        commentCountDiv.textContent = `View all ${currentCount + 1} comments`;
                        
                        // Reset form and hide reply container
                        commentForm.reset();
                        if (replyContainer) {
                            replyContainer.classList.add('hidden');
                        }
                    } else {
                        alert(data.error || 'Failed to post comment');
                    }
                })
                .catch(error => {
                    console.error('Error posting comment:', error);
                    alert(`Error posting comment: ${error.message}`);
                });
            });

            // Reply button
            document.body.addEventListener('click', function(e) {
                const replyBtn = e.target.closest('.reply-btn');
                if (!replyBtn) return;

                const comment = replyBtn.closest('.comment');
                const replyForm = comment.querySelector('.reply-form');
                if (replyForm) {
                    replyForm.classList.toggle('hidden');
                    
                    // Focus on the textarea when reply form is shown
                    if (!replyForm.classList.contains('hidden')) {
                        const textarea = replyForm.querySelector('textarea');
                        if (textarea) {
                            textarea.focus();
                        }
                    }
                }
            });

            // Comment deletion - FIXED VERSION
            document.body.addEventListener('click', function(e) {
                const deleteBtn = e.target.closest('.delete-comment-btn');
                if (!deleteBtn) return;

                e.preventDefault();
                const commentId = deleteBtn.getAttribute('data-comment-id');
                const comment = deleteBtn.closest('.comment');
                const commentCountDiv = comment.closest('.comment-section').querySelector('.comment-count');
                
                if (!commentId || isNaN(commentId)) {
                    console.error('Invalid comment ID:', commentId);
                    alert('Invalid comment ID');
                    return;
                }

                if (confirm('Are you sure you want to delete this comment? This will also delete all replies.')) {
                    fetch('firstpage.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `delete_comment=1&comment_id=${encodeURIComponent(commentId)}&csrf_token=<?php echo urlencode($_SESSION['csrf_token']); ?>`
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`HTTP error! Status: ${response.status}`);
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            // Count how many comments we're removing (main comment + replies)
                            const repliesToDelete = comment.querySelectorAll('.reply').length;
                            const totalToDelete = 1 + repliesToDelete;
                            
                            comment.remove();
                            
                            const currentCount = parseInt(commentCountDiv.textContent.match(/\d+/)?.[0] || totalToDelete);
                            const newCount = Math.max(0, currentCount - totalToDelete);
                            commentCountDiv.textContent = newCount > 0 ? `View all ${newCount} comments` : 'No comments yet';
                        } else {
                            alert(data.error || 'Failed to delete comment');
                        }
                    })
                    .catch(error => {
                        console.error('Error deleting comment:', error);
                        alert(`Error deleting comment: ${error.message}`);
                    });
                }
            });

            // Share post modal
            document.body.addEventListener('click', function(e) {
                const shareBtn = e.target.closest('.uil-share');
                if (!shareBtn) return;

                e.preventDefault();
                const feed = shareBtn.closest('.feed');
                const postId = feed.getAttribute('data-post-id');
                const modal = document.getElementById('sharePostModal');
                const userList = document.getElementById('share-user-list');
                const searchInput = document.getElementById('share-user-search');

                if (!postId || isNaN(postId)) {
                    console.error('Invalid post ID:', postId);
                    alert('Invalid post ID');
                    return;
                }

                modal.classList.remove('hidden');
                modal.setAttribute('data-post-id', postId);
                userList.innerHTML = '<p>Loading...</p>';
                searchInput.value = '';

                fetchChatUsers();

                let searchTimeout;
                searchInput.addEventListener('input', function() {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(() => {
                        fetchChatUsers(this.value.trim());
                    }, 300);
                });
            });

            function fetchChatUsers(search = '') {
                const userList = document.getElementById('share-user-list');
                const url = `share_post.php${search ? `?search=${encodeURIComponent(search)}` : ''}`;

                fetch(url)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`HTTP error! Status: ${response.status}`);
                        }
                        return response.json();
                    })
                    .then(data => {
                        userList.innerHTML = '';
                        if (data.success) {
                            if (data.users.length === 0) {
                                userList.innerHTML = '<p>No users found.</p>';
                                return;
                            }
                            data.users.forEach(user => {
                                const userItem = document.createElement('div');
                                userItem.classList.add('user-item');
                                userItem.setAttribute('data-user-id', user.user_id);
                                userItem.innerHTML = `
                                    <div class="profile-photo">
                                        <img src="${user.profile_picture || './profile_pics/profile.jpg'}" alt="Profile">
                                    </div>
                                    <div class="user-info">
                                        <h4>${user.name || user.username}</h4>
                                        <p>@${user.username}</p>
                                    </div>
                                `;
                                userItem.addEventListener('click', () => sharePost(user.user_id));
                                userList.appendChild(userItem);
                            });
                        } else {
                            userList.innerHTML = `<p>Error: ${data.error}</p>`;
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching users:', error);
                        userList.innerHTML = `<p>Error: ${error.message}</p>`;
                    });
            }

            function sharePost(receiverId) {
                const modal = document.getElementById('sharePostModal');
                const postId = modal.getAttribute('data-post-id');
                if (!postId || isNaN(postId) || !receiverId || isNaN(receiverId)) {
                    console.error('Invalid IDs:', { postId, receiverId });
                    alert('Invalid post or user ID');
                    return;
                }

                fetch('share_post.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `share_post=1&post_id=${encodeURIComponent(postId)}&receiver_id=${encodeURIComponent(receiverId)}&csrf_token=<?php echo urlencode($_SESSION['csrf_token']); ?>`
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! Status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        modal.classList.add('hidden');
                        alert('Post shared successfully!');
                        if (typeof(Storage) !== "undefined") {
                            localStorage.setItem('unread_count_update', Date.now());
                        }
                    } else {
                        alert(data.error || 'Failed to share post');
                    }
                })
                .catch(error => {
                    console.error('Error sharing post:', error);
                    alert(`Error sharing post: ${error.message}`);
                });
            }

            // Update unread count
            function updateUnreadCount() {
                fetch('get_unread_count.php')
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`HTTP error! Status: ${response.status}`);
                        }
                        return response.json();
                    })
                    .then(data => {
                        const countElement = document.querySelector('#messages-notification .notification-count');
                        if (countElement) {
                            countElement.textContent = data.unread_count > 0 ? data.unread_count : '';
                        }
                    })
                    .catch(error => console.error('Error fetching unread count:', error));
            }

            window.addEventListener('storage', e => {
                if (e.key === 'unread_count_update') {
                    updateUnreadCount();
                }
            });

            updateUnreadCount();

            // Modal close
            document.querySelectorAll('.close').forEach(closeBtn => {
                closeBtn.addEventListener('click', function() {
                    this.closest('.modal').classList.add('hidden');
                });
            });

            // Edit dropdown
            document.querySelectorAll('.edit').forEach(edit => {
                edit.addEventListener('click', function(e) {
                    e.preventDefault();
                    const dropdown = this.querySelector('.edit-dropdown');
                    dropdown.classList.toggle('hidden');
                });
            });

            document.querySelectorAll('.edit-option').forEach(option => {
                option.addEventListener('click', function() {
                    const postId = this.getAttribute('data-post-id');
                    const modal = document.getElementById(`editPostModal-${postId}`);
                    if (modal) modal.classList.remove('hidden');
                });
            });

            // Media upload
            document.querySelectorAll('.post-actions i').forEach(icon => {
                icon.addEventListener('click', function() {
                    const type = this.getAttribute('data-type');
                    const accept = {
                        'image': '.jpg,.jpeg,.png,.gif',
                        'video': '.mp4,.webm,.ogg',
                        'document': '.doc,.docx,.pdf',
                        'audio': '.mp3,.wav'
                    }[type] || '';
                    document.getElementById('media-upload').setAttribute('accept', accept);
                    document.getElementById('media-upload').click();
                });
            });

            document.getElementById('media-upload').addEventListener('change', function(e) {
                const fileName = e.target.files[0] ? e.target.files[0].name : '';
                document.querySelector('.create-post .file-name').textContent = fileName;
            });

            // Check if we need to scroll to a specific post
            const postIdToScrollTo = sessionStorage.getItem('scrollToPost');
            
            if (postIdToScrollTo) {
                // Wait a bit for the page to fully render
                setTimeout(() => {
                    scrollToPost(postIdToScrollTo);
                }, 500);
            }
        });

        // Function to scroll to and highlight a specific post
        function scrollToPost(postId) {
            const post = document.querySelector(`.feed[data-post-id="${postId}"]`);
            if (post) {
                // Smooth scroll to the post
                post.scrollIntoView({ 
                    behavior: 'smooth', 
                    block: 'center' 
                });
                
                // Add highlight effect
                post.style.border = '3px solid #007bff';
                post.style.borderRadius = '8px';
                post.style.boxShadow = '0 4px 12px rgba(0, 123, 255, 0.3)';
                post.style.transition = 'all 0.3s ease';
                post.style.backgroundColor = 'rgba(0, 123, 255, 0.05)';
                
                // Remove highlight after 4 seconds
                setTimeout(() => {
                    post.style.border = '';
                    post.style.borderRadius = '';
                    post.style.boxShadow = '';
                    post.style.backgroundColor = '';
                    post.style.transition = '';
                }, 4000);
                
                // Clear the stored post ID
                sessionStorage.removeItem('scrollToPost');
                
                return true;
            } else {
                // If post not found, try again after a short delay (in case posts are still loading)
                setTimeout(() => {
                    const retryPost = document.querySelector(`.feed[data-post-id="${postId}"]`);
                    if (retryPost) {
                        scrollToPost(postId);
                    } else {
                        // Post not found, clear the stored ID
                        sessionStorage.removeItem('scrollToPost');
                        console.warn(`Post with ID ${postId} not found on this page`);
                    }
                }, 1000);
            }
            return false;
        }
    </script>
</body>
</html>