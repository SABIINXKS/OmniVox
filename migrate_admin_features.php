<?php
// Database migration script for admin features
// Run this once to add admin functionality to existing database

require 'db_connect.php';

echo "<h2>OmniVox Admin Features Migration</h2>";
echo "<p>Adding admin features to your database...</p>";

try {
    // Check if role column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'");
    if ($stmt->rowCount() == 0) {
        echo "<p>Adding 'role' column...</p>";
        $pdo->exec("ALTER TABLE users ADD COLUMN role ENUM('user', 'moderator', 'admin') DEFAULT 'user'");
        echo "<p style='color: green;'>✓ Role column added successfully</p>";
    } else {
        echo "<p style='color: orange;'>⚠ Role column already exists</p>";
    }

    // Check if is_suspended column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_suspended'");
    if ($stmt->rowCount() == 0) {
        echo "<p>Adding 'is_suspended' column...</p>";
        $pdo->exec("ALTER TABLE users ADD COLUMN is_suspended BOOLEAN DEFAULT FALSE");
        echo "<p style='color: green;'>✓ Suspension column added successfully</p>";
    } else {
        echo "<p style='color: orange;'>⚠ Suspension column already exists</p>";
    }

    // Check if created_at column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'created_at'");
    if ($stmt->rowCount() == 0) {
        echo "<p>Adding 'created_at' column...</p>";
        $pdo->exec("ALTER TABLE users ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        echo "<p style='color: green;'>✓ Created_at column added successfully</p>";
    } else {
        echo "<p style='color: orange;'>⚠ Created_at column already exists</p>";
    }

    // Check if there are any admin users
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
    $admin_count = $stmt->fetchColumn();

    if ($admin_count == 0) {
        echo "<p>No admin users found. Making the first user an admin...</p>";
        
        // Get the first user
        $stmt = $pdo->query("SELECT user_id, username, email FROM users ORDER BY user_id ASC LIMIT 1");
        $first_user = $stmt->fetch();
        
        if ($first_user) {
            $pdo->prepare("UPDATE users SET role = 'admin' WHERE user_id = ?")->execute([$first_user['user_id']]);
            echo "<p style='color: green;'>✓ User '{$first_user['username']}' ({$first_user['email']}) is now an admin</p>";
        } else {
            echo "<p style='color: red;'>✗ No users found in database</p>";
        }
    } else {
        echo "<p style='color: green;'>✓ Admin users already exist ($admin_count admin(s))</p>";
    }

    // Optional: Create sessions table for remember me functionality
    $stmt = $pdo->query("SHOW TABLES LIKE 'sessions'");
    if ($stmt->rowCount() == 0) {
        echo "<p>Creating sessions table for 'remember me' functionality...</p>";
        $pdo->exec("
            CREATE TABLE sessions (
                session_id VARCHAR(64) PRIMARY KEY,
                user_id INT NOT NULL,
                expires_at TIMESTAMP NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
            )
        ");
        echo "<p style='color: green;'>✓ Sessions table created successfully</p>";
    } else {
        echo "<p style='color: orange;'>⚠ Sessions table already exists</p>";
    }

    // Show current admin users
    echo "<h3>Current Admin Users:</h3>";
    $stmt = $pdo->query("SELECT user_id, username, email, role, is_suspended, created_at FROM users WHERE role IN ('admin', 'moderator') ORDER BY role DESC, user_id ASC");
    $admins = $stmt->fetchAll();
    
    if ($admins) {
        echo "<table style='border-collapse: collapse; width: 100%; margin: 1rem 0;'>";
        echo "<tr style='background: #f8f9fa;'>";
        echo "<th style='border: 1px solid #ddd; padding: 8px;'>ID</th>";
        echo "<th style='border: 1px solid #ddd; padding: 8px;'>Username</th>";
        echo "<th style='border: 1px solid #ddd; padding: 8px;'>Email</th>";
        echo "<th style='border: 1px solid #ddd; padding: 8px;'>Role</th>";
        echo "<th style='border: 1px solid #ddd; padding: 8px;'>Status</th>";
        echo "<th style='border: 1px solid #ddd; padding: 8px;'>Created</th>";
        echo "</tr>";
        
        foreach ($admins as $admin) {
            $status = $admin['is_suspended'] ? 'Suspended' : 'Active';
            $statusColor = $admin['is_suspended'] ? 'red' : 'green';
            $roleColor = $admin['role'] === 'admin' ? '#dc3545' : '#fd7e14';
            
            echo "<tr>";
            echo "<td style='border: 1px solid #ddd; padding: 8px;'>{$admin['user_id']}</td>";
            echo "<td style='border: 1px solid #ddd; padding: 8px;'>{$admin['username']}</td>";
            echo "<td style='border: 1px solid #ddd; padding: 8px;'>{$admin['email']}</td>";
            echo "<td style='border: 1px solid #ddd; padding: 8px; color: {$roleColor}; font-weight: bold;'>{$admin['role']}</td>";
            echo "<td style='border: 1px solid #ddd; padding: 8px; color: {$statusColor};'>{$status}</td>";
            echo "<td style='border: 1px solid #ddd; padding: 8px;'>{$admin['created_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: red;'>No admin or moderator users found</p>";
    }

    // Show database statistics
    echo "<h3>Database Statistics:</h3>";
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    $total_users = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM posts");
    $total_posts = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM comments");
    $total_comments = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE is_suspended = 1");
    $suspended_users = $stmt->fetchColumn();

    echo "<ul>";
    echo "<li><strong>Total Users:</strong> {$total_users}</li>";
    echo "<li><strong>Total Posts:</strong> {$total_posts}</li>";
    echo "<li><strong>Total Comments:</strong> {$total_comments}</li>";
    echo "<li><strong>Suspended Users:</strong> {$suspended_users}</li>";
    echo "</ul>";

    echo "<h3 style='color: green;'>✅ Migration completed successfully!</h3>";
    echo "<p><strong>Next steps:</strong></p>";
    echo "<ul>";
    echo "<li>You can now access the admin panel at <a href='admin_panel.php' target='_blank'>admin_panel.php</a></li>";
    echo "<li>Admin users can manage content and users</li>";
    echo "<li>You can promote other users to moderator or admin roles</li>";
    echo "</ul>";

} catch (PDOException $e) {
    echo "<p style='color: red;'>✗ Error during migration: " . $e->getMessage() . "</p>";
    echo "<p>Please check your database connection and try again.</p>";
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>OmniVox Migration</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 2rem;
            background: #f8f9fa;
        }
        h2, h3 {
            color: #333;
        }
        p {
            line-height: 1.6;
        }
        a {
            color: #007bff;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
        .back-link {
            display: inline-block;
            margin-top: 2rem;
            padding: 0.75rem 1.5rem;
            background: #007bff;
            color: white;
            border-radius: 5px;
            text-decoration: none;
        }
        .back-link:hover {
            background: #0056b3;
        }
    </style>
</head>
<body>
    <a href="index.php" class="back-link">← Back to Login</a>
    <br><br>
    <a href="admin_panel.php" class="back-link">Go to Admin Panel →</a>
</body>
</html>