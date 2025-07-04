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

// Fetch user profile
$stmt = $pdo->prepare("SELECT username, name, email, profile_picture FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();
if (!$user) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Fetch all registered users (excluding the current user)
$stmt = $pdo->prepare("SELECT user_id, username, name, profile_picture FROM users WHERE user_id != ?");
$stmt->execute([$user_id]);
$all_users = $stmt->fetchAll();

// Fetch following relationships
try {
    $stmt = $pdo->prepare("
        SELECT follower_id, followed_id AS following_id
        FROM follows
        WHERE follower_id = ?
    ");
    $stmt->execute([$user_id]);
    $following = $stmt->fetchAll();
} catch (PDOException $e) {
    // Handle the error gracefully
    error_log("Error fetching follows: " . $e->getMessage());
    $following = []; // Fallback to empty array
}

// Function to check if the current user is following the target user
function isFollowing($user_id, $target_id, $following) {
    foreach ($following as $follow) {
        if ($follow['following_id'] == $target_id) {
            return true;
        }
    }
    return false;
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
    <title>Members - OmniVox</title>
</head>
<body>
    <nav>
        <div class="container">
            <h2 class="log">OmniVox</h2>
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
                <div class="search-bar">
                    <i class="uil uil-search"></i>
                    <input type="search" placeholder="Search members..." id="member-search">
                </div>
                <div class="users-grid">
                    <?php foreach ($all_users as $user_item): ?>
                        <div class="user-card" data-user-id="<?php echo $user_item['user_id']; ?>" onclick="window.location.href='profile.php?user_id=<?php echo $user_item['user_id']; ?>'" style="cursor: pointer;">
                            <div class="profile-photo">
                                <img src="<?php echo htmlspecialchars($user_item['profile_picture'] ?? './profile_pics/profile.jpg'); ?>" alt="Profile">
                            </div>
                            <div class="user-info">
                                <h4><?php echo htmlspecialchars($user_item['name'] ?? $user_item['username']); ?></h4>
                                <p class="text-muted">@<?php echo htmlspecialchars($user_item['username']); ?></p>
                            </div>
                            <div class="action-buttons" data-user-id="<?php echo $user_item['user_id']; ?>">
                                <?php
                                $is_following = isFollowing($user_id, $user_item['user_id'], $following);
                                ?>
                                <?php if ($is_following): ?>
                                    <button class="btn following-btn" disabled>Following</button>
                                <?php else: ?>
                                    <button class="btn" onclick="followUser(<?php echo $user_item['user_id']; ?>, event)">Follow</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Store PHP variables as JavaScript variables
        const currentUserId = <?php echo $user_id; ?>;
        const currentUsername = '<?php echo htmlspecialchars($user['username']); ?>';

        document.getElementById('member-search').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const usersGrid = document.querySelector('.users-grid');
            const userCards = Array.from(document.querySelectorAll('.user-card'));
            
            // Separate matching and non-matching cards
            const matchingCards = [];
            const nonMatchingCards = [];

            userCards.forEach(card => {
                const username = card.querySelector('.user-info p').textContent.toLowerCase();
                const name = card.querySelector('.user-info h4').textContent.toLowerCase();
                if (username.includes(searchTerm) || name.includes(searchTerm)) {
                    matchingCards.push(card);
                    card.style.visibility = 'visible';
                    card.style.opacity = '1';
                } else {
                    nonMatchingCards.push(card);
                    card.style.visibility = 'hidden';
                    card.style.opacity = '0';
                }
            });

            // Clear the grid
            while (usersGrid.firstChild) {
                usersGrid.removeChild(usersGrid.firstChild);
            }

            // Append matching cards first, then non-matching cards
            matchingCards.forEach(card => usersGrid.appendChild(card));
            nonMatchingCards.forEach(card => usersGrid.appendChild(card));
        });

        function followUser(targetUserId, event) {
            event.stopPropagation(); // Prevent the card click from navigating
            fetch('api/follow_user.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `follower_id=${currentUserId}&followed_id=${targetUserId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('You are now following this user!');
                    const card = document.querySelector(`.user-card[data-user-id="${targetUserId}"]`);
                    const actionButtons = card.querySelector('.action-buttons');
                    actionButtons.innerHTML = '<button class="btn following-btn" disabled>Following</button>';
                    
                    // Add notification
                    fetch('api/add_notification.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `user_id=${targetUserId}&message=User @${currentUsername} followed you`
                    })
                    .then(() => console.log('Notification added'))
                    .catch(error => console.error('Notification error:', error));
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => console.error('Error:', error));
        }
    </script>
</body>
</html>