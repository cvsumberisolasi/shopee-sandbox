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
        $dbPath = Env::require('DB_PATH');
        if (!str_starts_with($dbPath, '/')) {
            $dbPath = dirname(__DIR__, 2) . '/' . $dbPath;
        }
        $dir = dirname($dbPath);
        if (!is_dir($dir)) mkdir($dir, 0775, true);

        self::$pdo = new PDO('sqlite:' . $dbPath);
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        self::$pdo->exec('PRAGMA journal_mode=WAL');
        self::$pdo->exec('PRAGMA foreign_keys=ON');
        return self::$pdo;
    }

    public static function migrate(): void
    {
        $pdo = self::pdo();
        $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS shops (
            shop_id INTEGER PRIMARY KEY,
            name TEXT, region TEXT, status TEXT,
            auth_expire_at INTEGER,
            raw_json TEXT,
            synced_at INTEGER
        );
        CREATE TABLE IF NOT EXISTS tokens (
            shop_id INTEGER PRIMARY KEY,
            access_token TEXT NOT NULL,
            refresh_token TEXT NOT NULL,
            expire_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS products (
            item_id INTEGER PRIMARY KEY,
            shop_id INTEGER,
            name TEXT, sku TEXT, price INTEGER, stock INTEGER,
            status TEXT,
            raw_json TEXT,
            synced_at INTEGER
        );
        CREATE TABLE IF NOT EXISTS orders (
            order_sn TEXT PRIMARY KEY,
            shop_id INTEGER,
            status TEXT,
            total_amount INTEGER,
            currency TEXT,
            create_time INTEGER,
            raw_json TEXT,
            synced_at INTEGER
        );
        CREATE TABLE IF NOT EXISTS sync_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            resource TEXT,
            status TEXT,
            message TEXT,
            ran_at INTEGER
        );
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