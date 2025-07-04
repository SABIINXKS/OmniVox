<?php
session_start();
require 'db_connect.php';

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
$stmt = $pdo->prepare("SELECT username, name, email, profile_picture FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();
if (!$user) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Handle group creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_group'])) {
    $group_name = trim($_POST['group_name']);
    $description = trim($_POST['description']);
    $media_url = null;

    if (!empty($_FILES['group_photo']['name'])) {
        $upload_dir = 'group_photos/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        $file_name = basename($_FILES['group_photo']['name']);
        $target_file = $upload_dir . time() . '_' . $file_name;
        $file_type = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];

        if (!in_array($file_type, $allowed_types)) {
            $_SESSION['error'] = 'Invalid or unsupported file type for group photo.';
            header('Location: my-groups.php');
            exit;
        }
        if ($_FILES['group_photo']['size'] > 5 * 1024 * 1024) {
            $_SESSION['error'] = 'Group photo must be less than 5MB.';
            header('Location: my-groups.php');
            exit;
        }
        if (!move_uploaded_file($_FILES['group_photo']['tmp_name'], $target_file)) {
            $_SESSION['error'] = 'Failed to upload group photo.';
            header('Location: my-groups.php');
            exit;
        }
        $media_url = $target_file;
    }

    if (empty($group_name)) {
        $_SESSION['error'] = 'Group name is required.';
        header('Location: my-groups.php');
        exit;
    }
    if (strlen($group_name) > 100) {
        $_SESSION['error'] = 'Group name must be 100 characters or less.';
        header('Location: my-groups.php');
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO groups (group_name, description, creator_id, group_photo, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([$group_name, $description ?: null, $user_id, $media_url]);
    $group_id = $pdo->lastInsertId();

    // Automatically add creator as a member
    $stmt = $pdo->prepare("INSERT INTO group_members (group_id, user_id, joined_at) VALUES (?, ?, NOW())");
    $stmt->execute([$group_id, $user_id]);

    // Set success message and redirect
    $_SESSION['success'] = 'Group created successfully!';
    header("Location: my-groups.php");
    exit;
}

// Handle joining a group
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['join_group'])) {
    $group_id = (int)$_POST['group_id'];

    // Validate that the group exists
    $stmt = $pdo->prepare("SELECT group_id FROM groups WHERE group_id = ?");
    $stmt->execute([$group_id]);
    if (!$stmt->fetch()) {
        header("Location: my-groups.php?error=invalid_group");
        exit;
    }

    // Check if the user is not already a member
    $stmt = $pdo->prepare("SELECT * FROM group_members WHERE group_id = ? AND user_id = ?");
    $stmt->execute([$group_id, $user_id]);
    if (!$stmt->fetch()) {
        $stmt = $pdo->prepare("INSERT INTO group_members (group_id, user_id, joined_at) VALUES (?, ?, NOW())");
        $stmt->execute([$group_id, $user_id]);
    }
    header("Location: group-page.php?group_id=$group_id");
    exit;
}

// Fetch all groups with membership status
$stmt = $pdo->prepare("
    SELECT g.group_id, g.group_name, g.description, g.group_photo, g.created_at,
           (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = g.group_id) AS member_count,
           (SELECT 1 FROM group_members gm WHERE gm.group_id = g.group_id AND gm.user_id = ?) AS is_member
    FROM groups g
    ORDER BY g.created_at DESC
");
$stmt->execute([$user_id]);
$all_groups = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="./img/login.jpg">
    <link rel="stylesheet" href="./css/firstpage.css">
    <link rel="stylesheet" href="https://unicons.iconscout.com/release/v4.0.8/css/line.css">
    <title>My Groups - OmniVox</title>
    <style>
        .middle .groups-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .middle .groups-header h2 {
            font-size: 1.5rem;
            font-weight: bold;
        }
        .middle .group-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        .middle .group-tabs button {
            padding: 10px 20px;
            border: none;
            border-radius: 20px;
            background-color: #e4e6eb;
            cursor: pointer;
            font-weight: 500;
        }
        .middle .group-tabs button.active {
            background-color: #1DA1F2;
            color: white;
        }
        .middle .groups {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }
        .middle .group {
            width: 200px;
            border: 1px solid #e4e6eb;
            border-radius: 10px;
            padding: 10px;
            text-align: center;
            cursor: pointer;
            transition: transform 0.2s;
            position: relative;
        }
        .middle .group:hover {
            transform: scale(1.05);
        }
        .middle .group .group-photo {
            width: 100%;
            height: 120px;
            object-fit: cover;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        .middle .group h4 {
            font-size: 1rem;
            margin-bottom: 5px;
        }
        .middle .group .member-count {
            font-size: 0.9rem;
            color: #666;
        }
        .middle .group .join-btn {
            position: absolute;
            bottom: 5px;
            left: 50%;
            transform: translateX(-50%);
            padding: 5px 10px;
            background-color: #1DA1F2;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            display: none;
        }
        .middle .group:hover .join-btn {
            display: block;
        }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        .modal-content {
            background: white;
            padding: 20px;
            border-radius: 10px;
            width: 90%;
            max-width: 400px;
            text-align: center;
            position: relative;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .modal-header h3 {
            margin: 0;
        }
        .modal-header .close {
            cursor: pointer;
            font-size: 1.5rem;
        }
        .modal-body p {
            margin-bottom: 20px;
        }
        .modal-body .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin: 0 10px;
        }
        .modal-body .btn-confirm {
            background-color: #1DA1F2;
            color: white;
        }
        .modal-body .btn-cancel {
            background-color: #e4e6eb;
            color: #333;
        }
        .modal.show {
            display: flex;
        }
        .modal-body small {
            color: #666;
            text-align: left;
            display: block;
            margin-top: 5px;
        }
        .error-message {
            color: red;
            text-align: center;
            margin-bottom: 10px;
        }
        .success-message {
            color: green;
            text-align: center;
            margin-bottom: 10px;
        }
        .modal-body input[type="text"],
        .modal-body textarea,
        .modal-body input[type="file"] {
            width: 100%;
            padding: 10px;
            margin-bottom: 10px;
            border: 1px solid #e4e6eb;
            border-radius: 5px;
        }
        .preview-photo {
            max-width: 100%;
            margin-top: 10px;
            border-radius: 5px;
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
                <div class="groups-header">
                    <h2>Groups</h2>
                    <?php if (isset($_SESSION['success'])): ?>
                        <div class="success-message" id="success-message">
                            <?php echo htmlspecialchars($_SESSION['success']); ?>
                            <?php unset($_SESSION['success']); ?>
                        </div>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['error'])): ?>
                        <div class="error-message">
                            <?php echo htmlspecialchars($_SESSION['error']); ?>
                            <?php unset($_SESSION['error']); ?>
                        </div>
                    <?php elseif (isset($_GET['error']) && $_GET['error'] === 'invalid_group'): ?>
                        <div class="error-message">
                            The selected group does not exist.
                        </div>
                    <?php endif; ?>
                </div>
                <div class="group-tabs">
                    <button id="allGroupsBtn" class="active">All Groups</button>
                    <button id="myGroupsBtn">My Groups</button>
                    <button id="createGroupBtn">Create Group</button>
                </div>
                <div class="groups" id="groupsContainer">
                    <?php foreach ($all_groups as $group): ?>
                        <a href="group-page.php?group_id=<?php echo $group['group_id']; ?>">
                            <div class="group" data-group-id="<?php echo $group['group_id']; ?>">
                                <img src="<?php echo htmlspecialchars($group['group_photo'] ?? './group_photos/default_group.jpg'); ?>" alt="Group Photo" class="group-photo">
                                <h4><?php echo htmlspecialchars($group['group_name']); ?></h4>
                                <p class="member-count"><?php echo $group['member_count']; ?> members</p>
                                <?php if (!$group['is_member']): ?>
                                    <button class="join-btn" data-group-id="<?php echo $group['group_id']; ?>">Join Group</button>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Create Group Modal -->
    <div id="createGroupModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Create a New Group</h3>
                <span class="close">×</span>
            </div>
            <div class="modal-body">
                <form id="create-group-form" action="my-groups.php" method="POST" enctype="multipart/form-data">
                    <input type="text" name="group_name" placeholder="Group Name" required>
                    <textarea name="description" placeholder="Group Description"></textarea>
                    <input type="file" name="group_photo" accept=".jpg,.jpeg,.png,.gif">
                    <small>Recommended: 16:9 aspect ratio (e.g., 1920x1080)</small>
                    <img id="previewPhoto" class="preview-photo" style="display: none;">
                    <button type="submit" class="btn btn-confirm">Create Group</button>
                    <input type="hidden" name="create_group" value="1">
                </form>
            </div>
        </div>
    </div>

    <!-- Join Group Confirmation Modal -->
    <div id="joinGroupModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Join Group</h3>
                <span class="close">×</span>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to join this group?</p>
                <form id="join-group-form" action="my-groups.php" method="POST">
                    <input type="hidden" name="join_group" value="1">
                    <input type="hidden" id="join-group-id" name="group_id">
                    <button type="submit" class="btn btn-confirm">Yes</button>
                    <button type="button" class="btn btn-cancel" id="cancelJoinBtn">No</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-hide success message after 3 seconds
            const successMessage = document.getElementById('success-message');
            if (successMessage) {
                setTimeout(function() {
                    successMessage.style.transition = 'opacity 0.5s ease';
                    successMessage.style.opacity = '0';
                    setTimeout(function() {
                        successMessage.style.display = 'none';
                    }, 500);
                }, 3000);
            }

            const allGroupsBtn = document.getElementById('allGroupsBtn');
            const myGroupsBtn = document.getElementById('myGroupsBtn');
            const createGroupBtn = document.getElementById('createGroupBtn');
            const groupsContainer = document.getElementById('groupsContainer');
            const createGroupModal = document.getElementById('createGroupModal');
            const createGroupForm = document.getElementById('create-group-form');
            const previewPhoto = document.getElementById('previewPhoto');
            const joinGroupModal = document.getElementById('joinGroupModal');
            const joinGroupForm = document.getElementById('join-group-form');
            const joinGroupId = document.getElementById('join-group-id');
            const cancelJoinBtn = document.getElementById('cancelJoinBtn');

            // All groups data
            const allGroups = <?php echo json_encode(array_map(function($group) {
                return [
                    'group_id' => $group['group_id'],
                    'group_name' => htmlspecialchars($group['group_name']),
                    'group_photo' => $group['group_photo'] ?? './group_photos/default_group.jpg',
                    'member_count' => $group['member_count'],
                    'is_member' => (bool)$group['is_member']
                ];
            }, $all_groups)); ?>;

            // My groups data
            const myGroups = allGroups.filter(group => group.is_member);

            // Function to render groups
            function renderGroups(groups) {
                groupsContainer.innerHTML = groups.length > 0 ? groups.map(group => `
                    <a href="group-page.php?group_id=${group.group_id}">
                        <div class="group" data-group-id="${group.group_id}">
                            <img src="${group.group_photo}" alt="Group Photo" class="group-photo">
                            <h4>${group.group_name}</h4>
                            <p class="member-count">${group.member_count} members</p>
                            ${!group.is_member ? `<button class="join-btn" data-group-id="${group.group_id}">Join Group</button>` : ''}
                        </div>
                    </a>
                `).join('') : '<p>No groups found.</p>';

                // Reattach event listeners for join buttons
                document.querySelectorAll('.join-btn').forEach(button => {
                    button.addEventListener('click', function(e) {
                        e.preventDefault();
                        const groupId = this.getAttribute('data-group-id');
                        joinGroupId.value = groupId;
                        joinGroupModal.classList.add('show');
                    });
                });
            }

            // Show all groups by default
            renderGroups(allGroups);

            // Handle All Groups button
            allGroupsBtn.addEventListener('click', () => {
                allGroupsBtn.classList.add('active');
                myGroupsBtn.classList.remove('active');
                renderGroups(allGroups);
            });

            // Handle My Groups button
            myGroupsBtn.addEventListener('click', () => {
                myGroupsBtn.classList.add('active');
                allGroupsBtn.classList.remove('active');
                renderGroups(myGroups);
            });

            // Handle Create Group button
            createGroupBtn.addEventListener('click', (e) => {
                e.preventDefault();
                createGroupModal.classList.add('show');
                createGroupForm.reset();
                previewPhoto.style.display = 'none';
            });

            // Handle closing the modal
            document.querySelectorAll('.close').forEach(btn => {
                btn.addEventListener('click', () => {
                    createGroupModal.classList.remove('show');
                    joinGroupModal.classList.remove('show');
                    createGroupForm.reset();
                    previewPhoto.style.display = 'none';
                });
            });

            // Handle photo preview
            createGroupForm.querySelector('input[name="group_photo"]').addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = (e) => {
                        previewPhoto.src = e.target.result;
                        previewPhoto.style.display = 'block';
                    };
                    reader.readAsDataURL(file);
                }
            });

            // Handle group creation form submission
            createGroupForm.addEventListener('submit', (e) => {
                e.preventDefault();
                const formData = new FormData(createGroupForm);
                const errorDiv = createGroupModal.querySelector('.error-message');

                fetch('my-groups.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    // Redirect is handled server-side, so we expect the page to reload
                    window.location.href = 'my-groups.php';
                })
                .catch(error => {
                    console.error('Error:', error);
                    const errorMsg = createGroupModal.querySelector('.error-message') || document.createElement('div');
                    errorMsg.classList.add('error-message');
                    errorMsg.textContent = 'An error occurred while creating the group.';
                    if (!errorDiv) createGroupModal.querySelector('.modal-body').insertBefore(errorMsg, createGroupForm);
                });
            });

            // Handle Join Group confirmation
            cancelJoinBtn.addEventListener('click', () => joinGroupModal.classList.remove('show'));
        });
    </script>
</body>
</html>