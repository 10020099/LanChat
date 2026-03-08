<?php
// User related helpers

function getCurrentUser(mysqli $mysqli, int $userId): ?array
{
    $stmt = $mysqli->prepare("SELECT id, username, avatar FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc() ?: null;
    $stmt->close();
    return $user;
}

function ensureAvatar(?string $avatar): string
{
    // 默认头像：使用项目内静态资源，避免 data URL 过长导致写入数据库失败（avatar 列通常是 VARCHAR(255)）。
    $default = 'assets/images/default_avatar.svg';

    if (!empty($avatar)) {
        $normalized = trim((string)$avatar);
        if (
            $normalized !== '' &&
            stripos($normalized, 'default_avatar.png') === false &&
            stripos($normalized, 'default_avatar.svg') === false &&
            stripos($normalized, 'data:image/') !== 0
        ) {
            return $normalized;
        }
    }

    return $default;
}

function updateLastSeen(int $user_id, ?mysqli $mysqli = null): bool
{
    global $config;

    $user_id = intval($user_id);
    if ($user_id <= 0) {
        return false;
    }

    $interval = max(5, intval($config['presence_update_interval'] ?? 60));
    $now = time();
    $lastUpdatedAt = intval($_SESSION['last_seen_updated_at'] ?? 0);
    if ($lastUpdatedAt > 0 && ($now - $lastUpdatedAt) < $interval) {
        return true;
    }

    $shouldClose = !($mysqli instanceof mysqli);
    if ($shouldClose) {
        $mysqli = get_db_connection();
    }

    $stmt = $mysqli->prepare("UPDATE users SET last_seen = NOW() WHERE id = ?");
    if (!$stmt) {
        if ($shouldClose) {
            $mysqli->close();
        }
        return false;
    }

    $stmt->bind_param("i", $user_id);
    $result = $stmt->execute();
    $stmt->close();

    if ($shouldClose) {
        $mysqli->close();
    }

    if ($result) {
        $_SESSION['last_seen_updated_at'] = $now;
    }

    return $result;
}

function getUserList(): array
{
    $mysqli = get_db_connection();
    $stmt = $mysqli->prepare("SELECT id, username, avatar, last_seen FROM users WHERE id != ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $row['avatar'] = ensureAvatar($row['avatar'] ?? null);
        $users[] = $row;
    }
    $stmt->close();
    $mysqli->close();
    return $users;
}

function getUserPresenceMap(?mysqli $mysqli = null, ?int $excludeUserId = null): array
{
    $shouldClose = !($mysqli instanceof mysqli);
    if ($shouldClose) {
        $mysqli = get_db_connection();
    }

    $query = 'SELECT id, UNIX_TIMESTAMP(last_seen) AS last_seen_ts FROM users';
    if ($excludeUserId !== null && $excludeUserId > 0) {
        $query .= ' WHERE id != ?';
    }

    $stmt = $mysqli->prepare($query);
    if (!$stmt) {
        if ($shouldClose) {
            $mysqli->close();
        }
        return [];
    }

    if ($excludeUserId !== null && $excludeUserId > 0) {
        $stmt->bind_param('i', $excludeUserId);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $presenceMap = [];
    while ($row = $result->fetch_assoc()) {
        $presenceMap[(string)intval($row['id'])] = isset($row['last_seen_ts']) ? (int)$row['last_seen_ts'] : null;
    }
    $stmt->close();

    if ($shouldClose) {
        $mysqli->close();
    }

    return $presenceMap;
}
