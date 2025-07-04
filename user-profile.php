<?php
session_start();
require 'db_connect.php';

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

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $new_name = trim($_POST['name']);
    $new_username = trim($_POST['username']);

    // Fetch current user data first
    $stmt = $pdo->prepare("SELECT profile_picture FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $current_user = $stmt->fetch();
    $profile_picture = $current_user['profile_picture'];

    // Validate
    if (empty($new_name) || empty($new_username)) {
        $error = "Name and username are required.";
    } elseif (strlen($new_name) > 100) {
        $error = "Name must be 100 characters or less.";
    } elseif (strlen($new_username) > 50) {
        $error = "Username must be 50 characters or less.";
    } else {
        // Check if username is taken
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND user_id != ?");
        $stmt->execute([$new_username, $user_id]);
        if ($stmt->fetchColumn() > 0) {
            $error = "Username is already taken.";
        } else {
            // Handle avatar upload
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $file_tmp = $_FILES['avatar']['tmp_name'];
                $file_name = $_FILES['avatar']['name'];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                $allowed_exts = ['jpg', 'jpeg', 'png', 'gif'];

                if (!in_array($file_ext, $allowed_exts)) {
                    $error = "Only JPG, JPEG, PNG, and GIF files are allowed.";
                } elseif ($_FILES['avatar']['size'] > 5 * 1024 * 1024) {
                    $error = "File size must be less than 5MB.";
                } else {
                    $upload_dir = './profile_pics/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }

                    $new_file_name = $user_id . '_' . time() . '.' . $file_ext;
                    $upload_path = $upload_dir . $new_file_name;

                    if (move_uploaded_file($file_tmp, $upload_path)) {
                        // Delete old profile picture if it's not the default
                        if ($profile_picture !== './profile_pics/profile.jpg' && file_exists($profile_picture)) {
                            unlink($profile_picture);
                        }
                        $profile_picture = $upload_path;
                    } else {
                        $error = "Failed to upload the avatar.";
                    }
                }
            }

            // Update user if no errors
            if (empty($error)) {
                try {
                    $stmt = $pdo->prepare("UPDATE users SET name = ?, username = ?, profile_picture = ? WHERE user_id = ?");
                    $result = $stmt->execute([$new_name, $new_username, $profile_picture, $user_id]);
                    
                    $_SESSION['username'] = $new_username;
                    
                    $success = "Profile updated successfully!";
                } catch (PDOException $e) {
                    $error = "Database error: " . $e->getMessage();
                }
            }
        }
    }
}

// Handle profile deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_profile'])) {
    try {
        $pdo->beginTransaction();

        // Fetch current user data for cleanup
        $stmt = $pdo->prepare("SELECT profile_picture FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $current_user = $stmt->fetch();

        // Delete posts
        $stmt = $pdo->prepare("DELETE FROM posts WHERE user_id = ?");
        $stmt->execute([$user_id]);

        // Delete notifications
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ? OR actor_id = ?");
        $stmt->execute([$user_id, $user_id]);

        // Delete messages
        $stmt = $pdo->prepare("DELETE FROM messages WHERE sender_id = ? OR receiver_id = ?");
        $stmt->execute([$user_id, $user_id]);

        // Delete profile picture if not default
        if ($current_user['profile_picture'] !== './profile_pics/profile.jpg' && file_exists($current_user['profile_picture'])) {
            unlink($current_user['profile_picture']);
        }

        // Delete user
        $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);

        $pdo->commit();

        session_destroy();
        if (isset($_COOKIE['session_id'])) {
            setcookie('session_id', '', time() - 3600, '/');
        }
        header("Location: index.php?message=profile_deleted");
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "Failed to delete profile: " . $e->getMessage();
    }
}

// Fetch user profile
try {
    $stmt = $pdo->prepare("SELECT username, name, email, profile_picture FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
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

// Fetch user's posts
$stmt = $pdo->prepare("
    SELECT p.post_id, p.user_id, p.content, p.description, p.media_type, p.media_url, p.created_at, u.username
    FROM posts p
    JOIN users u ON p.user_id = u.user_id
    WHERE p.user_id = ?
    ORDER BY p.created_at DESC
");
$stmt->execute([$user_id]);
$posts = $stmt->fetchAll();

// Fetch counts
$stmt = $pdo->prepare("SELECT 
    (SELECT COUNT(*) FROM posts WHERE user_id = ?) AS publications,
    (SELECT COUNT(*) FROM follows WHERE followed_id = ?) AS followers,
    (SELECT COUNT(*) FROM follows WHERE follower_id = ?) AS following
");
$stmt->execute([$user_id, $user_id, $user_id]);
$counts = $stmt->fetch(PDO::FETCH_ASSOC);

$publication_count = $counts['publications'];
$follower_count = $counts['followers'];
$following_count = $counts['following'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="./img/login.jpg">
    <link rel="stylesheet" href="./css/user-profile.css">
    <link rel="stylesheet" href="https://unicons.iconscout.com/release/v4.0.8/css/line.css">
    <title>My Profile - OmniVox</title>
    <style>
        .error-message {
            color: red;
            text-align: center;
            margin-bottom: 15px;
        }
        .success-message {
            color: green;
            text-align: center;
            margin-bottom: 15px;
        }
        .post-item {
            position: relative;
            border: 1px solid #e4e6eb;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 20px;
        }
        .post-item img, .post-item video {
            width: 100%;
            max-height: 400px;
            object-fit: cover;
        }
        .text-post {
            padding: 15px;
            background-color: #fff;
        }
        .document-container, .audio-container {
            text-align: center;
            padding: 15px;
        }
        .no-posts {
            text-align: center;
            color: #666;
            padding: 20px;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }
        
        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background-color: #fff;
            padding: 20px;
            border-radius: 10px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        
        .modal-header h3 {
            margin: 0;
            color: #333;
        }
        
        .close {
            font-size: 28px;
            font-weight: bold;
            color: #999;
            cursor: pointer;
            line-height: 1;
        }
        
        .close:hover {
            color: #333;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
        }
        
        .form-group input[type="text"],
        .form-group input[type="file"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
        }
        
        .avatar-preview {
            text-align: center;
            margin-bottom: 15px;
        }
        
        .avatar-preview img {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #ddd;
        }
        
        .submit-btn {
            background-color: #007bff;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            width: 100%;
            margin-bottom: 20px;
        }
        
        .submit-btn:hover {
            background-color: #0056b3;
        }
        
        .delete-profile-section {
            border-top: 1px solid #eee;
            padding-top: 20px;
        }
        
        .delete-profile-section h4 {
            color: #dc3545;
            margin-bottom: 10px;
        }
        
        .delete-profile-section p {
            color: #666;
            margin-bottom: 15px;
        }
        
        .delete-btn {
            background-color: #dc3545;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .delete-btn:hover {
            background-color: #c82333;
        }
    </style>
</head>
<body>
    <nav>
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
            <!-- Include the left sidebar -->
            <?php include 'left.php'; ?>

            <!-- MIDDLE -->
            <div class="middle">
                <?php if (isset($error)): ?>
                    <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <?php if (isset($success)): ?>
                    <div class="success-message" id="success-message"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>
                <div class="profile-header">
                    <div class="profile-photo large">
                        <img src="<?php echo htmlspecialchars($user['profile_picture'] ?? './profile_pics/profile.jpg'); ?>" alt="Profile">
                    </div>
                    <div class="profile-info">
                        <h2><?php echo htmlspecialchars($user['name'] ?? $user['username']); ?></h2>
                        <p class="text-muted">@<?php echo htmlspecialchars($user['username']); ?></p>
                        <div class="stats">
                            <div class="stat">
                                <span class="count"><?php echo $publication_count; ?></span>
                                <span class="label">Publications</span>
                            </div>
                            <div class="stat">
                                <span class="count"><?php echo $follower_count; ?></span>
                                <span class="label">Followers</span>
                            </div>
                            <div class="stat">
                                <span class="count"><?php echo $following_count; ?></span>
                                <span class="label">Following</span>
                            </div>
                        </div>
                        <button class="btn btn-primary edit-profile-btn" id="editProfileBtn">Edit Profile</button>
                    </div>
                </div>

                <div class="user-posts">
                    <h3>Publications</h3>
                    <div class="posts-grid">
                        <?php if (empty($posts)): ?>
                            <p class="no-posts">No publications yet.</p>
                        <?php else: ?>
                            <?php foreach ($posts as $post): ?>
                                <div class="post-item">
                                    <?php if ($post['media_type']): ?>
                                        <?php if ($post['media_type'] === 'image'): ?>
                                            <img src="<?php echo htmlspecialchars($post['media_url']); ?>" alt="Post image">
                                        <?php elseif ($post['media_type'] === 'video'): ?>
                                            <video autoplay muted loop controls>
                                                <source src="<?php echo htmlspecialchars($post['media_url']); ?>" type="video/mp4">
                                                Your browser does not support the video tag.
                                            </video>
                                        <?php elseif ($post['media_type'] === 'document'): ?>
                                            <div class="document-container">
                                                <img src="./assets/document-placeholder.jpg" alt="Document placeholder">
                                            </div>
                                        <?php elseif ($post['media_type'] === 'audio'): ?>
                                            <div class="audio-container">
                                                <img src="./assets/audio-placeholder.jpg" alt="Audio placeholder">
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="text-post">
                                            <p><?php echo htmlspecialchars($post['description'] ?? $post['content']); ?></p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Edit Profile Modal -->
    <div id="editProfileModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Profile</h3>
                <span class="close" id="closeModal">×</span>
            </div>
            <div class="modal-body">
                <form action="user-profile.php" method="POST" enctype="multipart/form-data" id="editProfileForm">
                    <input type="hidden" name="update_profile" value="1">
                    <div class="form-group">
                        <label for="avatar">Profile Picture</label>
                        <div class="avatar-preview">
                            <img src="<?php echo htmlspecialchars($user['profile_picture'] ?? './profile_pics/profile.jpg'); ?>" alt="Profile Picture" id="avatar-preview">
                        </div>
                        <input type="file" name="avatar" id="avatar" accept="image/*">
                    </div>
                    <div class="form-group">
                        <label for="name">Name</label>
                        <input type="text" name="name" id="name" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" name="username" id="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                    </div>
                    <button type="submit" class="submit-btn">Save Changes</button>
                </form>
                <div class="delete-profile-section">
                    <h4>Delete Profile</h4>
                    <p>Warning: This action cannot be undone. All your data will be permanently deleted.</p>
                    <form action="user-profile.php" method="POST" onsubmit="return confirm('Are you sure you want to delete your profile? This action cannot be undone.');">
                        <input type="hidden" name="delete_profile" value="1">
                        <button type="submit" class="delete-btn">Delete Profile</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Modal elements
            const modal = document.getElementById('editProfileModal');
            const editBtn = document.getElementById('editProfileBtn');
            const closeBtn = document.getElementById('closeModal');
            const avatarInput = document.getElementById('avatar');
            const avatarPreview = document.getElementById('avatar-preview');

            // Open modal
            editBtn.addEventListener('click', function() {
                modal.classList.add('show');
                modal.style.display = 'flex';
            });

            // Close modal
            closeBtn.addEventListener('click', function() {
                modal.classList.remove('show');
                modal.style.display = 'none';
            });

            // Close modal when clicking outside
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.classList.remove('show');
                    modal.style.display = 'none';
                }
            });

            // Avatar preview function
            avatarInput.addEventListener('change', function(event) {
                const file = event.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        avatarPreview.src = e.target.result;
                    };
                    reader.readAsDataURL(file);
                }
            });

            // Auto-hide success message after 3 seconds
            const successMessage = document.getElementById('success-message');
            if (successMessage) {
                setTimeout(function() {
                    successMessage.style.transition = 'opacity 0.5s ease';
                    successMessage.style.opacity = '0';
                    setTimeout(function() {
                        successMessage.style.display = 'none';
                    }, 500);
                }, 1500);
            }

            // Ensure logout link works without interference
            document.querySelectorAll('a[href="?logout=true"]').forEach(link => {
                link.addEventListener('click', function(e) {
                    e.stopPropagation();
                    window.location.href = '?logout=true';
                });
            });
        });
    </script>
    <script src="./js/user-profile.js"></script>
</body>
</html>