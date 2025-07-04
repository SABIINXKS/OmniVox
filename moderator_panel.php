<?php
session_start();
require 'db_connect.php';

// Check if user is logged in and is moderator/admin
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Check if user is moderator or admin
try {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    if (!$user || ($user['role'] !== 'moderator' && $user['role'] !== 'admin')) {
        header("Location: firstpage.php");
        exit;
    }
    
    $is_admin = ($user['role'] === 'admin');
} catch (PDOException $e) {
    error_log("Error checking moderator status: " . $e->getMessage());
    header("Location: firstpage.php");
    exit;
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle moderator actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    try {
        // Delete post (moderators can delete posts)
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
        
        // Delete comment (moderators can delete comments)
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
        
        // Suspend/unsuspend user (moderators can suspend users, but not other moderators/admins)
        if (isset($_POST['toggle_user_status'])) {
            $target_user_id = (int)$_POST['user_id'];
            $is_suspended = $_POST['is_suspended'] === 'true';
            
            // Check if target user is not admin/moderator (moderators can't suspend other staff)
            $stmt = $pdo->prepare("SELECT role FROM users WHERE user_id = ?");
            $stmt->execute([$target_user_id]);
            $target_user = $stmt->fetch();
            
            if (!$target_user || ($target_user['role'] === 'admin' || $target_user['role'] === 'moderator')) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Cannot suspend staff members']);
                exit;
            }
            
            $stmt = $pdo->prepare("UPDATE users SET is_suspended = ? WHERE user_id = ?");
            $success = $stmt->execute([!$is_suspended, $target_user_id]);
            
            header('Content-Type: application/json');
            echo json_encode(['success' => $success, 'new_status' => !$is_suspended]);
            exit;
        }
        
    } catch (PDOException $e) {
        error_log("Moderator action error: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Database error']);
        exit;
    }
}

// Fetch statistics
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_users FROM users WHERE role = 'user'");
    $stmt->execute();
    $total_users = $stmt->fetch()['total_users'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_posts FROM posts");
    $stmt->execute();
    $total_posts = $stmt->fetch()['total_posts'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_comments FROM comments");
    $stmt->execute();
    $total_comments = $stmt->fetch()['total_comments'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as suspended_users FROM users WHERE is_suspended = 1 AND role = 'user'");
    $stmt->execute();
    $suspended_users = $stmt->fetch()['suspended_users'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as todays_posts FROM posts WHERE DATE(created_at) = CURDATE()");
    $stmt->execute();
    $todays_posts = $stmt->fetch()['todays_posts'];
} catch (PDOException $e) {
    error_log("Error fetching stats: " . $e->getMessage());
    $total_users = $total_posts = $total_comments = $suspended_users = $todays_posts = 0;
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
        LIMIT 15
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
        LIMIT 15
    ");
    $stmt->execute();
    $recent_comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching comments: " . $e->getMessage());
    $recent_comments = [];
}

// Fetch regular users (moderators can only manage regular users, not staff)
try {
    $stmt = $pdo->prepare("
        SELECT user_id, username, name, email, is_suspended, created_at,
               (SELECT COUNT(*) FROM posts WHERE user_id = u.user_id) as post_count,
               (SELECT COUNT(*) FROM comments WHERE user_id = u.user_id) as comment_count
        FROM users u
        WHERE role = 'user'
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $stmt->execute();
    $regular_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching users: " . $e->getMessage());
    $regular_users = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Moderator Panel - OmniVox</title>
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

        .mod-header {
            background: linear-gradient(135deg, #fd7e14 0%, #e67e22 100%);
            color: white;
            padding: 1rem 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .mod-header h1 {
            margin: 0;
            font-size: 2rem;
        }

        .mod-subtitle {
            opacity: 0.9;
            margin-top: 0.5rem;
            font-size: 0.9rem;
        }

        .mod-nav {
            margin-top: 1rem;
        }

        .mod-nav a {
            color: white;
            text-decoration: none;
            margin-right: 2rem;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            transition: background 0.3s;
        }

        .mod-nav a:hover, .mod-nav a.active {
            background: rgba(255,255,255,0.2);
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            text-align: center;
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card i {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }

        .stat-card.users i { color: #fd7e14; }
        .stat-card.posts i { color: #e74c3c; }
        .stat-card.comments i { color: #f39c12; }
        .stat-card.suspended i { color: #dc3545; }
        .stat-card.today i { color: #28a745; }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #333;
        }

        .stat-label {
            color: #666;
            margin-top: 0.5rem;
            font-size: 0.9rem;
        }

        .mod-section {
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
            display: flex;
            align-items: center;
            gap: 0.5rem;
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
            color: #fd7e14;
            border-bottom-color: #fd7e14;
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

        .admin-link {
            color: white;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin-left: 2rem;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            background: rgba(255,255,255,0.1);
            transition: background 0.3s;
        }

        .admin-link:hover {
            background: rgba(255,255,255,0.2);
        }

        .limited-notice {
            background: #fff3cd;
            color: #856404;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            border: 1px solid #ffeaa7;
        }
    </style>
</head>
<body>
    <div class="mod-header">
        <h1><i class="uil uil-shield"></i> Moderator Panel</h1>
        <div class="mod-subtitle">Content moderation and user management</div>
        <nav class="mod-nav">
            <a href="#dashboard" class="tab-link active" data-tab="dashboard">Dashboard</a>
            <a href="#posts" class="tab-link" data-tab="posts">Posts</a>
            <a href="#comments" class="tab-link" data-tab="comments">Comments</a>
            <a href="#users" class="tab-link" data-tab="users">Users</a>
        </nav>
        <a href="firstpage.php" class="back-link">
            <i class="uil uil-arrow-left"></i> Back to Main Site
        </a>
        <?php if ($is_admin): ?>
            <a href="admin_panel.php" class="admin-link">
                <i class="uil uil-shield-check"></i> Full Admin Panel
            </a>
        <?php endif; ?>
    </div>

    <div class="container">
        <!-- Dashboard Tab -->
        <div id="dashboard" class="tab-content active">
            <div class="limited-notice">
                <strong><i class="uil uil-info-circle"></i> Moderator Permissions:</strong>
                You can moderate content and suspend regular users. Admin functions like user role changes and staff management require full admin access.
            </div>
            
            <div class="stats-grid">
                <div class="stat-card users">
                    <i class="uil uil-users-alt"></i>
                    <div class="stat-number"><?php echo $total_users; ?></div>
                    <div class="stat-label">Regular Users</div>
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
                <div class="stat-card today">
                    <i class="uil uil-calendar-alt"></i>
                    <div class="stat-number"><?php echo $todays_posts; ?></div>
                    <div class="stat-label">Today's Posts</div>
                </div>
            </div>
        </div>

        <!-- Posts Tab -->
        <div id="posts" class="tab-content">
            <div class="mod-section">
                <div class="section-header">
                    <h2><i class="uil uil-postcard"></i> Content Moderation</h2>
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
                                            <?php if ($post['role'] === 'user'): ?>
                                                <span class="role-badge role-user">User</span>
                                            <?php endif; ?>
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
            <div class="mod-section">
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
                                            <?php if ($comment['role'] === 'user'): ?>
                                                <span class="role-badge role-user">User</span>
                                            <?php endif; ?>
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
            <div class="mod-section">
                <div class="section-header">
                    <h2><i class="uil uil-users-alt"></i> User Management</h2>
                </div>
                <div class="section-content">
                    <div class="limited-notice">
                        <strong><i class="uil uil-info-circle"></i> Limited Access:</strong>
                        You can only suspend/unsuspend regular users. Managing staff roles and deleting users requires admin privileges.
                    </div>
                    
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Activity</th>
                                <th>Joined</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($regular_users as $user): ?>
                            <tr data-user-id="<?php echo $user['user_id']; ?>">
                                <td>
                                    <div>
                                        <div style="font-weight: 600;"><?php echo htmlspecialchars($user['name'] ?: $user['username']); ?></div>
                                        <div style="color: #666; font-size: 0.875rem;">@<?php echo htmlspecialchars($user['username']); ?></div>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
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
                        fetch('moderator_panel.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `delete_post=1&post_id=${postId}&csrf_token=<?php echo urlencode($_SESSION['csrf_token']); ?>`
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                btn.closest('tr').remove();
                                showNotification('Post deleted successfully', 'success');
                            } else {
                                showNotification('Error deleting post: ' + (data.error || 'Unknown error'), 'error');
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            showNotification('Error deleting post', 'error');
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
                        fetch('moderator_panel.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `delete_comment=1&comment_id=${commentId}&csrf_token=<?php echo urlencode($_SESSION['csrf_token']); ?>`
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                btn.closest('tr').remove();
                                showNotification('Comment deleted successfully', 'success');
                            } else {
                                showNotification('Error deleting comment: ' + (data.error || 'Unknown error'), 'error');
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            showNotification('Error deleting comment', 'error');
                        });
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
                        fetch('moderator_panel.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `toggle_user_status=1&user_id=${userId}&is_suspended=${isSuspended}&csrf_token=<?php echo urlencode($_SESSION['csrf_token']); ?>`
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                showNotification(`User ${action}ed successfully`, 'success');
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
                            } else {
                                showNotification(`Error ${action}ing user: ` + (data.error || 'Unknown error'), 'error');
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            showNotification(`Error ${action}ing user`, 'error');
                        });
                    }
                }
            });

            // Notification system
            function showNotification(message, type = 'info') {
                // Remove existing notifications
                const existingNotifications = document.querySelectorAll('.mod-notification');
                existingNotifications.forEach(n => n.remove());

                const notification = document.createElement('div');
                notification.className = `mod-notification mod-notification-${type}`;
                notification.innerHTML = `
                    <div class="mod-notification-content">
                        <i class="uil uil-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-triangle' : 'info-circle'}"></i>
                        <span>${message}</span>
                        <button class="mod-notification-close" onclick="this.parentElement.parentElement.remove()">
                            <i class="uil uil-times"></i>
                        </button>
                    </div>
                `;

                document.body.appendChild(notification);

                // Auto-remove after 5 seconds
                setTimeout(() => {
                    if (notification.parentElement) {
                        notification.remove();
                    }
                }, 5000);
            }
        });
    </script>

    <style>
        /* Notification styles */
        .mod-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            max-width: 400px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            animation: slideInRight 0.3s ease-out;
        }

        .mod-notification-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .mod-notification-error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        .mod-notification-info {
            background: #e7f3ff;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }

        .mod-notification-content {
            display: flex;
            align-items: center;
            padding: 1rem;
            gap: 0.5rem;
        }

        .mod-notification-content i:first-child {
            font-size: 1.2rem;
        }

        .mod-notification-content span {
            flex: 1;
            font-weight: 500;
        }

        .mod-notification-close {
            background: none;
            border: none;
            color: inherit;
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 3px;
            transition: background 0.2s;
        }

        .mod-notification-close:hover {
            background: rgba(0,0,0,0.1);
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        /* Responsive improvements */
        @media (max-width: 768px) {
            .mod-header {
                padding: 1rem;
            }
            
            .mod-header h1 {
                font-size: 1.5rem;
            }
            
            .container {
                padding: 1rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 1rem;
            }
            
            .stat-card {
                padding: 1rem;
            }
            
            .stat-card i {
                font-size: 2rem;
            }
            
            .stat-number {
                font-size: 1.5rem;
            }
            
            .data-table {
                font-size: 0.875rem;
            }
            
            .data-table th,
            .data-table td {
                padding: 0.5rem;
            }
            
            .mod-notification {
                top: 10px;
                right: 10px;
                left: 10px;
                max-width: none;
            }
        }

        /* Highlight moderator-specific elements */
        .mod-section {
            border-left: 4px solid #fd7e14;
        }

        .section-header {
            background: linear-gradient(135deg, #fff8f0 0%, #f8f9fa 100%);
        }

        /* Add some visual feedback for actions */
        .btn:active {
            transform: scale(0.95);
        }

        .data-table tr:hover {
            background: linear-gradient(135deg, #fff8f0 0%, #f8f9fa 100%);
        }

        /* Improve mobile navigation */
        @media (max-width: 768px) {
            .mod-nav {
                display: flex;
                flex-wrap: wrap;
                gap: 0.5rem;
            }
            
            .mod-nav a {
                margin-right: 0;
                font-size: 0.875rem;
                padding: 0.5rem 0.75rem;
            }
            
            .back-link, .admin-link {
                margin-top: 0.5rem;
                font-size: 0.875rem;
            }
        }
    </style>
</body>
</html>