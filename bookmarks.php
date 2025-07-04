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

// Handle removing bookmark
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_bookmark'])) {
   $post_id = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
   
   if ($post_id > 0) {
       try {
           $stmt = $pdo->prepare("DELETE FROM bookmarks WHERE user_id = ? AND post_id = ?");
           $stmt->execute([$user_id, $post_id]);
           
           header('Content-Type: application/json');
           echo json_encode(['success' => true]);
           exit;
       } catch (PDOException $e) {
           error_log("Error removing bookmark: " . $e->getMessage());
           header('Content-Type: application/json');
           echo json_encode(['success' => false, 'error' => 'Database error']);
           exit;
       }
   }
}

// Fetch bookmarked posts
try {
   $stmt = $pdo->prepare("
       SELECT p.post_id, p.user_id, p.content, p.description, p.media_type, p.media_url, p.created_at, 
              u.username, u.profile_picture,
              b.created_at as bookmarked_at,
              (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.post_id) AS like_count,
              (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.post_id AND l.user_id = ?) AS is_liked_by_user,
              (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.post_id) AS comment_count
       FROM bookmarks b
       JOIN posts p ON b.post_id = p.post_id
       JOIN users u ON p.user_id = u.user_id
       WHERE b.user_id = ?
       ORDER BY b.created_at DESC
   ");
   $stmt->execute([$user_id, $user_id]);
   $bookmarked_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
   error_log("Error fetching bookmarked posts: " . $e->getMessage());
   $bookmarked_posts = [];
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
   <title>My Bookmarks - OmniVox</title>
   <style>
       .bookmarks-header {
           text-align: center;
           padding: 20px 0;
           border-bottom: 1px solid #eee;
           margin-bottom: 20px;
       }
       
       .bookmarks-header h1 {
           margin: 0;
           color: #333;
       }
       
       .bookmarks-count {
           color: #666;
           margin-top: 5px;
       }
       
       .no-bookmarks {
           text-align: center;
           padding: 40px 20px;
           color: #666;
       }
       
       .no-bookmarks i {
           font-size: 4rem;
           color: #ddd;
           margin-bottom: 20px;
       }
       
       .remove-bookmark {
           position: absolute;
           top: 10px;
           right: 50px;
           background: rgba(220, 53, 69, 0.1);
           color: #dc3545;
           border: none;
           padding: 5px 10px;
           border-radius: 5px;
           cursor: pointer;
           font-size: 12px;
           transition: all 0.3s ease;
       }
       
       .remove-bookmark:hover {
           background: #dc3545;
           color: white;
       }
       
       .feed {
           position: relative;
       }
       
       .bookmark-date {
           font-size: 11px;
           color: #999;
           margin-left: 10px;
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
               <div class="bookmarks-header">
                   <h1><i class="uil uil-bookmark"></i> My Bookmarks</h1>
                   <p class="bookmarks-count"><?php echo count($bookmarked_posts); ?> saved posts</p>
               </div>

               <div class="feeds">
                   <?php if (empty($bookmarked_posts)): ?>
                       <div class="no-bookmarks">
                           <i class="uil uil-bookmark"></i>
                           <h3>No bookmarks yet</h3>
                           <p>Posts you bookmark will appear here</p>
                           <a href="firstpage.php" class="btn btn-primary">Browse Posts</a>
                       </div>
                   <?php else: ?>
                       <?php foreach ($bookmarked_posts as $post): ?>
                           <div class="feed" data-post-id="<?php echo $post['post_id']; ?>">
                               <button class="remove-bookmark" data-post-id="<?php echo $post['post_id']; ?>" title="Remove bookmark">
                                   <i class="uil uil-times"></i> Remove
                               </button>
                               
                               <div class="head">
                                   <div class="user">
                                       <div class="profile-photo">
                                           <img src="<?php echo htmlspecialchars($post['profile_picture'] ?? './profile_pics/profile.jpg'); ?>" alt="Profile">
                                       </div>
                                       <div class="info">
                                           <h3><?php echo htmlspecialchars($post['username']); ?></h3>
                                           <small>
                                               <?php echo str_replace(',', '', date('M d, Y H:i', strtotime($post['created_at']))); ?>
                                               <span class="bookmark-date">
                                                   • Saved <?php echo str_replace(',', '', date('M d, Y', strtotime($post['bookmarked_at']))); ?>
                                               </span>
                                           </small>
                                       </div>
                                   </div>
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
                                       <span class="like-btn" data-post-id="<?php echo $post['post_id']; ?>">
                                           <i class="uil uil-heart<?php echo $post['is_liked_by_user'] ? ' liked' : ''; ?>"></i>
                                           <span class="like-count"><?php echo $post['like_count'] > 0 ? $post['like_count'] : ''; ?></span>
                                       </span>
                                       <span><i class="uil uil-comment-dots"></i> <?php echo $post['comment_count']; ?></span>
                                       <span><i class="uil uil-share"></i></span>
                                   </div>
                                   <div class="bookmark">
                                       <span class="bookmark-btn" data-post-id="<?php echo $post['post_id']; ?>">
                                           <i class="uil uil-bookmark bookmarked"></i>
                                       </span>
                                   </div>
                               </div>

                               <?php if (!empty($post['description'])): ?>
                                   <div class="description">
                                       <p><b><?php echo htmlspecialchars($post['username']); ?></b>: <?php echo htmlspecialchars($post['description']); ?></p>
                                   </div>
                               <?php endif; ?>
                           </div>
                       <?php endforeach; ?>
                   <?php endif; ?>
               </div>
           </div>
       </div>
   </main>

   <script>
       // Remove bookmark functionality
       document.body.addEventListener('click', function(e) {
           const removeBtn = e.target.closest('.remove-bookmark');
           if (!removeBtn) return;

           e.preventDefault();
           const postId = removeBtn.getAttribute('data-post-id');
           const feed = removeBtn.closest('.feed');

           if (!postId || isNaN(postId)) {
               alert('Invalid post ID');
               return;
           }

           if (confirm('Are you sure you want to remove this bookmark?')) {
               fetch('bookmarks.php', {
                   method: 'POST',
                   headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                   body: `remove_bookmark=1&post_id=${encodeURIComponent(postId)}&csrf_token=<?php echo urlencode($_SESSION['csrf_token'] ?? ''); ?>`
               })
               .then(response => response.json())
               .then(data => {
                   if (data.success) {
                       feed.style.animation = 'fadeOut 0.3s ease';
                       setTimeout(() => {
                           feed.remove();
                           
                           // Update count
                           const countElement = document.querySelector('.bookmarks-count');
                           const currentCount = parseInt(countElement.textContent.match(/\d+/)[0]);
                           const newCount = currentCount - 1;
                           countElement.textContent = `${newCount} saved posts`;
                           
                           // Show empty state if no bookmarks left
                           if (newCount === 0) {
                               document.querySelector('.feeds').innerHTML = `
                                   <div class="no-bookmarks">
                                       <i class="uil uil-bookmark"></i>
                                       <h3>No bookmarks yet</h3>
                                       <p>Posts you bookmark will appear here</p>
                                       <a href="firstpage.php" class="btn btn-primary">Browse Posts</a>
                                   </div>
                               `;
                           }
                       }, 300);
                   } else {
                       alert('Failed to remove bookmark');
                   }
               })
               .catch(error => {
                   console.error('Error:', error);
                   alert('Error removing bookmark');
               });
           }
       });
   </script>

   <style>
       @keyframes fadeOut {
           from { opacity: 1; transform: translateX(0); }
           to { opacity: 0; transform: translateX(100%); }
       }
   </style>
</body>
</html>