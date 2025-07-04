<?php
session_start();
require 'db_connect.php';

// Установить часовой пояс для PHP
date_default_timezone_set('UTC');

if (isset($_SESSION['user_id'])) {
    $user_id = (int)$_SESSION['user_id'];
    try {
        // Обновить last_active перед выходом
        $stmt = $pdo->prepare("UPDATE users SET last_active = NOW() WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $affected_rows = $stmt->rowCount();
        // Получить новое значение last_active
        $stmt = $pdo->prepare("SELECT last_active FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $last_active = $stmt->fetchColumn();
        error_log("logout: Updated last_active for user_id $user_id to $last_active (UTC), affected rows: $affected_rows");
    } catch (Exception $e) {
        error_log("logout: Failed to update last_active for user_id $user_id: " . $e->getMessage());
    }
}

// Очистить и уничтожить сессию
$_SESSION = [];
session_destroy();

// Удалить cookie сессии
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

header("Location: index.php");
exit;
?>