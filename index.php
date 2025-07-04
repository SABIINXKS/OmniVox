<?php
session_start();
require 'db_connect.php'; // Подключение к базе данных

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['login'])) {
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $remember = isset($_POST['remember']);

        // Updated query to include role and suspension status
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Check if user is suspended
            if (isset($user['is_suspended']) && $user['is_suspended']) {
                $error = "Your account has been suspended. Please contact support.";
            } else {
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'] ?? 'user'; // Default to 'user' if role doesn't exist

                if ($remember) {
                    $session_id = bin2hex(random_bytes(32));
                    $expires_at = date('Y-m-d H:i:s', strtotime('+30 days'));
                    
                    // Check if sessions table exists, if not create it
                    try {
                        $stmt = $pdo->prepare("INSERT INTO sessions (session_id, user_id, expires_at) VALUES (?, ?, ?)");
                        $stmt->execute([$session_id, $user['user_id'], $expires_at]);
                        setcookie('session_id', $session_id, time() + 30 * 24 * 3600, '/');
                    } catch (PDOException $e) {
                        // Sessions table might not exist, continue without remember me
                        error_log("Sessions table error: " . $e->getMessage());
                    }
                }

                // Redirect based on role - admins go to admin panel, moderators to moderator panel
                if (isset($user['role'])) {
                    if ($user['role'] === 'admin') {
                        header("Location: admin_panel.php");
                    } elseif ($user['role'] === 'moderator') {
                        header("Location: moderator_panel.php");
                    } else {
                        header("Location: firstpage.php");
                    }
                } else {
                    header("Location: firstpage.php"); 
                }
                exit;
            }
        } else {
            $error = "Invalid username or password";
        }
    } elseif (isset($_POST['register'])) {
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];

        if ($password !== $confirm_password) {
            $error = "Passwords do not match";
        } elseif (strlen($password) < 8) {
            $error = "Password must be at least 8 characters long";
        } else {
            try {
                // Проверка username
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE LOWER(username) = LOWER(?)");
                $stmt->execute([$username]);
                $username_count = $stmt->fetchColumn();

                // Проверка email
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE LOWER(email) = LOWER(?)");
                $stmt->execute([$email]);
                $email_count = $stmt->fetchColumn();

                if ($username_count > 0) {
                    $error = "Username is already taken";
                } elseif ($email_count > 0) {
                    $error = "Email is already taken. Already have an account? <a href='#' onclick=\"showTab('login')\">Log in here</a>";
                } else {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    
                    // Check if this is the first user to make them admin
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users");
                    $stmt->execute();
                    $total_users = $stmt->fetchColumn();
                    
                    $role = ($total_users == 0) ? 'admin' : 'user'; // First user becomes admin
                    
                    // Insert new user with role and suspension status
                    $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role, is_suspended, created_at) VALUES (?, ?, ?, ?, 0, NOW())");
                    $stmt->execute([$username, $email, $hashed_password, $role]);

                    $user_id = $pdo->lastInsertId();
                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['username'] = $username;
                    $_SESSION['role'] = $role;

                    // Redirect based on role
                    if ($role === 'admin') {
                        header("Location: admin_panel.php");
                    } elseif ($role === 'moderator') {
                        header("Location: moderator_panel.php");
                    } else {
                        header("Location: firstpage.php");
                    }
                    exit;
                }
            } catch (PDOException $e) {
                // Handle case where new columns don't exist yet
                if (strpos($e->getMessage(), 'Unknown column') !== false) {
                    // Try without the new columns
                    try {
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
                        $stmt->execute([$username, $email, $hashed_password]);

                        $user_id = $pdo->lastInsertId();
                        $_SESSION['user_id'] = $user_id;
                        $_SESSION['username'] = $username;
                        $_SESSION['role'] = 'user';

                        header("Location: firstpage.php");
                        exit;
                    } catch (PDOException $e2) {
                        $error = "Database error: " . $e2->getMessage();
                    }
                } else {
                    $error = "Database error: " . $e->getMessage();
                }
            }
        }
    }
}

// Check for error messages from URL parameters
if (isset($_GET['error'])) {
    switch ($_GET['error']) {
        case 'suspended':
            $error = "Your account has been suspended. Please contact support.";
            break;
        case 'access_denied':
            $error = "Access denied. You don't have permission to access that page.";
            break;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="./img/login.jpg">
    <link rel="stylesheet" href="./css/login.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <title>OmniVox - Login & Register</title>
    <style>
        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            border: 1px solid #f5c6cb;
        }
        
        .error-message a {
            color: #721c24;
            text-decoration: underline;
        }
        
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            border: 1px solid #c3e6cb;
        }
        
        .admin-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
            margin-left: 0.5rem;
        }
        
        .form-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .form-header h2 {
            margin-bottom: 0.5rem;
        }
        
        .first-user-notice {
            background: #e7f3ff;
            color: #0c5460;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            border: 1px solid #bee5eb;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="image-section"></div>
        <div class="form-section">
            <div class="form-box">
                <div class="form-header">
                    <h2> Create. Connect. Inspire. </h2>
                    <p>OmniVox is your space to shine.</p>
                </div>

                <!-- Tabs for Login and Register -->
                <div class="tabs">
                    <button class="tab-button active" onclick="showTab('login')">Login</button>
                    <button class="tab-button" onclick="showTab('register')">Register</button>
                </div>

                <!-- Error Message -->
                <?php if (!empty($error)): ?>
                    <div class="error-message"><?php echo $error; ?></div>
                <?php endif; ?>

                <!-- Check if this would be the first user -->
                <?php
                try {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users");
                    $stmt->execute();
                    $total_users = $stmt->fetchColumn();
                    if ($total_users == 0):
                ?>
                    <div class="first-user-notice">
                        <strong>🛡️ First User Registration</strong><br>
                        Since no users exist yet, the first registered user will automatically become an administrator with full access to the admin panel.
                    </div>
                <?php 
                    endif;
                } catch (PDOException $e) {
                    // Ignore if table doesn't exist yet
                }
                ?>

                <!-- Login Form -->
                <div id="login" class="tab-content active">
                    <form action="index.php" method="POST">
                        <input type="hidden" name="login" value="1">
                        <div class="form-group">
                            <input type="text" name="username" placeholder="Username or Email" required 
                                   value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <input type="password" name="password" placeholder="Password" required>
                        </div>
                        <div class="form-options">
                            <!-- <label>
                                <input type="checkbox" name="remember"> Remember me
                            </label> -->
                            <!-- <a href="forgot-password.php" class="forgot-password">Forgot password?</a> -->
                        </div>
                        <button type="submit" class="submit-btn">Sign In</button>
                    </form>
                    <p class="switch-form">Don't have an account? <a href="#" onclick="showTab('register')">Register now</a></p>
                </div>

                <!-- Register Form -->
                <div id="register" class="tab-content">
                    <form action="index.php" method="POST">
                        <input type="hidden" name="register" value="1">
                        <div class="form-group">
                            <input type="text" name="username" placeholder="Username" required 
                                   value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <input type="email" name="email" placeholder="Email" required 
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <input type="password" name="password" placeholder="Password (min 8 characters)" required>
                        </div>
                        <div class="form-group">
                            <input type="password" name="confirm_password" placeholder="Confirm Password" required>
                        </div>
                        <button type="submit" class="submit-btn">
                            Create Account
                            <?php
                            try {
                                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users");
                                $stmt->execute();
                                $total_users = $stmt->fetchColumn();
                                if ($total_users == 0):
                            ?>
                                <span class="admin-badge">Will become Admin</span>
                            <?php 
                                endif;
                            } catch (PDOException $e) {
                                // Ignore if table doesn't exist yet
                            }
                            ?>
                        </button>
                    </form>
                    <p class="switch-form">Already have an account? <a href="#" onclick="showTab('login')">Sign in</a></p>
                </div>
            </div>
        </div>
    </div>

    <script>
        function showTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('active');
            });
            document.getElementById(tabName).classList.add('active');
            document.querySelector(`.tab-button[onclick="showTab('${tabName}')"]`).classList.add('active');
            
            // Clear any error messages when switching tabs
            const errorMessage = document.querySelector('.error-message');
            if (errorMessage) {
                errorMessage.style.display = 'none';
            }
        }

        // Show register tab if there was a registration error
        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register']) && !empty($error)): ?>
            showTab('register');
        <?php endif; ?>

        const hamburgerBtn = document.querySelector('.hamburger-btn');
        const menu = document.querySelector('.hamburger-menu');
        const closeBtn = document.querySelector('.close-btn');

        if (hamburgerBtn && menu) {
            hamburgerBtn.addEventListener('click', () => {
                menu.classList.toggle('active');
            });
        }

        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                menu.classList.remove('active');
            });
        }

        // Auto-hide error messages after 10 seconds
        const errorMessage = document.querySelector('.error-message');
        if (errorMessage) {
            setTimeout(() => {
                errorMessage.style.opacity = '0';
                setTimeout(() => {
                    errorMessage.style.display = 'none';
                }, 300);
            }, 10000);
        }
    </script>
</body>
</html>


