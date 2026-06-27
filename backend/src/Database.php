<?php
declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo) return self::$pdo;

        Env::load(dirname(__DIR__, 2));

        $host = Env::get('DB_HOST', 'localhost');
        $port = Env::get('DB_PORT', '3306');
        $name = Env::require('DB_NAME');
        $user = Env::require('DB_USER');
        $pass = Env::get('DB_PASS', '');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return self::$pdo;
    }

    public static function migrate(): void
    {
        $pdo = self::pdo();
        $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS shops (
            shop_id BIGINT PRIMARY KEY,
            name VARCHAR(255), region VARCHAR(10), status VARCHAR(50),
            auth_expire_at INT,
            raw_json LONGTEXT,
            synced_at INT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        SQL);

        $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS tokens (
            shop_id BIGINT PRIMARY KEY,
            access_token TEXT NOT NULL,
            refresh_token TEXT NOT NULL,
            expire_at INT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        SQL);

        $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS products (
            item_id BIGINT PRIMARY KEY,
            shop_id BIGINT,
            name VARCHAR(500), sku VARCHAR(100), price BIGINT, stock INT,
            status VARCHAR(50),
            raw_json LONGTEXT,
            synced_at INT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        SQL);

        $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS orders (
            order_sn VARCHAR(50) PRIMARY KEY,
            shop_id BIGINT,
            status VARCHAR(50),
            total_amount BIGINT,
            currency VARCHAR(10),
            create_time INT,
            raw_json LONGTEXT,
            synced_at INT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        SQL);

        $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS sync_log (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            resource VARCHAR(50),
            status VARCHAR(20),
            message TEXT,
            ran_at INT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        SQL);
    }

    public static function log(string $resource, string $status, string $message): void
    {
        $stmt = self::pdo()->prepare(
            'INSERT INTO sync_log (resource, status, message, ran_at) VALUES (?,?,?,?)'
        );
        $stmt->execute([$resource, $status, $message, time()]);
    }
}
