<?php
// Enhanced authentication check with suspension status
// Include this at the top of pages that require authentication

function checkAuthAndSuspension($pdo) {
    if (!isset($_SESSION['user_id'])) {
        header("Location: index.php");
        exit;
    }
    
    $user_id = $_SESSION['user_id'];
    
    try {
        $stmt = $pdo->prepare("SELECT username, role, is_suspended FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        if (!$user) {
            session_destroy();
            header("Location: index.php");
            exit;
        }
        
        // Check if user is suspended
        if ($user['is_suspended']) {
            session_destroy();
            header("Location: index.php?error=suspended");
            exit;
        }
        
        return $user;
        
    } catch (PDOException $e) {
        error_log("Error checking user status: " . $e->getMessage());
        header("Location: index.php");
        exit;
    }
}

function checkAdminAccess($pdo) {
    $user = checkAuthAndSuspension($pdo);
    
    if ($user['role'] !== 'admin' && $user['role'] !== 'moderator') {
        header("Location: firstpage.php?error=access_denied");
        exit;
    }
    
    return $user;
}

function isAdmin($role) {
    return $role === 'admin';
}

function isModerator($role) {
    return $role === 'moderator' || $role === 'admin';
}
?>