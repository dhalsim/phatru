<?php

/**
 * Database setup script for Khatru PHP Relay
 * This script helps you create the MySQL database and user
 */

echo "🗄️  Khatru PHP Relay - Database Setup\n";
echo "=====================================\n\n";

// Check if config file exists
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    echo "❌ Configuration file not found!\n";
    echo "Please copy config.example.php to config.php and configure it first.\n";
    exit(1);
}

$config = require $configFile;

echo "📋 Database Configuration:\n";
echo "Host: {$config['database']['host']}\n";
echo "Port: {$config['database']['port']}\n";
echo "Database: {$config['database']['name']}\n";
echo "Username: {$config['database']['username']}\n";
echo "Table: {$config['database']['table_name']}\n\n";

echo "🔧 This script will help you set up the MySQL database.\n";
echo "You'll need MySQL root access or a user with CREATE privileges.\n\n";

// Get MySQL root credentials
echo "Enter MySQL root username (default: root): ";
$rootUser = trim(fgets(STDIN)) ?: 'root';

echo "Enter MySQL root password: ";
$rootPass = trim(fgets(STDIN));

echo "\n🔌 Testing root connection...\n";

try {
    $rootDsn = sprintf(
        'mysql:host=%s;port=%d;charset=%s',
        $config['database']['host'],
        $config['database']['port'],
        $config['database']['charset']
    );

    $rootPdo = new \PDO($rootDsn, $rootUser, $rootPass, [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
    ]);
    
    echo "✅ Root connection successful\n";
} catch (\PDOException $e) {
    echo "❌ Root connection failed: " . $e->getMessage() . "\n";
    echo "\nPossible solutions:\n";
    echo "1. Make sure MySQL server is running\n";
    echo "2. Check your root credentials\n";
    echo "3. Try: sudo systemctl start mysql (Linux) or brew services start mysql (macOS)\n";
    exit(1);
}

// Create database
echo "\n📦 Creating database...\n";
try {
    $sql = sprintf(
        "CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET %s COLLATE %s_unicode_ci",
        $config['database']['name'],
        $config['database']['charset'],
        $config['database']['charset']
    );
    
    $rootPdo->exec($sql);
    echo "✅ Database '{$config['database']['name']}' created/verified\n";
} catch (\PDOException $e) {
    echo "❌ Failed to create database: " . $e->getMessage() . "\n";
    exit(1);
}

// Create user
echo "\n👤 Creating database user...\n";
try {
    // Check if user exists
    $stmt = $rootPdo->prepare("SELECT User FROM mysql.user WHERE User = ?");
    $stmt->execute([$config['database']['username']]);
    
    if ($stmt->rowCount() == 0) {
        // Create user
        $createUserSql = sprintf(
            "CREATE USER '%s'@'localhost' IDENTIFIED BY '%s'",
            $config['database']['username'],
            $config['database']['password']
        );
        $rootPdo->exec($createUserSql);
        echo "✅ User '{$config['database']['username']}' created\n";
    } else {
        echo "✅ User '{$config['database']['username']}' already exists\n";
    }
} catch (\PDOException $e) {
    echo "❌ Failed to create user: " . $e->getMessage() . "\n";
    exit(1);
}

// Grant privileges
echo "\n🔐 Granting privileges...\n";
try {
    $grantSql = sprintf(
        "GRANT ALL PRIVILEGES ON `%s`.* TO '%s'@'localhost'",
        $config['database']['name'],
        $config['database']['username']
    );
    $rootPdo->exec($grantSql);
    $rootPdo->exec("FLUSH PRIVILEGES");
    echo "✅ Privileges granted\n";
} catch (\PDOException $e) {
    echo "❌ Failed to grant privileges: " . $e->getMessage() . "\n";
    exit(1);
}

// Test the new user connection
echo "\n🧪 Testing new user connection...\n";
try {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $config['database']['host'],
        $config['database']['port'],
        $config['database']['name'],
        $config['database']['charset']
    );

    $pdo = new \PDO($dsn, $config['database']['username'], $config['database']['password'], [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
    ]);
    
    echo "✅ New user connection successful\n";
} catch (\PDOException $e) {
    echo "❌ New user connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n🎉 Database setup completed successfully!\n";
echo "\nNext steps:\n";
echo "1. Run: php setup.php\n";
echo "2. Run: php test.php\n";
echo "3. Run: php example.php\n"; 