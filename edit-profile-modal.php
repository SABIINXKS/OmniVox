<?php
session_start();
require 'db_connect.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
   header("Location: login.php");
   exit;
}

$user_id = $_SESSION['user_id'];

// Fetch user profile
try {
   $stmt = $pdo->prepare("SELECT username, name, profile_picture FROM users WHERE user_id = ?");
   $stmt->execute([$user_id]);
   $user = $stmt->fetch();
   if (!$user) {
       error_log("User not found for user_id: $user_id");
       die("User not found");
   }
} catch (PDOException $e) {
   error_log("Error fetching user: " . $e->getMessage());
   die("Database error");
}
?>

<div id="editProfileModal" class="modal hidden">
   <div class="modal-content">
       <div class="modal-header">
           <h3>Edit Profile</h3>
           <span class="close">×</span>
       </div>
       <div class="modal-body">
           <form action="user-profile.php" method="POST" enctype="multipart/form-data" id="editProfileForm">
               <input type="hidden" name="update_profile" value="1">
               <div class="form-group">
                   <label for="avatar">Profile Picture</label>
                   <div class="avatar-preview">
                       <img src="<?php echo htmlspecialchars($user['profile_picture'] ?? './profile_pics/profile.jpg'); ?>" alt="Profile Picture" id="avatar-preview">
                   </div>
                   <input type="file" name="avatar" id="avatar" accept="image/*" onchange="previewAvatar(event)">
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
function previewAvatar(event) {
   const reader = new FileReader();
   reader.onload = function() {
       const output = document.getElementById('avatar-preview');
       output.src = reader.result;
   };
   if (event.target.files[0]) {
       reader.readAsDataURL(event.target.files[0]);
   }
}
</script>