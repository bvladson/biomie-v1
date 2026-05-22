<?php
$host = '193.203.175.140';
$port = 3306;
$db   = 'u106688109_Comercial';
$user = 'u106688109_Comercial_user';
$pass = 'Mag@6135';

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "Connection successful!\n";
    $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $dbUser = $pdo->query("SELECT CURRENT_USER()")->fetchColumn();
    $version = $pdo->query("SELECT VERSION()")->fetchColumn();
    
    echo "DATABASE(): $dbName\n";
    echo "CURRENT_USER(): $dbUser\n";
    echo "VERSION(): $version\n";
} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage() . "\n";
}
