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

$current_user_id = $_SESSION['user_id'];
$profile_user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;

if (!$profile_user_id) {
    header("Location: members.php");
    exit;
}

// Set current page for navigation - this is the key fix
$current_page = 'members.php';

// Fetch current user profile
$stmt = $pdo->prepare("SELECT username, name, email, profile_picture FROM users WHERE user_id = ?");
$stmt->execute([$current_user_id]);
$current_user = $stmt->fetch();

// Fetch profile user details
$stmt = $pdo->prepare("SELECT username, name, email, bio, profile_picture FROM users WHERE user_id = ?");
$stmt->execute([$profile_user_id]);
$profile_user = $stmt->fetch();

if (!$profile_user) {
    header("Location: members.php");
    exit;
}

// Fetch counts (excluding friends count)
$stmt = $pdo->prepare("SELECT 
    (SELECT COUNT(*) FROM posts WHERE user_id = ?) AS publications,
    (SELECT COUNT(*) FROM follows WHERE followed_id = ?) AS followers,
    (SELECT COUNT(*) FROM follows WHERE follower_id = ?) AS following");
$stmt->execute([$profile_user_id, $profile_user_id, $profile_user_id]);
$counts = $stmt->fetch(PDO::FETCH_ASSOC);

// Check if current user is following the profile user
$is_following = false;
$stmt = $pdo->prepare("SELECT COUNT(*) FROM follows WHERE follower_id = ? AND followed_id = ?");
$stmt->execute([$current_user_id, $profile_user_id]);
$is_following = $stmt->fetchColumn() > 0;

// Fetch publications for profile user
$stmt = $pdo->prepare("
    SELECT p.post_id, p.user_id, p.content, p.description, p.media_type, p.media_url, p.created_at, u.username
    FROM posts p
    JOIN users u ON p.user_id = u.user_id
    WHERE p.user_id = ?
    ORDER BY p.created_at DESC
");
$stmt->execute([$profile_user_id]);
$posts = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel ="icon" type="image/x-icon" href="./img/login.jpg">
    <link rel="stylesheet" href="./css/user-profile.css">
    <link rel="stylesheet" href="https://unicons.iconscout.com/release/v4.0.8/css/line.css">
    <title>Profile - OmniVox</title>
</head>
<body>
    <nav>
        <div class="container">
            <h2 class="log">OmniVox</h2>
            <!-- <div class="create"> -->
                <!-- <label class="btn btn-primary" for="create-post">Create</label> -->
                <div class="profile-photo">
                    <img src="<?php echo htmlspecialchars($current_user['profile_picture'] ?? './profile_pics/profile.jpg'); ?>" alt="Profile">
                </div>
            </div>
        </div>
    </nav>

    <main>
        <div class="container">
            <!-- LEFT -->
            <?php include 'left.php'; ?>

            <!-- MIDDLE -->
            <div class="middle">
                <div class="profile-header">
                    <div class="profile-photo large">
                        <img src="<?php echo htmlspecialchars($profile_user['profile_picture'] ?? './profile_pics/profile.jpg'); ?>" alt="Profile">
                    </div>
                    <div class="profile-info">
                        <h2><?php echo htmlspecialchars($profile_user['name'] ?? $profile_user['username']); ?></h2>
                        <p class="text-muted">@<?php echo htmlspecialchars($profile_user['username']); ?></p>
                        <?php if (!empty($profile_user['bio'])): ?>
                            <p class="bio"><?php echo htmlspecialchars($profile_user['bio']); ?></p>
                        <?php endif; ?>
                        <div class="stats">
                            <div class="stat">
                                <span class="count"><?php echo $counts['publications']; ?></span>
                                <span class="label">Publications</span>
                            </div>
                            <div class="stat">
                                <span class="count"><?php echo $counts['followers']; ?></span>
                                <span class="label">Followers</span>
                            </div>
                            <div class="stat">
                                <span class="count"><?php echo $counts['following']; ?></span>
                                <span class="label">Following</span>
                            </div>
                        </div>
                        <div class="profile-actions">
                            <?php if ($is_following): ?>
                                <button class="btn" disabled>Following</button>
                            <?php else: ?>
                                <button class="btn" onclick="followUser(<?php echo $profile_user_id; ?>)">Follow</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="user-posts">
                    <h3>Publications</h3>
                    <div class="posts-grid">
                        <?php if (empty($posts)): ?>
                            <p class="text-muted">No publications yet.</p>
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
                                            <div class="photo document-container">
                                                <img src="./assets/document-placeholder.jpg" alt="Document placeholder">
                                                <span class="document-name"><?php echo basename($post['media_url']); ?></span>
                                            </div>
                                        <?php elseif ($post['media_type'] === 'audio'): ?>
                                            <div class="photo document-container">
                                                <img src="./assets/audio-placeholder.jpg" alt="Audio placeholder">
                                                <span class="document-name"><?php echo basename($post['media_url']); ?></span>
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


    <script src="./js/firstpage.js"></script>
    <script>
        function sendFriendRequest(profileUserId) {
            // This function is no longer needed since +Friend is removed
            console.log('Friend request functionality removed.');
        }

        function followUser(profileUserId) {
            fetch('api/follow_user.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `follower_id=<?php echo $current_user_id; ?>&followed_id=${profileUserId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('You are now following this user!');
                    fetch('api/add_notification.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `user_id=${profileUserId}&message=User @<?php echo $current_user['username']; ?> followed you`
                    })
                    .then(() => console.log('Notification added'))
                    .catch(error => console.error('Notification error:', error));
                    location.reload(); // Reload to update the button
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => console.error('Error:', error));
        }

        function acceptFriendRequest(requestId) {
            // This function is no longer needed since Requests are removed
            console.log('Friend request acceptance functionality removed.');
        }

        function declineFriendRequest(requestId) {
            // This function is no longer needed since Requests are removed
            console.log('Friend request decline functionality removed.');
        }
    </script>
</body>
</html>