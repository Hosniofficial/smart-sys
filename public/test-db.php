<?php
// Test database connection
header('Content-Type: application/json');

$host = $_ENV['DB_HOST'] ?? getenv('DB_HOST');
$port = $_ENV['DB_PORT'] ?? getenv('DB_PORT');
$db   = $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE');
$user = $_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME');
$pass = $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD');

$result = [
    'status' => 'testing',
    'config' => [
        'host' => $host,
        'port' => $port,
        'database' => $db,
        'username' => $user,
        'password' => $pass ? '***set***' : 'not set',
    ],
    'connection' => null,
    'error' => null,
];

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
    ];
    
    $pdo = new PDO($dsn, $user, $pass, $options);
    $result['connection'] = 'SUCCESS';
    
    // Try a simple query
    $stmt = $pdo->query('SELECT DATABASE() as db, VERSION() as version');
    $info = $stmt->fetch(PDO::FETCH_ASSOC);
    $result['database_info'] = $info;
    
} catch (PDOException $e) {
    $result['connection'] = 'FAILED';
    $result['error'] = $e->getMessage();
    http_response_code(500);
}

echo json_encode($result, JSON_PRETTY_PRINT);
