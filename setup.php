<?php
/**
 * OmniVox sociālo mediju platformas setup skripts
 * Pārbauda un inicializē sistēmu
 */

error_reporting(E_ALL);
ini_set('display_errors', true);

// Iekļaujam nepieciešamos failus
$coreFiles = [
    'config.php' => 'Konfigurācijas fails',
    'db_connect.php' => 'Datubāzes savienojuma fails',
    'AdminAuth.php' => 'Admin autentifikācijas klase',
    // 'firstpage.php' => 'Galvenās lapas fails'
];

$missingFiles = [];
foreach ($coreFiles as $file => $description) {
    if (!file_exists($file)) {
        $missingFiles[$file] = $description;
    } else {
        require_once $file;
    }
}

// Sākam HTML izvadi
echo "<html><head><title>OmniVox Sistēmas Setup</title><meta charset='UTF-8'>";
echo "<style>
body { font-family: Arial, sans-serif; max-width: 1000px; margin: 20px auto; padding: 20px; background: #f5f5f5; }
.container { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
.success { background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; border-radius: 5px; margin: 10px 0; }
.warning { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 10px 0; }
.error { background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; border-radius: 5px; margin: 10px 0; }
.info { background: #d1ecf1; border: 1px solid #bee5eb; padding: 15px; border-radius: 5px; margin: 10px 0; }
.btn { background: #007bff; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px; margin: 5px; display: inline-block; }
.btn-success { background: #28a745; }
.btn-warning { background: #ffc107; color: #212529; }
.btn-danger { background: #dc3545; }
table { border-collapse: collapse; width: 100%; margin: 10px 0; }
th, td { border: 1px solid #dee2e6; padding: 8px; text-align: left; }
th { background: #f8f9fa; }
.status-ok { color: #28a745; font-weight: bold; }
.status-error { color: #dc3545; font-weight: bold; }
.status-warning { color: #ffc107; font-weight: bold; }
h1, h2, h3 { color: #333; }
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 20px 0; }
.card { background: #f8f9fa; padding: 20px; border-radius: 8px; border: 1px solid #e9ecef; }
details { margin: 10px 0; }
summary { cursor: pointer; font-weight: bold; }
pre { background: #f4f4f4; padding: 10px; border-radius: 4px; }
</style></head><body>";

echo "<div class='container'>";
echo "<h1>🚀 OmniVox sociālo mediju platformas Setup</h1>";
echo "<p>Šis skripts pārbauda sistēmas gatavību un veic sākotnējo konfigurāciju.</p>";
echo "<hr>";

// 1. Pārbaudām failu esamību
echo "<h2>📁 Failu pārbaude</h2>";
if (!empty($missingFiles)) {
    echo "<div class='error'>";
    echo "❌ <strong>Trūkst nepieciešamie faili:</strong><br>";
    foreach ($missingFiles as $file => $description) {
        echo "• {$description} ({$file})<br>";
    }
    echo "</div>";
    echo "</div></body></html>";
    exit;
} else {
    echo "<div class='success'>✅ Visi nepieciešamie core faili atrasti</div>";
}

// 2. Pārbaudām datubāzes savienojumu
echo "<h2>🗄 Datubāzes pārbaude</h2>";
try {
    if (!isset($pdo)) {
        throw new Exception("PDO savienojums nav definēts db_connect.php failā.");
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<div class='success'>✅ Datubāzes savienojums veiksmīgs</div>";

    // Pārbaudām tabulu esamību
    $requiredTables = ['users', 'posts', 'likes', 'comments'];
    $existingTables = [];
    $stmt = $pdo->query("SHOW TABLES");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $existingTables[] = $row[0];
    }

    $missingTables = array_diff($requiredTables, $existingTables);
    if (empty($missingTables)) {
        echo "<div class='success'>✅ Visas nepieciešamās tabulas eksistē</div>";
    } else {
        echo "<div class='warning'>";
        echo "<strong>⚠ Trūkstošās tabulas:</strong><br>";
        foreach ($missingTables as $table) {
            echo "• {$table}<br>";
        }
        echo "<a href='install/database.sql' class='btn btn-warning'>Lejupielādēt SQL skriptu</a>";
        echo "</div>";
    }
} catch (Exception $e) {
    echo "<div class='error'>";
    echo "❌ <strong>Datubāzes kļūda:</strong> " . htmlspecialchars($e->getMessage());
    echo "</div>";
}

// 3. Pārbaudām konfigurāciju
echo "<h2>⚙ Konfigurācijas pārbaude</h2>";
$configItems = [
    'DB_HOST' => defined('DB_HOST') ? DB_HOST : null,
    'DB_NAME' => defined('DB_NAME') ? DB_NAME : null,
    'DB_USER' => defined('DB_USER') ? DB_USER : null,
    'APP_NAME' => defined('APP_NAME') ? APP_NAME : 'OmniVox',
    'DEBUG_MODE' => defined('DEBUG_MODE') ? (DEBUG_MODE ? 'true' : 'false') : 'false'
];

echo "<table>";
echo "<tr><th>Parametrs</th><th>Vērtība</th><th>Status</th></tr>";
foreach ($configItems as $key => $value) {
    $status = $value ? "✅" : "❌";
    $statusClass = $value ? "status-ok" : "status-error";
    echo "<tr><td>{$key}</td><td>" . ($value ?: 'Nav iestatīts') . "</td><td class='{$statusClass}'>{$status}</td></tr>";
}
echo "</table>";

// 4. Pārbaudām AdminAuth klasi
echo "<h2>🔐 AdminAuth klases pārbaude</h2>";
if (class_exists('AdminAuth')) {
    echo "<div class='success'>✅ AdminAuth klase eksistē</div>";

    // Pārbaudām metodes
    $requiredMethods = ['login', 'logout', 'isAdmin'];
    echo "<table>";
    echo "<tr><th>Metode</th><th>Status</th></tr>";
    foreach ($requiredMethods as $method) {
        $exists = method_exists('AdminAuth', $method);
        $status = $exists ? "✅" : "❌";
        $statusClass = $exists ? "status-ok" : "status-error";
        echo "<tr><td>{$method}</td><td class='{$statusClass}'>{$status}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<div class='error'>❌ AdminAuth klase neeksistē</div>";
}

// 5. Pārbaudām admin eksistenci
echo "<h2>👤 Admin lietotāju pārbaude</h2>";
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin'");
    $stmt->execute();
    $adminCount = $stmt->fetchColumn();

    if ($adminCount > 0) {
        echo "<div class='success'>✅ Atrasti {$adminCount} administratori</div>";

        // Rādām admin sarakstu
        $stmt = $pdo->prepare("SELECT user_id, username, email, created_at FROM users WHERE role = 'admin' ORDER BY created_at DESC LIMIT 5");
        $stmt->execute();
        $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo "<table>";
        echo "<tr><th>ID</th><th>Lietotājvārds</th><th>E-pasts</th><th>Izveidots</th></tr>";
        foreach ($admins as $admin) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($admin['user_id']) . "</td>";
            echo "<td>" . htmlspecialchars($admin['username']) . "</td>";
            echo "<td>" . htmlspecialchars($admin['email']) . "</td>";
            echo "<td>" . date('d.m.Y H:i', strtotime($admin['created_at'])) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<div class='warning'>";
        echo "⚠ <strong>Nav atrasts neviens administrators</strong><br>";
        echo "<a href='admin/create-admin.php' class='btn btn-warning'>Izveidot admin</a>";
        echo "</div>";
    }
} catch (Exception $e) {
    echo "<div class='error'>❌ Neizdevās pārbaudīt adminus: " . htmlspecialchars($e->getMessage()) . "</div>";
}

// 6. Pārbaudām demo datus
echo "<h2>📊 Demo datu pārbaude</h2>";
try {
    $tables = ['users', 'posts'];
    echo "<table>";
    echo "<tr><th>Tabula</th><th>Ierakstu skaits</th><th>Status</th></tr>";

    foreach ($tables as $table) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE " . ($table === 'users' ? "username LIKE 'demo%'" : "content LIKE 'Demo%'"));
        $stmt->execute();
        $count = $stmt->fetchColumn();
        $status = $count > 0 ? "✅" : "⚠";
        $statusClass = $count > 0 ? "status-ok" : "status-warning";
        echo "<tr><td>" . ucfirst($table) . "</td><td>{$count}</td><td class='{$statusClass}'>{$status}</td></tr>";
    }
    echo "</table>";

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username LIKE 'demo%'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        echo "<div class='info'>";
        echo "ℹ <strong>Nav demo datu.</strong> Varat ielādēt demo datus ar SQL skriptu.<br>";
        echo "<a href='install/demo-data.sql' class='btn'>Lejupielādēt demo datus</a>";
        echo "</div>";
    }
} catch (Exception $e) {
    echo "<div class='error'>❌ Neizdevās pārbaudīt datus: " . htmlspecialchars($e->getMessage()) . "</div>";
}

// 7. Sistēmas statusu kopsavilkums
echo "<h2>🎯 Sistēmas statuss</h2>";
echo "<div class='grid'>";

echo "<div class='card'>";
echo "<h3>🌐 Frontend</h3>";
echo "<ul>";
echo "<li><a href='firstpage.php' target='_blank'>Galvenā lapa</a></li>";
echo "<li><a href='user-profile.php' target='_blank'>Profila lapa</a></li>";
echo "<li><a href='admin/index.php' target='_blank'>Admin pieteikšanās</a></li>";
echo "<li><a href='admin/dashboard.php' target='_blank'>Admin panelis</a></li>";
echo "</ul>";
echo "</div>";

echo "<div class='card'>";
echo "<h3>🔧 API Endpoints</h3>";
echo "<ul>";
echo "<li><a href='api/posts/get-posts.php' target='_blank'>Iegūt postus</a></li>";
echo "<li><a href='api/comments/add-comment.php' target='_blank'>Pievienot komentāru</a></li>";
echo "<li><a href='api/likes/toggle-like.php' target='_blank'>Like pārslēgšana</a></li>";
echo "<li><a href='admin/check-auth.php' target='_blank'>Autentifikācijas pārbaude</a></li>";
echo "</ul>";
echo "</div>";

echo "</div>";

// 8. Nākamie soļi
echo "<h2>🚀 Nākamie soļi</h2>";

$hasAdmin = false;
$hasData = false;
$systemReady = empty($missingFiles) && isset($pdo);

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin'");
    $stmt->execute();
    $hasAdmin = $stmt->fetchColumn() > 0;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username LIKE 'demo%'");
    $stmt->execute();
    $hasData = $stmt->fetchColumn() > 0;
} catch (Exception $e) {
    $systemReady = false;
}

if (!$hasAdmin) {
    echo "<div class='warning'>";
    echo "1️⃣ <strong>Izveidojiet administratoru:</strong><br>";
    echo "<a href='admin/create-admin.php' class='btn btn-warning'>Izveidot Admin</a>";
    echo "</div>";
    $systemReady = false;
}

if (!$hasData) {
    echo "<div class='info'>";
    echo "2️⃣ <strong>Ielādējiet demo datus (opcija):</strong><br>";
    echo "Varat ielādēt demo lietotājus un postus testēšanai.<br>";
    echo "<small>Palaidiet SQL skriptu: install/demo-data.sql</small>";
    echo "</div>";
}

if ($systemReady && $hasAdmin) {
    echo "<div class='success'>";
    echo "✅ <strong>Sistēma ir gatava darbam!</strong><br>";
    echo "Varat sākt izmantot sistēmu:<br><br>";
    echo "<a href='admin/index.php' class='btn btn-success'>Admin pieteikšanās</a>";
    echo "<a href='firstpage.php' class='btn'>Galvenā lapa</a>";
    echo "<a href='user-profile.php' class='btn'>Profila lapa</a>";
    echo "</div>";
}

// 9. Tehniskā informācija
echo "<h2>🔧 Tehniskā informācija</h2>";
echo "<div class='grid'>";

echo "<div class='card'>";
echo "<h3>Servera informācija</h3>";
echo "<ul>";
echo "<li><strong>PHP versija:</strong> " . phpversion() . "</li>";
echo "<li><strong>Serveris:</strong> " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Nezināms') . "</li>";
echo "<li><strong>Dokumentu sakne:</strong> " . ($_SERVER['DOCUMENT_ROOT'] ?? 'Nezināms') . "</li>";
echo "<li><strong>Pašreizējā URL:</strong> " . (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $_SERVER['REQUEST_URI'] . "</li>";
echo "</ul>";
echo "</div>";

echo "<div class='card'>";
echo "<h3>PHP paplašinājumi</h3>";
$requiredExtensions = ['pdo', 'pdo_mysql', 'json', 'mbstring'];
echo "<ul>";
foreach ($requiredExtensions as $ext) {
    $loaded = extension_loaded($ext);
    $status = $loaded ? "✅" : "❌";
    echo "<li>{$status} {$ext}</li>";
}
echo "</ul>";
echo "</div>";

echo "</div>";

// 10. Debug informācija
if (defined('DEBUG_MODE') && DEBUG_MODE) {
    echo "<h2>🐛 Debug informācija</h2>";
    echo "<div class='info'>";
    echo "<strong>Debug režīms ir ieslēgts</strong><br>";
    echo "<small>Atslēdziet production vidē, iestatot DEBUG_MODE = false config.php failā</small>";
    echo "</div>";

    echo "<details>";
    echo "<summary>Konfigurācijas dati</summary>";
    echo "<pre>";
    $debugConfig = [
        'DB_HOST' => defined('DB_HOST') ? DB_HOST : 'Nav definēts',
        'DB_NAME' => defined('DB_NAME') ? DB_NAME : 'Nav definēts',
        'DB_USER' => defined('DB_USER') ? DB_USER : 'Nav definēts',
        'APP_NAME' => defined('APP_NAME') ? APP_NAME : 'OmniVox',
        'DEBUG_MODE' => defined('DEBUG_MODE') ? (DEBUG_MODE ? 'true' : 'false') : 'false'
    ];
    print_r($debugConfig);
    echo "</pre>";
    echo "</details>";

    echo "<details>";
    echo "<summary>Servera informācija</summary>";
    echo "<pre>";
    print_r([
        'PHP_VERSION' => phpversion(),
        'SERVER_SOFTWARE' => $_SERVER['SERVER_SOFTWARE'] ?? 'Nezināms',
        'DOCUMENT_ROOT' => $_SERVER['DOCUMENT_ROOT'] ?? 'Nezināms',
        'HTTP_HOST' => $_SERVER['HTTP_HOST'] ?? 'Nezināms',
        'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? 'Nezināms'
    ]);
    echo "</pre>";
    echo "</details>";
}

// 11. Problēmu risinājumi
echo "<h2>🆘 Bieži sastopamās problēmas</h2>";
echo "<div class='info'>";
echo "<h4>Datubāzes savienojums neizdodas:</h4>";
echo "<ul>";
echo "<li>Pārbaudiet DB_HOST, DB_NAME, DB_USER, DB_PASS config.php failā.</li>";
echo "<li>Pārliecinieties, ka MySQL serveris ir palaists.</li>";
echo "<li>Pārbaudiet lietotāja privilēģijas datubāzē.</li>";
echo "</ul>";

echo "<h4>AdminAuth klase neeksistē:</h4>";
echo "<ul>";
echo "<li>Pārliecinieties, ka AdminAuth.php fails ir saknes mapē.</li>";
echo "<li>Pārbaudiet, vai klasē ir definētas metodes login, logout, isAdmin.</li>";
echo "</ul>";

echo "<h4>Nav admin lietotāju:</h4>";
echo "<ul>";
echo "<li>Izveidojiet admin lietotāju, izmantojot admin/create-admin.php.</li>";
echo "<li>Manuāli pievienojiet SQL: <code>INSERT INTO users (username, email, password_hash, role) VALUES ('admin', 'admin@example.com', '" . password_hash('admin123', PASSWORD_DEFAULT) . "', 'admin');</code></li>";
echo "</ul>";

echo "<h4>404 kļūdas frontend lapās:</h4>";
echo "<ul>";
echo "<li>Pārbaudiet, vai firstpage.php un profile.php eksistē.</li>";
echo "<li>Pārliecinieties, ka CSS un JS faili ir pareizajās mapēs (css/, js/).</li>";
echo "</ul>";
echo "</div>";

// Footer
echo "<hr>";
echo "<div style='text-align: center; margin-top: 30px;'>";
echo "<h3>🎉 OmniVox sociālo mediju platforma</h3>";
echo "<p>Setup pabeigts: " . date('Y-m-d H:i:s') . "</p>";
echo "<small>Versija 1.0 | Izveidots kursa darbam</small>";
echo "</div>";

echo "</div>"; // container
echo "</body></html>";
?>