<?php
session_start();
require 'db_connect.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Check if user is admin
try {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    if (!$user || $user['role'] !== 'admin') {
        header("Location: firstpage.php");
        exit;
    }
} catch (PDOException $e) {
    error_log("Error checking admin status: " . $e->getMessage());
    header("Location: firstpage.php");
    exit;
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle admin actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    try {
        // Delete post
        if (isset($_POST['delete_post'])) {
            $post_id = (int)$_POST['post_id'];
            
            // Get media URL before deletion
            $stmt = $pdo->prepare("SELECT media_url FROM posts WHERE post_id = ?");
            $stmt->execute([$post_id]);
            $post = $stmt->fetch();
            
            if ($post && $post['media_url'] && file_exists($post['media_url'])) {
                unlink($post['media_url']);
            }
            
            // Delete related data
            $stmt = $pdo->prepare("DELETE FROM likes WHERE post_id = ?");
            $stmt->execute([$post_id]);
            
            $stmt = $pdo->prepare("DELETE FROM comments WHERE post_id = ?");
            $stmt->execute([$post_id]);
            
            $stmt = $pdo->prepare("DELETE FROM bookmarks WHERE post_id = ?");
            $stmt->execute([$post_id]);
            
            // Delete post
            $stmt = $pdo->prepare("DELETE FROM posts WHERE post_id = ?");
            $success = $stmt->execute([$post_id]);
            
            header('Content-Type: application/json');
            echo json_encode(['success' => $success]);
            exit;
        }
        
        // Delete comment
        if (isset($_POST['delete_comment'])) {
            $comment_id = (int)$_POST['comment_id'];
            
            // Delete replies first
            $stmt = $pdo->prepare("DELETE FROM comments WHERE parent_comment_id = ?");
            $stmt->execute([$comment_id]);
            
            // Delete main comment
            $stmt = $pdo->prepare("DELETE FROM comments WHERE comment_id = ?");
            $success = $stmt->execute([$comment_id]);
            
            header('Content-Type: application/json');
            echo json_encode(['success' => $success]);
            exit;
        }
        
        // Update user role
        if (isset($_POST['update_user_role'])) {
            $target_user_id = (int)$_POST['user_id'];
            $new_role = $_POST['role'];
            
            // Validate role
            if (!in_array($new_role, ['user', 'admin', 'moderator'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Invalid role']);
                exit;
            }
            
            $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE user_id = ?");
            $success = $stmt->execute([$new_role, $target_user_id]);
            
            header('Content-Type: application/json');
            echo json_encode(['success' => $success]);
            exit;
        }
        
        // Suspend/unsuspend user
        if (isset($_POST['toggle_user_status'])) {
            $target_user_id = (int)$_POST['user_id'];
            $is_suspended = $_POST['is_suspended'] === 'true';
            
            $stmt = $pdo->prepare("UPDATE users SET is_suspended = ? WHERE user_id = ?");
            $success = $stmt->execute([!$is_suspended, $target_user_id]);
            
            header('Content-Type: application/json');
            echo json_encode(['success' => $success, 'new_status' => !$is_suspended]);
            exit;
        }
        
        // Delete user
        if (isset($_POST['delete_user'])) {
            $target_user_id = (int)$_POST['user_id'];
            
            // Don't allow deleting self
            if ($target_user_id === $user_id) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Cannot delete your own account']);
                exit;
            }
            
            // Delete user's posts and related data
            $stmt = $pdo->prepare("SELECT post_id, media_url FROM posts WHERE user_id = ?");
            $stmt->execute([$target_user_id]);
            $user_posts = $stmt->fetchAll();
            
            foreach ($user_posts as $post) {
                if ($post['media_url'] && file_exists($post['media_url'])) {
                    unlink($post['media_url']);
                }
                
                $stmt = $pdo->prepare("DELETE FROM likes WHERE post_id = ?");
                $stmt->execute([$post['post_id']]);
                
                $stmt = $pdo->prepare("DELETE FROM comments WHERE post_id = ?");
                $stmt->execute([$post['post_id']]);
                
                $stmt = $pdo->prepare("DELETE FROM bookmarks WHERE post_id = ?");
                $stmt->execute([$post['post_id']]);
            }
            
            // Delete user's posts
            $stmt = $pdo->prepare("DELETE FROM posts WHERE user_id = ?");
            $stmt->execute([$target_user_id]);
            
            // Delete user's likes and comments
            $stmt = $pdo->prepare("DELETE FROM likes WHERE user_id = ?");
            $stmt->execute([$target_user_id]);
            
            $stmt = $pdo->prepare("DELETE FROM comments WHERE user_id = ?");
            $stmt->execute([$target_user_id]);
            
            $stmt = $pdo->prepare("DELETE FROM bookmarks WHERE user_id = ?");
            $stmt->execute([$target_user_id]);
            
            // Delete user
            $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
            $success = $stmt->execute([$target_user_id]);
            
            header('Content-Type: application/json');
            echo json_encode(['success' => $success]);
            exit;
        }
        
    } catch (PDOException $e) {
        error_log("Admin action error: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Database error']);
        exit;
    }
}

// Fetch statistics
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_users FROM users");
    $stmt->execute();
    $total_users = $stmt->fetch()['total_users'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_posts FROM posts");
    $stmt->execute();
    $total_posts = $stmt->fetch()['total_posts'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_comments FROM comments");
    $stmt->execute();
    $total_comments = $stmt->fetch()['total_comments'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as suspended_users FROM users WHERE is_suspended = 1");
    $stmt->execute();
    $suspended_users = $stmt->fetch()['suspended_users'];
} catch (PDOException $e) {
    error_log("Error fetching stats: " . $e->getMessage());
    $total_users = $total_posts = $total_comments = $suspended_users = 0;
}

// Fetch recent posts for moderation
try {
    $stmt = $pdo->prepare("
        SELECT p.post_id, p.user_id, p.content, p.description, p.media_type, p.media_url, p.created_at,
               u.username, u.profile_picture, u.role, u.is_suspended,
               (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.post_id) AS like_count,
               (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.post_id) AS comment_count
        FROM posts p
        JOIN users u ON p.user_id = u.user_id
        ORDER BY p.created_at DESC
        LIMIT 20
    ");
    $stmt->execute();
    $recent_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching posts: " . $e->getMessage());
    $recent_posts = [];
}

// Fetch recent comments for moderation
try {
    $stmt = $pdo->prepare("
        SELECT c.comment_id, c.post_id, c.user_id, c.content, c.created_at,
               u.username, u.profile_picture, u.role, u.is_suspended,
               p.description as post_description
        FROM comments c
        JOIN users u ON c.user_id = u.user_id
        JOIN posts p ON c.post_id = p.post_id
        ORDER BY c.created_at DESC
        LIMIT 20
    ");
    $stmt->execute();
    $recent_comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching comments: " . $e->getMessage());
    $recent_comments = [];
}

// Fetch all users for management
try {
    $stmt = $pdo->prepare("
        SELECT user_id, username, name, email, role, is_suspended, created_at,
               (SELECT COUNT(*) FROM posts WHERE user_id = u.user_id) as post_count,
               (SELECT COUNT(*) FROM comments WHERE user_id = u.user_id) as comment_count
        FROM users u
        ORDER BY created_at DESC
    ");
    $stmt->execute();
    $all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching users: " . $e->getMessage());
    $all_users = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - OmniVox</title>
    <link rel="stylesheet" href="https://unicons.iconscout.com/release/v4.0.8/css/line.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7fa;
            color: #333;
        }

        .admin-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .admin-header h1 {
            margin: 0;
            font-size: 2rem;
        }

        .admin-nav {
            margin-top: 1rem;
        }

        .admin-nav a {
            color: white;
            text-decoration: none;
            margin-right: 2rem;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            transition: background 0.3s;
        }

        .admin-nav a:hover, .admin-nav a.active {
            background: rgba(255,255,255,0.2);
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }

        .stat-card {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            text-align: center;
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card i {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        .stat-card.users i { color: #3498db; }
        .stat-card.posts i { color: #e74c3c; }
        .stat-card.comments i { color: #f39c12; }
        .stat-card.suspended i { color: #e67e22; }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: #333;
        }

        .stat-label {
            color: #666;
            margin-top: 0.5rem;
        }

        .admin-section {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
            overflow: hidden;
        }

        .section-header {
            background: #f8f9fa;
            padding: 1.5rem 2rem;
            border-bottom: 1px solid #dee2e6;
        }

        .section-header h2 {
            margin: 0;
            color: #333;
        }

        .section-content {
            padding: 2rem;
        }

        .tab-buttons {
            display: flex;
            margin-bottom: 2rem;
            border-bottom: 1px solid #dee2e6;
        }

        .tab-button {
            background: none;
            border: none;
            padding: 1rem 2rem;
            cursor: pointer;
            font-size: 1rem;
            color: #666;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
        }

        .tab-button.active {
            color: #667eea;
            border-bottom-color: #667eea;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .data-table th,
        .data-table td {
            text-align: left;
            padding: 1rem;
            border-bottom: 1px solid #dee2e6;
        }

        .data-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }

        .data-table tr:hover {
            background: #f8f9fa;
        }

        .profile-photo {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }

        .role-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .role-admin {
            background: #dc3545;
            color: white;
        }

        .role-moderator {
            background: #fd7e14;
            color: white;
        }

        .role-user {
            background: #28a745;
            color: white;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-suspended {
            background: #f8d7da;
            color: #721c24;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.875rem;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background: #007bff;
            color: white;
        }

        .btn-primary:hover {
            background: #0056b3;
        }

        .btn-warning {
            background: #ffc107;
            color: #212529;
        }

        .btn-warning:hover {
            background: #e0a800;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-success:hover {
            background: #218838;
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
        }

        .post-content {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .media-preview {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 5px;
        }

        .role-select {
            padding: 0.25rem;
            border: 1px solid #ced4da;
            border-radius: 3px;
            font-size: 0.875rem;
        }

        .back-link {
            color: white;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="admin-header">
        <h1><i class="uil uil-shield-check"></i> Admin Panel</h1>
        <nav class="admin-nav">
            <a href="#dashboard" class="tab-link active" data-tab="dashboard">Dashboard</a>
            <a href="#posts" class="tab-link" data-tab="posts">Posts</a>
            <a href="#comments" class="tab-link" data-tab="comments">Comments</a>
            <a href="#users" class="tab-link" data-tab="users">Users</a>
        </nav>
        <a href="firstpage.php" class="back-link">
            <i class="uil uil-arrow-left"></i> Back to Main Site
        </a>
    </div>

    <div class="container">
        <!-- Dashboard Tab -->
        <div id="dashboard" class="tab-content active">
            <div class="stats-grid">
                <div class="stat-card users">
                    <i class="uil uil-users-alt"></i>
                    <div class="stat-number"><?php echo $total_users; ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
                <div class="stat-card posts">
                    <i class="uil uil-postcard"></i>
                    <div class="stat-number"><?php echo $total_posts; ?></div>
                    <div class="stat-label">Total Posts</div>
                </div>
                <div class="stat-card comments">
                    <i class="uil uil-comments"></i>
                    <div class="stat-number"><?php echo $total_comments; ?></div>
                    <div class="stat-label">Total Comments</div>
                </div>
                <div class="stat-card suspended">
                    <i class="uil uil-user-times"></i>
                    <div class="stat-number"><?php echo $suspended_users; ?></div>
                    <div class="stat-label">Suspended Users</div>
                </div>
            </div>
        </div>

        <!-- Posts Tab -->
        <div id="posts" class="tab-content">
            <div class="admin-section">
                <div class="section-header">
                    <h2><i class="uil uil-postcard"></i> Post Moderation</h2>
                </div>
                <div class="section-content">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Content</th>
                                <th>Media</th>
                                <th>Stats</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_posts as $post): ?>
                            <tr data-post-id="<?php echo $post['post_id']; ?>">
                                <td>
                                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                                        <img src="<?php echo htmlspecialchars($post['profile_picture'] ?? './profile_pics/profile.jpg'); ?>" 
                                             alt="Profile" class="profile-photo">
                                        <div>
                                            <div><?php echo htmlspecialchars($post['username']); ?></div>
                                            <span class="role-badge role-<?php echo $post['role']; ?>">
                                                <?php echo $post['role']; ?>
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="post-content">
                                        <?php echo htmlspecialchars($post['description'] ?: $post['content']); ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($post['media_url']): ?>
                                        <?php if ($post['media_type'] === 'image'): ?>
                                            <img src="<?php echo htmlspecialchars($post['media_url']); ?>" 
                                                 alt="Media" class="media-preview">
                                        <?php else: ?>
                                            <i class="uil uil-file"></i> <?php echo $post['media_type']; ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: #999;">No media</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div>
                                        <i class="uil uil-heart"></i> <?php echo $post['like_count']; ?>
                                        <i class="uil uil-comment" style="margin-left: 1rem;"></i> <?php echo $post['comment_count']; ?>
                                    </div>
                                </td>
                                <td><?php echo date('M d, Y H:i', strtotime($post['created_at'])); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn btn-danger btn-sm delete-post-btn" 
                                                data-post-id="<?php echo $post['post_id']; ?>">
                                            <i class="uil uil-trash-alt"></i> Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Comments Tab -->
        <div id="comments" class="tab-content">
            <div class="admin-section">
                <div class="section-header">
                    <h2><i class="uil uil-comments"></i> Comment Moderation</h2>
                </div>
                <div class="section-content">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Comment</th>
                                <th>Post</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_comments as $comment): ?>
                            <tr data-comment-id="<?php echo $comment['comment_id']; ?>">
                                <td>
                                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                                        <img src="<?php echo htmlspecialchars($comment['profile_picture'] ?? './profile_pics/profile.jpg'); ?>" 
                                             alt="Profile" class="profile-photo">
                                        <div>
                                            <div><?php echo htmlspecialchars($comment['username']); ?></div>
                                            <span class="role-badge role-<?php echo $comment['role']; ?>">
                                                <?php echo $comment['role']; ?>
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="post-content">
                                        <?php echo htmlspecialchars($comment['content']); ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="post-content">
                                        <?php echo htmlspecialchars($comment['post_description'] ?: 'Post #' . $comment['post_id']); ?>
                                    </div>
                                </td>
                                <td><?php echo date('M d, Y H:i', strtotime($comment['created_at'])); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn btn-danger btn-sm delete-comment-btn" 
                                                data-comment-id="<?php echo $comment['comment_id']; ?>">
                                            <i class="uil uil-trash-alt"></i> Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Users Tab -->
        <div id="users" class="tab-content">
            <div class="admin-section">
                <div class="section-header">
                    <h2><i class="uil uil-users-alt"></i> User Management</h2>
                </div>
                <div class="section-content">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Activity</th>
                                <th>Joined</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_users as $user): ?>
                            <tr data-user-id="<?php echo $user['user_id']; ?>">
                                <td>
                                    <div>
                                        <div style="font-weight: 600;"><?php echo htmlspecialchars($user['name'] ?: $user['username']); ?></div>
                                        <div style="color: #666; font-size: 0.875rem;">@<?php echo htmlspecialchars($user['username']); ?></div>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td>
                                    <select class="role-select" data-user-id="<?php echo $user['user_id']; ?>">
                                        <option value="user" <?php echo $user['role'] === 'user' ? 'selected' : ''; ?>>User</option>
                                        <option value="moderator" <?php echo $user['role'] === 'moderator' ? 'selected' : ''; ?>>Moderator</option>
                                        <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                    </select>
                                </td>
                                <td>
                                    <span class="status-badge <?php echo $user['is_suspended'] ? 'status-suspended' : 'status-active'; ?>">
                                        <?php echo $user['is_suspended'] ? 'Suspended' : 'Active'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div>
                                        <i class="uil uil-postcard"></i> <?php echo $user['post_count']; ?> posts
                                        <br>
                                        <i class="uil uil-comment"></i> <?php echo $user['comment_count']; ?> comments
                                    </div>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <?php if (!$user['is_suspended']): ?>
                                            <button class="btn btn-warning btn-sm suspend-user-btn" 
                                                    data-user-id="<?php echo $user['user_id']; ?>" 
                                                    data-suspended="false">
                                                <i class="uil uil-ban"></i> Suspend
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-success btn-sm suspend-user-btn" 
                                                    data-user-id="<?php echo $user['user_id']; ?>" 
                                                    data-suspended="true">
                                                <i class="uil uil-check"></i> Unsuspend
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($user['user_id'] !== $user_id): ?>
                                            <button class="btn btn-danger btn-sm delete-user-btn" 
                                                    data-user-id="<?php echo $user['user_id']; ?>"
                                                    data-username="<?php echo htmlspecialchars($user['username']); ?>">
                                                <i class="uil uil-trash-alt"></i> Delete
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Tab switching
            const tabLinks = document.querySelectorAll('.tab-link');
            const tabContents = document.querySelectorAll('.tab-content');

            tabLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const targetTab = this.getAttribute('data-tab');

                    // Remove active class from all tabs and contents
                    tabLinks.forEach(l => l.classList.remove('active'));
                    tabContents.forEach(c => c.classList.remove('active'));

                    // Add active class to clicked tab and corresponding content
                    this.classList.add('active');
                    document.getElementById(targetTab).classList.add('active');
                });
            });

            // Delete post
            document.addEventListener('click', function(e) {
                if (e.target.closest('.delete-post-btn')) {
                    const btn = e.target.closest('.delete-post-btn');
                    const postId = btn.getAttribute('data-post-id');
                    
                    if (confirm('Are you sure you want to delete this post? This action cannot be undone.')) {
                        fetch('admin_panel.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `delete_post=1&post_id=${postId}&csrf_token=<?php echo urlencode($_SESSION['csrf_token']); ?>`
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                btn.closest('tr').remove();
                                alert('Post deleted successfully');
                                // Update stats
                                location.reload();
                            } else {
                                alert('Error deleting post: ' + (data.error || 'Unknown error'));
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('Error deleting post');
                        });
                    }
                }
            });

            // Delete comment
            document.addEventListener('click', function(e) {
                if (e.target.closest('.delete-comment-btn')) {
                    const btn = e.target.closest('.delete-comment-btn');
                    const commentId = btn.getAttribute('data-comment-id');
                    
                    if (confirm('Are you sure you want to delete this comment? This action cannot be undone.')) {
                        fetch('admin_panel.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `delete_comment=1&comment_id=${commentId}&csrf_token=<?php echo urlencode($_SESSION['csrf_token']); ?>`
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                btn.closest('tr').remove();
                                alert('Comment deleted successfully');
                                // Update stats
                                location.reload();
                            } else {
                                alert('Error deleting comment: ' + (data.error || 'Unknown error'));
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('Error deleting comment');
                        });
                    }
                }
            });

            // Update user role
            document.addEventListener('change', function(e) {
                if (e.target.classList.contains('role-select')) {
                    const userId = e.target.getAttribute('data-user-id');
                    const newRole = e.target.value;
                    const oldRole = e.target.getAttribute('data-old-role') || e.target.options[e.target.selectedIndex].defaultSelected;
                    
                    if (confirm(`Are you sure you want to change this user's role to ${newRole}?`)) {
                        fetch('admin_panel.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `update_user_role=1&user_id=${userId}&role=${newRole}&csrf_token=<?php echo urlencode($_SESSION['csrf_token']); ?>`
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                alert('User role updated successfully');
                                // Update the role badge
                                const row = e.target.closest('tr');
                                const roleBadge = row.querySelector('.role-badge');
                                if (roleBadge) {
                                    roleBadge.className = `role-badge role-${newRole}`;
                                    roleBadge.textContent = newRole;
                                }
                            } else {
                                alert('Error updating user role: ' + (data.error || 'Unknown error'));
                                // Revert the select
                                e.target.value = oldRole;
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('Error updating user role');
                            // Revert the select
                            e.target.value = oldRole;
                        });
                    } else {
                        // Revert the select if user cancelled
                        e.target.value = oldRole;
                    }
                }
            });

            // Suspend/Unsuspend user
            document.addEventListener('click', function(e) {
                if (e.target.closest('.suspend-user-btn')) {
                    const btn = e.target.closest('.suspend-user-btn');
                    const userId = btn.getAttribute('data-user-id');
                    const isSuspended = btn.getAttribute('data-suspended') === 'true';
                    const action = isSuspended ? 'unsuspend' : 'suspend';
                    
                    if (confirm(`Are you sure you want to ${action} this user?`)) {
                        fetch('admin_panel.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `toggle_user_status=1&user_id=${userId}&is_suspended=${isSuspended}&csrf_token=<?php echo urlencode($_SESSION['csrf_token']); ?>`
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                alert(`User ${action}ed successfully`);
                                // Update the button and status badge
                                const newSuspended = data.new_status;
                                btn.setAttribute('data-suspended', newSuspended);
                                
                                if (newSuspended) {
                                    btn.innerHTML = '<i class="uil uil-check"></i> Unsuspend';
                                    btn.className = 'btn btn-success btn-sm suspend-user-btn';
                                } else {
                                    btn.innerHTML = '<i class="uil uil-ban"></i> Suspend';
                                    btn.className = 'btn btn-warning btn-sm suspend-user-btn';
                                }
                                
                                // Update status badge
                                const statusBadge = btn.closest('tr').querySelector('.status-badge');
                                if (statusBadge) {
                                    if (newSuspended) {
                                        statusBadge.className = 'status-badge status-suspended';
                                        statusBadge.textContent = 'Suspended';
                                    } else {
                                        statusBadge.className = 'status-badge status-active';
                                        statusBadge.textContent = 'Active';
                                    }
                                }
                                
                                // Update stats
                                location.reload();
                            } else {
                                alert(`Error ${action}ing user: ` + (data.error || 'Unknown error'));
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert(`Error ${action}ing user`);
                        });
                    }
                }
            });

            // Delete user
            document.addEventListener('click', function(e) {
                if (e.target.closest('.delete-user-btn')) {
                    const btn = e.target.closest('.delete-user-btn');
                    const userId = btn.getAttribute('data-user-id');
                    const username = btn.getAttribute('data-username');
                    
                    if (confirm(`Are you sure you want to permanently delete user "${username}"? This will delete all their posts, comments, and data. This action cannot be undone.`)) {
                        if (confirm('This is your final warning. Are you absolutely sure? Type "DELETE" to confirm:')) {
                            const confirmation = prompt('Type "DELETE" to confirm:');
                            if (confirmation === 'DELETE') {
                                fetch('admin_panel.php', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                    body: `delete_user=1&user_id=${userId}&csrf_token=<?php echo urlencode($_SESSION['csrf_token']); ?>`
                                })
                                .then(response => response.json())
                                .then(data => {
                                    if (data.success) {
                                        btn.closest('tr').remove();
                                        alert('User deleted successfully');
                                        // Update stats
                                        location.reload();
                                    } else {
                                        alert('Error deleting user: ' + (data.error || 'Unknown error'));
                                    }
                                })
                                .catch(error => {
                                    console.error('Error:', error);
                                    alert('Error deleting user');
                                });
                            } else {
                                alert('Deletion cancelled - confirmation text did not match');
                            }
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>