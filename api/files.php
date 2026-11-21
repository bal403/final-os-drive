<?php
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

function perm_for_role(array $file, string $role): string {
    if ($role === 'admin') return $file['perm_admin'] ?? 'rwx';
    if ($role === 'user') return $file['perm_user'] ?? 'rw-';
    return $file['perm_guest'] ?? 'r--';
}

function can_read(array $file, string $role): bool {
    return ($role === 'admin') || (str_contains(perm_for_role($file, $role), 'r'));
}

function can_write(array $file, string $role): bool {
    return ($role === 'admin') || (str_contains(perm_for_role($file, $role), 'w'));
}

function can_exec(array $file, string $role): bool {
    return ($role === 'admin') || (str_contains(perm_for_role($file, $role), 'x'));
}

function allocate_blocks(array &$blocks, int $neededBlocks, string $type): array {
    $total = count($blocks);
    $freeIndices = [];
    for ($i = 0; $i < $total; $i++) {
        if ((int)$blocks[$i] === 0) $freeIndices[] = $i;
    }

    if ($type === 'indexed') {
        $reqTotal = $neededBlocks + 1;
        if (count($freeIndices) < $reqTotal) {
            return ['ok' => false, 'error' => 'Not enough free virtual disk blocks for Indexed Allocation (includes index block)'];
        }
        $indexBlock = array_shift($freeIndices);
        $dataBlocks = array_slice($freeIndices, 0, $neededBlocks);
        $blocks[$indexBlock] = 1;
        foreach ($dataBlocks as $b) {
            $blocks[$b] = 1;
        }
        return ['ok' => true, 'blocks' => $dataBlocks, 'index_block' => $indexBlock];
    }

    if ($type === 'contiguous') {
        $run = [];
        for ($i = 0; $i < $total; $i++) {
            if ((int)$blocks[$i] === 0) {
                $run[] = $i;
                if (count($run) === $neededBlocks) {
                    foreach ($run as $b) $blocks[$b] = 1;
                    return ['ok' => true, 'blocks' => $run, 'index_block' => null];
                }
            } else {
                $run = [];
            }
        }
        if (count($freeIndices) >= $neededBlocks) {
            return ['ok' => false, 'error' => 'External fragmentation: no contiguous block run available. Try Linked or Indexed allocation.'];
        }
        return ['ok' => false, 'error' => 'Storage full: not enough free blocks'];
    }

    if (count($freeIndices) < $neededBlocks) {
        return ['ok' => false, 'error' => 'Storage full: not enough free blocks for Linked Allocation'];
    }
    $chosen = array_slice($freeIndices, 0, $neededBlocks);
    foreach ($chosen as $b) {
        $blocks[$b] = 1;
    }
    return ['ok' => true, 'blocks' => $chosen, 'index_block' => null];
}

function free_file_blocks(array &$blocks, array $file): void {
    $total = count($blocks);
    $list = json_decode($file['allocated_blocks_json'] ?? '[]', true) ?: [];
    foreach ($list as $b) {
        $idx = (int)$b;
        if ($idx >= 0 && $idx < $total) $blocks[$idx] = 0;
    }
    if ($file['index_block'] !== null) {
        $idx = (int)$file['index_block'];
        if ($idx >= 0 && $idx < $total) $blocks[$idx] = 0;
    }
}

if ($action === 'create' || $action === 'upload') {
    if (current_role() !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only Admin can create or upload files']);
        exit;
    }

    $filename = trim($_POST['filename'] ?? '');
    if ($filename === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Filename is required']);
        exit;
    }

    $sizeKb = max(1, (int)($_POST['size_kb'] ?? 4));
    $content = $_POST['content'] ?? '';
    $allocType = $_POST['allocation_type'] ?? 'contiguous';
    if (!in_array($allocType, ['contiguous', 'linked', 'indexed'], true)) {
        $allocType = 'contiguous';
    }
    $dirId = max(1, (int)($_POST['parent_id'] ?? 1));

    $permAdmin = preg_replace('/[^rwx-]/', '-', str_pad(substr($_POST['perm_admin'] ?? 'rwx', 0, 3), 3, '-'));
    $permUser  = preg_replace('/[^rwx-]/', '-', str_pad(substr($_POST['perm_user'] ?? 'rw-', 0, 3), 3, '-'));
    $permGuest = preg_replace('/[^rwx-]/', '-', str_pad(substr($_POST['perm_guest'] ?? 'r--', 0, 3), 3, '-'));

    $disk = get_disk_state($pdo);
    $neededBlocks = max(1, (int)ceil($sizeKb / max(1, $disk['block_kb'])));
    $blocksCopy = $disk['blocks'];

    $alloc = allocate_blocks($blocksCopy, $neededBlocks, $allocType);
    if (!$alloc['ok']) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $alloc['error']]);
        exit;
    }

    save_bitmap($pdo, $blocksCopy);

    $ext = '';
    if (str_contains($filename, '.')) {
        $parts = explode('.', $filename);
        $ext = strtolower(end($parts));
    }

    $blocksJson = json_encode($alloc['blocks']);
    $indexBlock = $alloc['index_block'];
    $ownerRole = current_role();

    $stmt = $pdo->prepare("INSERT INTO files (dir_id, filename, extension, size_kb, content, allocation_type, allocated_blocks_json, index_block, perm_admin, perm_user, perm_guest, owner_role) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$dirId, $filename, $ext, $sizeKb, $content, $allocType, $blocksJson, $indexBlock, $permAdmin, $permUser, $permGuest, $ownerRole]);

    echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'blocks' => $alloc['blocks'], 'index_block' => $indexBlock]);
    exit;
}

if ($action === 'read') {
    $fileId = (int)($_GET['file_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ?");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch();

    if (!$file) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'File not found']);
        exit;
    }

    $role = current_role();
    if (!can_read($file, $role)) {
        echo json_encode([
            'ok' => false,
            'can_read' => false,
            'can_write' => false,
            'can_exec' => false,
            'error' => "Role '$role' lacks Read (r) permission",
        ]);
        exit;
    }

    $file['id'] = (int)$file['id'];
    $file['size_kb'] = (int)$file['size_kb'];
    $file['index_block'] = $file['index_block'] !== null ? (int)$file['index_block'] : null;
    $file['blocks'] = json_decode($file['allocated_blocks_json'] ?? '[]', true) ?: [];

    echo json_encode([
        'ok' => true,
        'can_read' => true,
        'can_write' => can_write($file, $role),
        'can_exec' => can_exec($file, $role),
        'file' => $file,
    ]);
    exit;
}

if ($action === 'edit') {
    $fileId = (int)($_POST['file_id'] ?? 0);
    $content = $_POST['content'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ?");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch();
    if (!$file) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'File not found']);
        exit;
    }

    $role = current_role();
    if (!can_write($file, $role)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => "Role '$role' lacks Write (w) permission"]);
        exit;
    }

    $upd = $pdo->prepare("UPDATE files SET content = ? WHERE id = ?");
    $upd->execute([$content, $fileId]);

    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'rename') {
    $fileId = (int)($_POST['file_id'] ?? 0);
    $filename = trim($_POST['filename'] ?? '');
    if ($filename === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Filename cannot be empty']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ?");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch();
    if (!$file || !can_write($file, current_role())) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Write permission required to rename file']);
        exit;
    }

    $parts = explode('.', $filename);
    $ext = str_contains($filename, '.') ? strtolower(end($parts)) : '';
    $upd = $pdo->prepare("UPDATE files SET filename = ?, extension = ? WHERE id = ?");
    $upd->execute([$filename, $ext, $fileId]);

    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'copy') {
    $fileId = (int)($_POST['file_id'] ?? 0);
    $targetDir = max(1, (int)($_POST['parent_id'] ?? 1));

    $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ?");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch();
    if (!$file || !can_write($file, current_role())) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Permission denied to copy file']);
        exit;
    }

    $disk = get_disk_state($pdo);
    $neededBlocks = max(1, (int)ceil((int)$file['size_kb'] / max(1, $disk['block_kb'])));
    $blocksCopy = $disk['blocks'];
    $alloc = allocate_blocks($blocksCopy, $neededBlocks, $file['allocation_type']);
    if (!$alloc['ok']) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $alloc['error']]);
        exit;
    }

    save_bitmap($pdo, $blocksCopy);
    $copyName = 'copy_' . $file['filename'];
    $blocksJson = json_encode($alloc['blocks']);

    $ins = $pdo->prepare("INSERT INTO files (dir_id, filename, extension, size_kb, content, allocation_type, allocated_blocks_json, index_block, perm_admin, perm_user, perm_guest, owner_role) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $ins->execute([
        $targetDir,
        $copyName,
        $file['extension'],
        $file['size_kb'],
        $file['content'],
        $file['allocation_type'],
        $blocksJson,
        $alloc['index_block'],
        $file['perm_admin'],
        $file['perm_user'],
        $file['perm_guest'],
        $file['owner_role'],
    ]);

    echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    exit;
}

if ($action === 'move') {
    $fileId = (int)($_POST['file_id'] ?? 0);
    $targetDir = max(1, (int)($_POST['parent_id'] ?? 1));

    $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ?");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch();
    if (!$file || !can_write($file, current_role())) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Write permission required to move file']);
        exit;
    }

    $upd = $pdo->prepare("UPDATE files SET dir_id = ? WHERE id = ?");
    $upd->execute([$targetDir, $fileId]);

    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'delete') {
    $fileId = (int)($_POST['file_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ?");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch();
    if (!$file || !can_write($file, current_role())) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Write permission required to move file to trash']);
        exit;
    }

    $upd = $pdo->prepare("UPDATE files SET is_trashed = 1 WHERE id = ?");
    $upd->execute([$fileId]);

    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'list_trash') {
    $files = [];
    $stmtF = $pdo->query("SELECT id, dir_id, filename, extension, size_kb, allocation_type, updated_at FROM files WHERE is_trashed = 1 ORDER BY updated_at DESC");
    while ($f = $stmtF->fetch()) {
        $f['id'] = (int)$f['id'];
        $f['size_kb'] = (int)$f['size_kb'];
        $files[] = $f;
    }

    $dirs = [];
    $stmtD = $pdo->query("SELECT id, name, parent_id, updated_at FROM directories WHERE is_trashed = 1 AND is_root = 0 ORDER BY updated_at DESC");
    while ($d = $stmtD->fetch()) {
        $d['id'] = (int)$d['id'];
        $dirs[] = $d;
    }

    echo json_encode(['ok' => true, 'files' => $files, 'directories' => $dirs]);
    exit;
}

if ($action === 'restore') {
    if (current_role() !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin only']);
        exit;
    }
    $fileId = (int)($_POST['file_id'] ?? 0);
    $upd = $pdo->prepare("UPDATE files SET is_trashed = 0 WHERE id = ?");
    $upd->execute([$fileId]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'delete_forever') {
    if (current_role() !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin only']);
        exit;
    }
    $fileId = (int)($_POST['file_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ?");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch();

    if ($file) {
        $disk = get_disk_state($pdo);
        $blocks = $disk['blocks'];
        free_file_blocks($blocks, $file);
        save_bitmap($pdo, $blocks);

        $del = $pdo->prepare("DELETE FROM files WHERE id = ?");
        $del->execute([$fileId]);
    }

    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'download') {
    $fileId = (int)($_GET['file_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ?");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch();

    if (!$file || !can_read($file, current_role())) {
        http_response_code(403);
        echo 'Access denied';
        exit;
    }

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($file['filename']) . '"');
    echo $file['content'] ?? '';
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unsupported file action']);