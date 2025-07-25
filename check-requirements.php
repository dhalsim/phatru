<?php

/**
 * Requirements check script for Phatru PHP Relay
 * Run this before installing dependencies
 */

echo "🎯 Phatru PHP Relay - Requirements Check\n";
echo "========================================\n\n";

$errors = [];
$warnings = [];

// Check PHP version
echo "📋 Checking PHP version...\n";
if (version_compare(PHP_VERSION, '8.1.0', '>=')) {
    echo "✅ PHP " . PHP_VERSION . " (8.1+ required)\n";
} else {
    echo "❌ PHP " . PHP_VERSION . " (8.1+ required)\n";
    $errors[] = "PHP 8.1 or higher is required";
}

// Check required extensions
echo "\n📦 Checking PHP extensions...\n";
$required_extensions = [
    'pdo' => 'Database connectivity',
    'pdo_mysql' => 'MySQL database support',
    'json' => 'JSON serialization',
    'openssl' => 'Cryptographic operations (recommended)'
];

foreach ($required_extensions as $ext => $description) {
    if (extension_loaded($ext)) {
        echo "✅ {$ext} - {$description}\n";
    } else {
        if ($ext === 'openssl') {
            echo "⚠️  {$ext} - {$description} (optional but recommended)\n";
            $warnings[] = "OpenSSL extension is recommended for production use";
        } else {
            echo "❌ {$ext} - {$description}\n";
            $errors[] = "Required extension '{$ext}' is not loaded";
        }
    }
}

// Check Composer
echo "\n🎼 Checking Composer...\n";
if (file_exists(__DIR__ . '/composer.json')) {
    echo "✅ composer.json found\n";
} else {
    echo "❌ composer.json not found\n";
    $errors[] = "composer.json file is missing";
}

// Check if vendor directory exists
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    echo "✅ Composer dependencies installed\n";
} else {
    echo "⚠️  Composer dependencies not installed\n";
    echo "   Run: composer install\n";
}

// Check MySQL connection (if configured)
echo "\n🗄️  Checking MySQL connection...\n";
$configFile = __DIR__ . '/config.php';
if (file_exists($configFile)) {
    $config = require $configFile;
    
    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['database']['host'],
            $config['database']['port'],
            $config['database']['name'],
            $config['database']['charset']
        );

        $pdo = new \PDO(
            $dsn,
            $config['database']['username'],
            $config['database']['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
        echo "✅ MySQL connection successful\n";
    } catch (PDOException $e) {
        echo "❌ MySQL connection failed: " . $e->getMessage() . "\n";
        $warnings[] = "MySQL connection failed - check your config.php";
    }
} else {
    echo "⚠️  config.php not found - copy config.example.php to config.php\n";
    $warnings[] = "Configuration file not found";
}

// Summary
echo "\n📊 Summary\n";
echo "==========\n";

if (empty($errors) && empty($warnings)) {
    echo "🎉 All requirements met! You can now run:\n";
    echo "   composer install\n";
    echo "   php setup.php\n";
    echo "   php example.php\n";
} else {
    if (!empty($errors)) {
        echo "\n❌ Errors (must be fixed):\n";
        foreach ($errors as $error) {
            echo "   - {$error}\n";
        }
    }
    
    if (!empty($warnings)) {
        echo "\n⚠️  Warnings (recommended to fix):\n";
        foreach ($warnings as $warning) {
            echo "   - {$warning}\n";
        }
    }
    
    echo "\n🔧 Next steps:\n";
    if (!empty($errors)) {
        echo "   1. Fix the errors above\n";
    }
    echo "   2. Run: composer install\n";
    echo "   3. Copy config.example.php to config.php and configure\n";
    echo "   4. Run: php setup.php\n";
}

echo "\n📚 For more information, see README.md\n"; 