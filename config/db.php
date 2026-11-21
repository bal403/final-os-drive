<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('DB_PATH', __DIR__ . '/../data/filesystem.sqlite');

if (!is_dir(dirname(DB_PATH))) {
    mkdir(dirname(DB_PATH), 0777, true);
}

$pdo = new PDO('sqlite:' . DB_PATH);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');

$pdo->exec("
CREATE TABLE IF NOT EXISTS disk_config (
    id INTEGER PRIMARY KEY CHECK(id = 1),
    total_blocks INTEGER NOT NULL DEFAULT 64,
    block_size_kb INTEGER NOT NULL DEFAULT 4,
    bitmap_json TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS directories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    parent_id INTEGER NULL,
    is_root INTEGER NOT NULL DEFAULT 0,
    is_public INTEGER NOT NULL DEFAULT 0,
    is_trashed INTEGER NOT NULL DEFAULT 0,
    owner_role TEXT NOT NULL DEFAULT 'admin',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    dir_id INTEGER NOT NULL DEFAULT 1,
    filename TEXT NOT NULL,
    extension TEXT NOT NULL DEFAULT '',
    size_kb INTEGER NOT NULL DEFAULT 4,
    content TEXT,
    allocation_type TEXT NOT NULL CHECK(allocation_type IN ('contiguous','linked','indexed')) DEFAULT 'contiguous',
    allocated_blocks_json TEXT NOT NULL,
    index_block INTEGER NULL,
    perm_admin TEXT NOT NULL DEFAULT 'rwx',
    perm_user TEXT NOT NULL DEFAULT 'rw-',
    perm_guest TEXT NOT NULL DEFAULT 'r--',
    is_public INTEGER NOT NULL DEFAULT 0,
    is_trashed INTEGER NOT NULL DEFAULT 0,
    owner_role TEXT NOT NULL DEFAULT 'admin',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS shares (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_type TEXT NOT NULL CHECK(resource_type IN ('file','directory')),
    resource_id INTEGER NOT NULL,
    target_user TEXT NOT NULL,
    shared_with_role TEXT NOT NULL DEFAULT 'user',
    permission TEXT NOT NULL DEFAULT 'r--',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
");

$stmtCfg = $pdo->query("SELECT id FROM disk_config WHERE id = 1");
if (!$stmtCfg->fetch()) {
    $emptyBitmap = json_encode(array_fill(0, 64, 0));
    $insCfg = $pdo->prepare("INSERT INTO disk_config (id, total_blocks, block_size_kb, bitmap_json) VALUES (1, 64, 4, ?)");
    $insCfg->execute([$emptyBitmap]);
}

$stmtRoot = $pdo->query("SELECT id FROM directories WHERE id = 1");
if (!$stmtRoot->fetch()) {
    $pdo->exec("INSERT INTO directories (id, name, parent_id, is_root, is_public, is_trashed, owner_role) VALUES (1, 'Root', NULL, 1, 0, 0, 'admin')");
    $pdo->exec("INSERT INTO directories (name, parent_id, is_root, is_public, is_trashed, owner_role) VALUES
        ('Documents', 1, 0, 0, 0, 'admin'),
        ('Projects', 1, 0, 0, 0, 'admin'),
        ('System', 1, 0, 0, 0, 'admin'),
        ('Public_Share', 1, 0, 1, 0, 'admin')");
}

if (!isset($_SESSION['role'])) {
    $_SESSION['role'] = 'admin';
}

function current_role(): string {
    $r = $_SESSION['role'] ?? 'admin';
    return in_array($r, ['admin', 'user', 'guest'], true) ? $r : 'admin';
}

function get_disk_state(PDO $pdo): array {
    $row = $pdo->query("SELECT total_blocks, block_size_kb, bitmap_json FROM disk_config WHERE id = 1")->fetch();
    $total = (int)($row['total_blocks'] ?? 64);
    $blockKb = (int)($row['block_size_kb'] ?? 4);
    $blocks = json_decode($row['bitmap_json'] ?? '[]', true);
    if (!is_array($blocks) || count($blocks) !== $total) {
        $blocks = array_fill(0, $total, 0);
    }
    return [
        'total' => $total,
        'block_kb' => $blockKb,
        'blocks' => array_map('intval', $blocks),
    ];
}

function save_bitmap(PDO $pdo, array $blocks): void {
    $json = json_encode(array_values(array_map('intval', $blocks)));
    $stmt = $pdo->prepare("UPDATE disk_config SET bitmap_json = ? WHERE id = 1");
    $stmt->execute([$json]);
}