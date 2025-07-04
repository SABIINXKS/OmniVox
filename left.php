<?php 
// Ensure session and database connection are available
require 'db_connect.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch user profile with role and suspension status
$stmt = $pdo->prepare("SELECT username, name, email, profile_picture, role, is_suspended FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Check if user is suspended
if (isset($user['is_suspended']) && $user['is_suspended']) {
    session_destroy();
    header("Location: index.php?error=suspended");
    exit;
}

// Store user role in session for easy access
$_SESSION['role'] = $user['role'] ?? 'user';

// Fetch unread notifications
$stmt = $pdo->prepare("
    SELECT n.notification_id, n.message, n.created_at, u.username AS actor, u.profile_picture
    FROM notifications n
    JOIN users u ON n.actor_id = u.user_id
    WHERE n.user_id = ? AND n.is_read = 0
    ORDER BY n.created_at DESC
    LIMIT 5
");
$stmt->execute([$user_id]);
$notifications = $stmt->fetchAll();

// Fetch unread messages count
$stmt = $pdo->prepare("
    SELECT COUNT(*) as unread_count
    FROM messages m
    WHERE m.receiver_id = ? AND m.is_read = 0
");
$stmt->execute([$user_id]);
$unread_messages_count = $stmt->fetchColumn();

// Get current page name
$current_page = basename($_SERVER['PHP_SELF']);

// Special cases for navigation highlighting
if ($current_page === 'profile.php') {
    $current_page = 'members.php';
}
// Special case: if we're on group-page.php, treat it as my-groups.php for navigation
if ($current_page === 'group-page.php') {
    $current_page = 'my-groups.php';
}

// Check if user has admin/moderator privileges
$is_admin = isset($user['role']) && $user['role'] === 'admin';
$is_moderator = isset($user['role']) && ($user['role'] === 'moderator' || $user['role'] === 'admin');
?>

<div class="left">
    <a class="profile">
        <div class="profile-photo">
            <img src="<?php echo htmlspecialchars($user['profile_picture'] ?? './profile_pics/profile.jpg'); ?>" alt="Profile">
        </div>
        <div class="handle">
            <h4>
                <?php echo htmlspecialchars($user['name'] ?? $user['username']); ?>
                <?php if ($is_admin): ?>
                    <span class="role-badge admin-badge" title="Administrator">👑</span>
                <?php elseif ($is_moderator): ?>
                    <span class="role-badge mod-badge" title="Moderator">🛡️</span>
                <?php endif; ?>
            </h4>
            <p class="text-muted">@<?php echo htmlspecialchars($user['username']); ?></p>
        </div>
    </a>
    
    <div class="sidebar">
        <a href="firstpage.php" class="menu-item<?php echo $current_page === 'firstpage.php' ? ' active' : ''; ?>">
            <span><i class="uil uil-home"></i></span><h3>Home</h3>
        </a>
        
        <a href="messages.php" class="menu-item<?php echo $current_page === 'messages.php' ? ' active' : ''; ?>" id="messages-notification">
            <span>
                <i class="uil uil-envelopes"></i>
                <small class="notification-count"<?php echo $unread_messages_count > 0 ? ' data-count="'.$unread_messages_count.'"' : ''; ?>><?php echo $unread_messages_count > 0 ? $unread_messages_count : ''; ?></small>
            </span>
            <h3>Messages</h3>
        </a>
        
        <a href="bookmarks.php" class="menu-item<?php echo $current_page === 'bookmarks.php' ? ' active' : ''; ?>">
            <span><i class="uil uil-bookmark"></i></span><h3>Bookmarks</h3>
        </a>
        
        <a href="members.php" class="menu-item<?php echo $current_page === 'members.php' ? ' active' : ''; ?>">
            <span><i class="uil uil-user"></i></span><h3>Members</h3>
        </a>
        
        <a href="my-groups.php" class="menu-item<?php echo $current_page === 'my-groups.php' ? ' active' : ''; ?>">
            <span><i class="uil uil-users-alt"></i></span><h3>Groups</h3>
        </a>
        
        <a href="user-profile.php" class="menu-item<?php echo $current_page === 'user-profile.php' ? ' active' : ''; ?>">
            <span><i class="uil uil-user-circle"></i></span><h3>My Profile</h3>
        </a>
        
        <?php if ($is_moderator): ?>
            <a href="<?php echo $is_admin ? 'admin_panel.php' : 'moderator_panel.php'; ?>" class="menu-item admin-menu<?php echo ($current_page === 'admin_panel.php' || $current_page === 'moderator_panel.php') ? ' active' : ''; ?>" title="<?php echo $is_admin ? 'Admin Panel' : 'Moderation Panel'; ?>">
                <span>
                    <i class="uil uil-shield-check"></i>
                    <?php if ($is_admin): ?>
                        <small class="admin-indicator">A</small>
                    <?php else: ?>
                        <small class="mod-indicator">M</small>
                    <?php endif; ?>
                </span>
                <h3><?php echo $is_admin ? 'Admin Panel' : 'Moderation'; ?></h3>
            </a>
        <?php endif; ?>
        
        <!-- <a href="#" class="menu-item">
            <span><i class="uil uil-setting"></i></span><h3>Settings</h3>
        </a> -->
        
        <a href="?logout=true" class="menu-item" id="logout-link">
            <span><i class="uil uil-sign-out-alt"></i></span><h3>Logout</h3>
        </a>
    </div>
</div>

<style>
/* Role badges */
.role-badge {
    font-size: 0.8em;
    margin-left: 0.25rem;
}

.admin-badge {
    color: #dc3545;
    font-weight: bold;
}

.mod-badge {
    color: #fd7e14;
    font-weight: bold;
}

/* Admin menu styling */
.admin-menu {
    position: relative;
}

.admin-menu span {
    position: relative;
}

.admin-indicator, .mod-indicator {
    position: absolute;
    top: -5px;
    right: -5px;
    background: #dc3545;
    color: white;
    border-radius: 50%;
    width: 16px;
    height: 16px;
    font-size: 10px;
    font-weight: bold;
    display: flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
}

.mod-indicator {
    background: #fd7e14;
}

.admin-menu:hover .admin-indicator,
.admin-menu:hover .mod-indicator {
    transform: scale(1.1);
    transition: transform 0.2s ease;
}

/* Highlight admin menu */
.admin-menu {
    border-left: 3px solid transparent;
    transition: all 0.3s ease;
}

.admin-menu:hover {
    border-left-color: #dc3545;
    background: rgba(220, 53, 69, 0.05);
}

.admin-menu.active {
    border-left-color: #dc3545;
    background: rgba(220, 53, 69, 0.1);
}

/* Add some spacing for better visual hierarchy */
.admin-menu {
    margin-top: 0.5rem;
    padding-top: 0.5rem;
    border-top: 1px solid rgba(0,0,0,0.1);
}

/* Make role indicators more visible */
@media (max-width: 768px) {
    .role-badge {
        font-size: 0.7em;
    }
    
    .admin-indicator, .mod-indicator {
        width: 14px;
        height: 14px;
        font-size: 9px;
    }
}

/* Notification count styling consistency */
.notification-count:empty, 
.notification-count[data-count="0"] {
    display: none;
}

.notification-count {
    background: #e74c3c;
    color: white;
    border-radius: 50%;
    padding: 2px 6px;
    font-size: 10px;
    font-weight: bold;
    position: absolute;
    top: -5px;
    right: -5px;
    min-width: 16px;
    height: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
}

/* Add subtle animation for admin elements */
.admin-menu, .role-badge {
    animation: subtle-glow 3s ease-in-out infinite alternate;
}

@keyframes subtle-glow {
    from {
        filter: brightness(1);
    }
    to {
        filter: brightness(1.05);
    }
}

/* Stop animation on hover to avoid distraction */
.admin-menu:hover, .role-badge:hover {
    animation: none;
}
</style>

<script>
// Auto-update unread message count
function updateUnreadCount() {
    fetch('get_unread_count.php')
        .then(response => response.json())
        .then(data => {
            const countElement = document.querySelector('#messages-notification .notification-count');
            if (countElement) {
                if (data.unread_count > 0) {
                    countElement.textContent = data.unread_count;
                    countElement.setAttribute('data-count', data.unread_count);
                    countElement.style.display = 'flex';
                } else {
                    countElement.textContent = '';
                    countElement.removeAttribute('data-count');
                    countElement.style.display = 'none';
                }
            }
        })
        .catch(error => console.error('Error fetching unread count:', error));
}

// Update count every 30 seconds
setInterval(updateUnreadCount, 30000);

// Listen for storage events (when messages are read in another tab)
window.addEventListener('storage', function(e) {
    if (e.key === 'unread_count_update') {
        updateUnreadCount();
    }
});

// Add click animation for admin panel
document.addEventListener('DOMContentLoaded', function() {
    const adminMenu = document.querySelector('.admin-menu');
    if (adminMenu) {
        adminMenu.addEventListener('click', function() {
            this.style.transform = 'scale(0.95)';
            setTimeout(() => {
                this.style.transform = 'scale(1)';
            }, 150);
        });
    }
});
</script>