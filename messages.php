<?php
session_start();
require 'db_connect.php';

// Handle logout
if (isset($_GET['logout'])) {
    error_log("Logout triggered for user_id " . ($_SESSION['user_id'] ?? 'unknown'));
    session_unset();
    session_destroy();
    if (isset($_COOKIE['session_id'])) {
        setcookie('session_id', '', time() - 3600, '/');
    }
    header("Location: index.php");
    exit;
}

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
header('Content-Type: application/json'); // Default for API responses

// Handle send message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $receiver_id = isset($_POST['receiver_id']) ? (int)$_POST['receiver_id'] : 0;
    $content = isset($_POST['content']) ? trim($_POST['content']) : '';
    $post_owner_username = isset($_POST['post_owner_username']) ? trim($_POST['post_owner_username']) : '';
    $receiver_username = isset($_POST['receiver_username']) ? trim($_POST['receiver_username']) : '';
    $receiver_profile_picture = isset($_POST['receiver_profile_picture']) ? trim($_POST['receiver_profile_picture']) : '';

    if ($receiver_id <= 0 || empty($content)) {
        echo json_encode(['success' => false, 'error' => 'Invalid receiver or empty message']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, content, created_at, is_read) VALUES (?, ?, ?, NOW(), 0)");
        $stmt->execute([$user_id, $receiver_id, $content]);
        echo json_encode([
            'success' => true,
            'post_owner_username' => $post_owner_username,
            'receiver_username' => $receiver_username,
            'receiver_profile_picture' => $receiver_profile_picture
        ]);
    } catch (PDOException $e) {
        error_log("Send message error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit;
}

// Handle mark messages as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_as_read'])) {
    $sender_id = isset($_POST['sender_id']) ? (int)$_POST['sender_id'] : 0;

    if ($sender_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid sender ID']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE receiver_id = ? AND sender_id = ? AND is_read = 0");
        $stmt->execute([$user_id, $sender_id]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        error_log("Mark as read error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit;
}

// Handle delete chat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_chat'])) {
    $other_user_id = isset($_POST['other_user_id']) ? (int)$_POST['other_user_id'] : 0;

    if ($other_user_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM messages WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)");
        $stmt->execute([$user_id, $other_user_id, $other_user_id, $user_id]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        error_log("Delete chat error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit;
}

// Handle user search
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search_users'])) {
    $query = isset($_POST['query']) ? trim($_POST['query']) : '';

    if (strlen($query) < 2) {
        echo json_encode(['success' => false, 'error' => 'Query too short']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT user_id, username, profile_picture FROM users WHERE username LIKE ? AND user_id != ? LIMIT 10");
        $stmt->execute(["%$query%", $user_id]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'users' => $users]);
    } catch (PDOException $e) {
        error_log("Search users error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit;
}

// Handle fetching messages for a conversation
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_messages'])) {
    $other_user_id = isset($_GET['other_user_id']) ? (int)$_GET['other_user_id'] : 0;

    if ($other_user_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
        exit;
    }

    try {
        // Fetch messages
        $stmt = $pdo->prepare("
            SELECT m.message_id, m.sender_id, m.receiver_id, m.content, m.created_at, m.is_read,
                   u1.username AS sender_username, u1.profile_picture AS sender_profile_picture,
                   u2.username AS receiver_username, u2.profile_picture AS receiver_profile_picture
            FROM messages m
            JOIN users u1 ON m.sender_id = u1.user_id
            JOIN users u2 ON m.receiver_id = u2.user_id
            WHERE (m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)
            ORDER BY m.created_at ASC
        ");
        $stmt->execute([$user_id, $other_user_id, $other_user_id, $user_id]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch post details for shared posts
        $post_ids = [];
        foreach ($messages as $message) {
            if (preg_match('/\(Post ID: (\d+)\)$/', $message['content'], $matches)) {
                $post_ids[] = (int)$matches[1];
            }
        }

        $posts = [];
        if (!empty($post_ids)) {
            $placeholders = implode(',', array_fill(0, count($post_ids), '?'));
            $stmt = $pdo->prepare("
                SELECT p.post_id, p.user_id, p.content, p.description, p.media_type, p.media_url, p.created_at,
                       u.username, u.profile_picture
                FROM posts p
                JOIN users u ON p.user_id = u.user_id
                WHERE p.post_id IN ($placeholders)
            ");
            $stmt->execute($post_ids);
            $posts_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($posts_data as $post) {
                $posts[$post['post_id']] = $post;
            }
        }

        echo json_encode(['success' => true, 'messages' => $messages, 'posts' => $posts]);
    } catch (PDOException $e) {
        error_log("Get messages error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit;
}

// For HTML rendering, reset Content-Type
header('Content-Type: text/html; charset=UTF-8');

// Get user data
try {
    $stmt = $pdo->prepare("SELECT username, profile_picture FROM users WHERE user_id = ?");
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

// Get conversations list with unread counts - UPDATED QUERY
$stmt = $pdo->prepare("
    SELECT DISTINCT u.user_id, u.username, u.profile_picture,
           (SELECT CASE 
               WHEN m.content LIKE '%(Post ID: %' THEN 
                   CONCAT('Shared @', COALESCE((
                       SELECT u2.username 
                       FROM posts p 
                       JOIN users u2 ON p.user_id = u2.user_id 
                       WHERE p.post_id = CAST(
                           SUBSTRING(m.content, 
                               LOCATE('(Post ID: ', m.content) + 10,
                               LOCATE(')', m.content, LOCATE('(Post ID: ', m.content)) - LOCATE('(Post ID: ', m.content) - 10
                           ) AS UNSIGNED
                       )
                   ), 'Unknown'), '''s post')
               ELSE 
                   CASE 
                       WHEN LENGTH(m.content) > 30 THEN CONCAT(LEFT(m.content, 30), '...')
                       ELSE m.content 
                   END
           END
           FROM messages m 
           WHERE (m.sender_id = u.user_id AND m.receiver_id = ?) 
              OR (m.sender_id = ? AND m.receiver_id = u.user_id)
           ORDER BY m.created_at DESC LIMIT 1) AS last_message,
           (SELECT created_at FROM messages m 
            WHERE (m.sender_id = u.user_id AND m.receiver_id = ?) 
               OR (m.sender_id = ? AND m.receiver_id = u.user_id)
            ORDER BY m.created_at DESC LIMIT 1) AS last_message_time,
           (SELECT COUNT(*) FROM messages m 
            WHERE m.sender_id = u.user_id AND m.receiver_id = ? AND m.is_read = 0) AS unread_count
    FROM users u
    JOIN messages m ON u.user_id = m.sender_id OR u.user_id = m.receiver_id
    WHERE (m.sender_id = ? OR m.receiver_id = ?) AND u.user_id != ?
    ORDER BY last_message_time DESC
");
$stmt->execute([$user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id]);
$messaged_users = $stmt->fetchAll();

// Get total unread count for sidebar
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS unread_count FROM messages WHERE receiver_id = ? AND is_read = 0");
    $stmt->execute([$user_id]);
    $unread_count = $stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Unread count error: " . $e->getMessage());
    $unread_count = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="./img/login.jpg">
    <link rel="stylesheet" href="./css/user-profile.css">
    <link rel="stylesheet" href="https://unicons.iconscout.com/release/v4.0.8/css/line.css">
    <title>Messages - OmniVox</title>
    <style>
        .messages-container {
            display: flex;
            height: 80vh;
            border: 1px solid #e4e6eb;
            border-radius: 8px;
            overflow: hidden;
        }
        .followed-users {
            width: 30%;
            border-right: 1px solid #e4e6eb;
            overflow-y: auto;
        }
        .chat-area {
            width: 70%;
            display: flex;
            flex-direction: column;
        }
        .user-item {
            display: flex;
            align-items: center;
            padding: 15px;
            cursor: pointer;
            border-bottom: 1px solid #f0f2f5;
            position: relative;
        }
        .user-item:hover {
            background-color: #f0f2f5;
        }
        .user-item.active {
            background-color: #e7f3ff;
        }
        .user-item img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            margin-right: 10px;
        }
        .user-info {
            flex: 1;
        }
        .user-info .username {
            font-weight: 600;
            font-size: 16px;
        }
        .user-info .last-message {
            color: #666;
            font-size: 14px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 200px;
        }
        .chat-header {
            padding: 15px;
            border-bottom: 1px solid #e4e6eb;
            display: flex;
            align-items: center;
        }
        .chat-header img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            margin-right: 10px;
        }
        .chat-header .username {
            font-weight: 600;
            font-size: 16px;
        }
        .chat-messages {
            flex: 1;
            padding: 15px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }
        .message {
            max-width: 60%;
            padding: 10px;
            margin-bottom: 10px;
            border-radius: 15px;
            font-size: 14px;
            white-space: pre-wrap; /* Preserve line breaks */
        }
        .message.sent {
            background-color: #0084ff;
            color: white;
            align-self: flex-end;
            border-bottom-right-radius: 5px;
        }
        .message.received {
            background-color: #f0f2f5;
            align-self: flex-start;
            border-bottom-left-radius: 5px;
        }
        .message.post-preview {
            border: 1px solid #e4e6eb;
            border-radius: 15px;
            padding: 10px;
        }
        .message.post-preview.sent {
            background-color: #0084ff;
            color: white;
            border-bottom-right-radius: 5px;
        }
        .message.post-preview.received {
            background-color: #f0f2f5;
            border-bottom-left-radius: 5px;
        }
        .post-preview .post-content p {
            font-size: 13px; /* Smaller font for shared posts */
            margin: 0;
        }
        .post-preview .post-footer a {
            color: #1a73e8;
            text-decoration: none;
            font-size: 11px; /* Smaller font for link */
        }
        .post-preview .post-footer a:hover {
            text-decoration: underline;
        }
        .message-time {
            font-size: 12px;
            color: #999;
            margin-top: 5px;
            text-align: right;
        }
        .chat-input {
            padding: 15px;
            border-top: 1px solid #e4e6eb;
            display: flex;
        }
        .chat-input textarea {
            flex: 1;
            padding: 10px;
            border: 1px solid #e4e6eb;
            border-radius: 20px;
            resize: none;
            font-size: 14px;
        }
        .chat-input button {
            background-color: #0084ff;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 20px;
            margin-left: 10px;
            cursor: pointer;
        }
        .chat-input button:hover {
            background-color: #0066cc;
        }
        .no-chat {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #666;
            font-size: 16px;
        }
        .no-users {
            padding: 15px;
            color: #666;
            text-align: center;
        }
        .search-container {
            position: relative;
            margin-bottom: 15px;
            padding: 10px;
        }
        #user-search {
            width: 100%;
            padding: 10px;
            border: 1px solid #e4e6eb;
            border-radius: 20px;
            font-size: 14px;
        }
        .search-results {
            display: none;
            position: absolute;
            top: 100%;
            left: 10px;
            right: 10px;
            background: white;
            border: 1px solid #e4e6eb;
            border-radius: 8px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 10;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .search-results.active {
            display: block;
        }
        .search-result-item {
            padding: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .search-result-item:hover {
            background-color: #f0f2f5;
        }
        .search-result-item img {
            width: 30px;
            height: 30px;
            border-radius: 50%;
        }
        .more-options {
            padding: 5px;
            cursor: pointer;
        }
        .options-menu {
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            border: 1px solid #e4e6eb;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            z-index: 10;
        }
        .options-menu.active {
            display: block;
        }
        .options-menu a,
        .options-menu button {
            display: block;
            padding: 10px;
            border: none;
            background: none;
            width: 100%;
            text-align: left;
            cursor: pointer;
            font-size: 14px;
        }
        .options-menu a:hover,
        .options-menu button:hover {
            background-color: #f0f2f5;
        }
        .unread-data {
            display: none;
        }
        .unread-data[data-unread="0"] {
            display: none;
        }
        .unread-data[data-unread]:not([data-unread="0"]) {
            width: 8px;
            height: 8px;
            background-color: #0084ff;
            border-radius: 50%;
            position: absolute;
            right: 40px;
            top: 50%;
            transform: translateY(-50%);
        }
        .error-message {
            color: red;
            font-size: 14px;
            padding: 10px;
            display: none;
        }
        
        /* Enhanced styles for view post links */
        .view-post-link {
            color: #1a73e8 !important;
            text-decoration: none !important;
            font-weight: 500;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        
        .view-post-link:hover {
            text-decoration: underline !important;
            color: #0d5cb8 !important;
        }
        
        .message.sent .view-post-link {
            color: rgba(255, 255, 255, 0.9) !important;
        }
        
        .message.sent .view-post-link:hover {
            color: white !important;
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
            <?php include 'left.php'; ?>

            <div class="middle">
                <div class="search-container">
                    <input type="text" id="user-search" placeholder="Search users...">
                    <div id="search-results" class="search-results"></div>
                </div>
                <div class="messages-container">
                    <div class="followed-users">
                        <?php if (empty($messaged_users)): ?>
                            <p class="no-users">No message history yet.</p>
                        <?php else: ?>
                            <?php foreach ($messaged_users as $messaged): ?>
                                <div class="user-item" data-user-id="<?php echo $messaged['user_id']; ?>">
                                    <img src="<?php echo htmlspecialchars($messaged['profile_picture'] ?? './profile_pics/profile.jpg'); ?>" alt="Profile">
                                    <div class="user-info">
                                        <div class="username"><?php echo htmlspecialchars($messaged['username']); ?></div>
                                        <div class="last-message"><?php echo htmlspecialchars($messaged['last_message'] ?? 'No messages yet'); ?></div>
                                    </div>
                                    <div class="unread-data" data-unread="<?php echo $messaged['unread_count']; ?>"></div>
                                    <div class="more-options" data-user-id="<?php echo $messaged['user_id']; ?>">
                                        <i class="uil uil-ellipsis-h"></i>
                                    </div>
                                    <div class="options-menu" data-user-id="<?php echo $messaged['user_id']; ?>">
                                        <a href="profile.php?user_id=<?php echo $messaged['user_id']; ?>">Open Profile</a>
                                        <button class="delete-chat-btn" data-user-id="<?php echo $messaged['user_id']; ?>">Delete Chat</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div class="chat-area">
                        <div class="no-chat">No chat selected, start a chat.</div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        window.currentUserId = <?php echo json_encode($user_id); ?>;

        // Function to format date
        function formatDate(date) {
            const now = new Date();
            const diff = (now - date) / 1000; // Difference in seconds
            if (diff < 60) return 'Just now';
            if (diff < 3600) return `${Math.floor(diff / 60)}m`;
            if (diff < 86400) return `${Math.floor(diff / 3600)}h`;
            return date.toLocaleString('en-US', {
                month: 'short',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                hour12: false
            }).replace(/,/, '');
        }

        // Function to render a message - UPDATED VERSION WITH POST NAVIGATION
        function renderMessage(message, posts) {
            const isSent = message.sender_id == window.currentUserId;
            const messageDiv = document.createElement('div');
            messageDiv.classList.add('message', isSent ? 'sent' : 'received');
            messageDiv.setAttribute('data-message-id', message.message_id);

            // Check if it's a shared post
            const postMatch = message.content.match(/\(Post ID: (\d+)\)$/);
            if (postMatch && posts[postMatch[1]]) {
                const post = posts[postMatch[1]];
                messageDiv.classList.add('post-preview');

                // Use post owner's username
                const displayContent = `Shared @${post.username}'s post`;

                messageDiv.innerHTML = `
                    <div class="post-content">
                        <p>${displayContent}</p>
                    </div>
                    <div class="post-footer">
                        <a href="#" class="view-post-link" data-post-id="${post.post_id}">View post</a>
                    </div>
                    <div class="message-time">${formatDate(new Date(message.created_at))}</div>
                `;

                // Add click event to the view post link
                const viewPostLink = messageDiv.querySelector('.view-post-link');
                viewPostLink.addEventListener('click', (e) => {
                    e.preventDefault();
                    goToPost(post.post_id);
                });
            } else {
                messageDiv.innerHTML = `
                    <p>${message.content}</p>
                    <div class="message-time">${formatDate(new Date(message.created_at))}</div>
                `;
            }

            return messageDiv;
        }

        // NEW FUNCTION: Navigate to specific post
        function goToPost(postId) {
            console.log('Going to post:', postId);
            
            // Store the post ID in sessionStorage so it persists across page navigation
            sessionStorage.setItem('scrollToPost', postId);
            console.log('Stored in sessionStorage:', sessionStorage.getItem('scrollToPost'));
            
            // Navigate to firstpage.php
            window.location.href = 'firstpage.php';
        }

        // Load conversation
        function loadConversation(userId, username, profilePicture) {
            const chatArea = document.querySelector('.chat-area');
            chatArea.innerHTML = `
                <div class="chat-header">
                    <img src="${profilePicture || './profile_pics/profile.jpg'}" alt="Profile">
                    <span class="username">${username}</span>
                </div>
                <div class="chat-messages"></div>
                <div class="chat-input">
                    <textarea placeholder="Type a message..."></textarea>
                    <button>Send</button>
                </div>
            `;
            const chatMessages = chatArea.querySelector('.chat-messages');

            // Fetch messages
            fetch(`messages.php?get_messages=1&other_user_id=${userId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        chatMessages.innerHTML = '';
                        data.messages.forEach(message => {
                            chatMessages.appendChild(renderMessage(message, data.posts));
                        });
                        chatMessages.scrollTop = chatMessages.scrollHeight;

                        // Mark messages as read
                        fetch('messages.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `mark_as_read=1&sender_id=${userId}`
                        }).then(() => {
                            const unreadElement = document.querySelector(`.user-item[data-user-id="${userId}"] .unread-data`);
                            if (unreadElement) {
                                unreadElement.setAttribute('data-unread', '0');
                            }
                            localStorage.setItem('unread_count_update', Date.now());
                        });
                    } else {
                        chatMessages.innerHTML = '<p>Error loading messages.</p>';
                    }
                })
                .catch(error => {
                    console.error('Error loading messages:', error);
                    chatMessages.innerHTML = '<p>An error occurred while loading messages.</p>';
                });

            // Handle send message
            const sendButton = chatArea.querySelector('.chat-input button');
            const textarea = chatArea.querySelector('.chat-input textarea');
            sendButton.addEventListener('click', () => sendMessage(userId, username, profilePicture, textarea, chatMessages));
            textarea.addEventListener('keypress', e => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage(userId, username, profilePicture, textarea, chatMessages);
                }
            });
        }

        // UPDATED sendMessage function
        function sendMessage(receiverId, receiverUsername, receiverProfilePicture, textarea, chatMessages) {
            const content = textarea.value.trim();
            if (!content) return;

            // Optimistic UI update - add message immediately
            const tempMessage = {
                message_id: 'temp-' + Date.now(),
                sender_id: window.currentUserId,
                receiver_id: receiverId,
                content: content,
                created_at: new Date().toISOString(),
                is_read: 0,
                sender_username: '<?php echo addslashes($username); ?>'
            };
            
            // Add to chat immediately
            chatMessages.appendChild(renderMessage(tempMessage, {}));
            chatMessages.scrollTop = chatMessages.scrollHeight;
            textarea.value = '';

            // Extract post ID if it's a shared post
            const postMatch = content.match(/\(Post ID: (\d+)\)$/);
            let postOwnerUsername = '';
            
            if (postMatch) {
                // For shared posts, fetch the post owner username
                fetch(`firstpage.php?get_post_owner=1&post_id=${postMatch[1]}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            postOwnerUsername = data.username;
                        } else {
                            postOwnerUsername = '<?php echo addslashes($username); ?>';
                        }
                        sendMessageToServer(receiverId, receiverUsername, receiverProfilePicture, content, postOwnerUsername);
                    })
                    .catch(error => {
                        console.error('Error fetching post owner:', error);
                        postOwnerUsername = '<?php echo addslashes($username); ?>';
                        sendMessageToServer(receiverId, receiverUsername, receiverProfilePicture, content, postOwnerUsername);
                    });
            } else {
                // Regular message
                sendMessageToServer(receiverId, receiverUsername, receiverProfilePicture, content, '');
            }
        }

        // NEW sendMessageToServer function
        function sendMessageToServer(receiverId, receiverUsername, receiverProfilePicture, content, postOwnerUsername) {
            fetch('messages.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `send_message=1&receiver_id=${receiverId}&content=${encodeURIComponent(content)}&post_owner_username=${encodeURIComponent(postOwnerUsername)}&receiver_username=${encodeURIComponent(receiverUsername)}&receiver_profile_picture=${encodeURIComponent(receiverProfilePicture)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update the conversation list with correct last message
                    updateLastMessage(receiverId, receiverUsername, receiverProfilePicture, content, postOwnerUsername);
                } else {
                    // Remove the optimistic message if failed
                    const tempMessages = document.querySelectorAll('[data-message-id^="temp-"]');
                    tempMessages[tempMessages.length - 1]?.remove();
                    alert(data.error || 'Failed to send message');
                }
            })
            .catch(error => {
                console.error('Error sending message:', error);
                // Remove the optimistic message if failed
                const tempMessages = document.querySelectorAll('[data-message-id^="temp-"]');
                tempMessages[tempMessages.length - 1]?.remove();
                alert('An error occurred while sending the message');
            });
        }

        // UPDATED updateLastMessage function
        function updateLastMessage(userId, username, profilePicture, content, postOwnerUsername) {
            const followedUsers = document.querySelector('.followed-users');
            let userItem = document.querySelector(`.user-item[data-user-id="${userId}"]`);

            // Prepare last message text based on content type
            let lastMessageText;
            const postMatch = content.match(/\(Post ID: \d+\)$/);
            
            if (postMatch && postOwnerUsername) {
                lastMessageText = `Shared @${postOwnerUsername}'s post`;
            } else if (postMatch) {
                lastMessageText = "Shared a post";
            } else {
                // Regular message - truncate if too long
                lastMessageText = content.length > 30 ? content.substring(0, 30) + '...' : content;
            }

            // Remove "No message history yet" if present
            followedUsers.querySelector('.no-users')?.remove();

            if (userItem) {
                // Update existing conversation
                const lastMessage = userItem.querySelector('.last-message');
                lastMessage.textContent = lastMessageText;
                
                // Move to top of the list
                followedUsers.prepend(userItem);
            } else {
                // Create new conversation item
                userItem = document.createElement('div');
                userItem.classList.add('user-item');
                userItem.setAttribute('data-user-id', userId);
                userItem.innerHTML = `
                    <img src="${profilePicture || './profile_pics/profile.jpg'}" alt="Profile">
                    <div class="user-info">
                        <div class="username">${username}</div>
                        <div class="last-message">${lastMessageText}</div>
                    </div>
                    <div class="unread-data" data-unread="0"></div>
                    <div class="more-options" data-user-id="${userId}">
                        <i class="uil uil-ellipsis-h"></i>
                    </div>
                    <div class="options-menu" data-user-id="${userId}">
                        <a href="profile.php?user_id=${userId}">Open Profile</a>
                        <button class="delete-chat-btn" data-user-id="${userId}">Delete Chat</button>
                    </div>
                `;
                followedUsers.prepend(userItem);

                // Add event listeners to the new item
                addUserItemListeners(userItem, userId, username, profilePicture);
            }

            // Ensure the item is marked as active if it's the current conversation
            if (document.querySelector('.chat-area .chat-header .username')?.textContent === username) {
                document.querySelectorAll('.user-item').forEach(i => i.classList.remove('active'));
                userItem.classList.add('active');
            }
        }

        // NEW Helper function to add event listeners to user items
        function addUserItemListeners(userItem, userId, username, profilePicture) {
            // Main click listener
            userItem.addEventListener('click', e => {
                if (e.target.closest('.more-options') || e.target.closest('.options-menu')) return;
                document.querySelectorAll('.user-item').forEach(i => i.classList.remove('active'));
                userItem.classList.add('active');
                loadConversation(userId, username, profilePicture);
            });

            // More options click listener
            const moreOptions = userItem.querySelector('.more-options');
            moreOptions.addEventListener('click', (e) => {
                e.stopPropagation();
                document.querySelectorAll('.options-menu').forEach(menu => menu.classList.remove('active'));
                const menu = userItem.querySelector('.options-menu');
                menu.classList.toggle('active');
            });

            // Delete chat listener
            const deleteBtn = userItem.querySelector('.delete-chat-btn');
            deleteBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                if (confirm('Are you sure you want to delete this chat?')) {
                    fetch('messages.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `delete_chat=1&other_user_id=${userId}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            userItem.remove();
                            if (document.querySelector('.chat-area .chat-header .username')?.textContent === username) {
                                document.querySelector('.chat-area').innerHTML = '<div class="no-chat">No chat selected, start a chat.</div>';
                            }
                            if (!document.querySelector('.followed-users .user-item')) {
                                document.querySelector('.followed-users').innerHTML = '<p class="no-users">No message history yet.</p>';
                            }
                        } else {
                            alert(data.error || 'Failed to delete chat');
                        }
                    })
                    .catch(error => {
                        console.error('Error deleting chat:', error);
                        alert('An error occurred while deleting the chat');
                    });
                }
            });
        }

        // Event listeners for existing items
        document.querySelectorAll('.user-item').forEach(item => {
            item.addEventListener('click', e => {
                if (e.target.closest('.more-options') || e.target.closest('.options-menu')) return;
                document.querySelectorAll('.user-item').forEach(i => i.classList.remove('active'));
                item.classList.add('active');
                const userId = item.getAttribute('data-user-id');
                const username = item.querySelector('.username').textContent;
                const profilePicture = item.querySelector('img').src;
                loadConversation(userId, username, profilePicture);
            });
        });

        document.querySelectorAll('.more-options').forEach(opt => {
            opt.addEventListener('click', () => {
                const userId = opt.getAttribute('data-user-id');
                const menu = document.querySelector(`.options-menu[data-user-id="${userId}"]`);
                menu.classList.toggle('active');
            });
        });

        document.addEventListener('click', e => {
            if (!e.target.closest('.more-options') && !e.target.closest('.options-menu')) {
                document.querySelectorAll('.options-menu').forEach(menu => menu.classList.remove('active'));
            }
        });

        document.querySelectorAll('.delete-chat-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const userId = btn.getAttribute('data-user-id');
                const username = btn.closest('.user-item').querySelector('.username').textContent;
                if (confirm('Are you sure you want to delete this chat?')) {
                    fetch('messages.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `delete_chat=1&other_user_id=${userId}`
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                const userItem = document.querySelector(`.user-item[data-user-id="${userId}"]`);
                                userItem?.remove();
                                if (document.querySelector('.chat-area .chat-header .username')?.textContent === username) {
                                    document.querySelector('.chat-area').innerHTML = '<div class="no-chat">No chat selected, start a chat.</div>';
                                }
                                if (!document.querySelector('.followed-users .user-item')) {
                                    document.querySelector('.followed-users').innerHTML = '<p class="no-users">No message history yet.</p>';
                                }
                            } else {
                                alert(data.error || 'Failed to delete chat');
                            }
                        })
                        .catch(error => {
                            console.error('Error deleting chat:', error);
                            alert('An error occurred while deleting the chat');
                        });
                }
            });
        });

        const userSearch = document.getElementById('user-search');
        const searchResults = document.getElementById('search-results');
        let searchTimeout;
        userSearch.addEventListener('input', () => {
            clearTimeout(searchTimeout);
            const query = userSearch.value.trim();
            if (query.length < 2) {
                searchResults.classList.remove('active');
                searchResults.innerHTML = '';
                return;
            }
            searchTimeout = setTimeout(() => {
                fetch('messages.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `search_users=1&query=${encodeURIComponent(query)}`
                })
                    .then(response => response.json())
                    .then(data => {
                        searchResults.innerHTML = '';
                        if (data.success && data.users.length > 0) {
                            data.users.forEach(user => {
                                const item = document.createElement('div');
                                item.classList.add('search-result-item');
                                item.setAttribute('data-user-id', user.user_id);
                                item.innerHTML = `
                                    <img src="${user.profile_picture || './profile_pics/profile.jpg'}" alt="Profile">
                                    <span>${user.username}</span>
                                `;
                                item.addEventListener('click', () => {
                                    document.querySelectorAll('.user-item').forEach(i => i.classList.remove('active'));
                                    loadConversation(user.user_id, user.username, user.profile_picture);
                                    searchResults.classList.remove('active');
                                    userSearch.value = '';
                                });
                                searchResults.appendChild(item);
                            });
                            searchResults.classList.add('active');
                        } else {
                            searchResults.innerHTML = '<p style="padding: 10px;">No users found.</p>';
                            searchResults.classList.add('active');
                        }
                    })
                    .catch(error => {
                        console.error('Error searching users:', error);
                        searchResults.innerHTML = '<p style="padding: 10px;">Error searching users.</p>';
                        searchResults.classList.add('active');
                    });
            }, 300);
        });

        document.addEventListener('click', e => {
            if (!e.target.closest('.search-container')) {
                searchResults.classList.remove('active');
            }
        });
    </script>
</body>
</html>