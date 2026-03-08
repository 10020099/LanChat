<?php
updateLastSeen($_SESSION['user_id']);

$limit = intval($_POST['limit'] ?? 50);
$before_id = intval($_POST['before_id'] ?? 0);
$historyPageMax = max(1, intval($config['history_page_max'] ?? 200));
$limit = max(1, min($limit, $historyPageMax));

$parseMessage = static function ($message) use ($parsedown) {
    if (!is_string($message)) {
        return $message;
    }

    $trimmed = trim($message);
    if (strpos($trimmed, '{') === 0) {
        $decoded = json_decode($message, true);
        if ($decoded && isset($decoded['type']) && $decoded['type'] === 'file') {
            return $message;
        }
    }

    return customParseAllowHtml($message, $parsedown);
};

try {
    $mysqli = get_db_connection();

    $where = 'WHERE recalled = FALSE';
    $params = [];
    $types = '';

    if ($before_id > 0) {
        $where .= ' AND id < ?';
        $params[] = $before_id;
        $types .= 'i';
    }

    $query = "
        SELECT
            id,
            user_id,
            username,
            avatar,
            message,
            reply_to_id,
            UNIX_TIMESTAMP(timestamp) AS timestamp,
            ip,
            recalled
        FROM public_messages
        $where
        ORDER BY id DESC
        LIMIT ?
    ";

    $params[] = $limit + 1;
    $types .= 'i';

    $stmt = $mysqli->prepare($query);
    if (!$stmt) {
        throw new Exception('查询失败');
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    $replyIds = [];
    $hasMore = false;

    while ($row = $result->fetch_assoc()) {
        if (count($rows) >= $limit) {
            $hasMore = true;
            break;
        }

        $rows[] = $row;
        if (!empty($row['reply_to_id'])) {
            $replyIds[(int)$row['reply_to_id']] = true;
        }
    }
    $stmt->close();

    $replyMap = [];
    if (!empty($replyIds)) {
        $ids = array_values(array_map('intval', array_keys($replyIds)));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $replyStmt = $mysqli->prepare("SELECT id, username, avatar, message FROM public_messages WHERE id IN ($placeholders)");
        if ($replyStmt) {
            $replyStmt->bind_param(str_repeat('i', count($ids)), ...$ids);
            $replyStmt->execute();
            $replyResult = $replyStmt->get_result();
            while ($reply = $replyResult->fetch_assoc()) {
                $replyMap[(int)$reply['id']] = [
                    'message' => $parseMessage($reply['message']),
                    'username' => $reply['username'],
                    'avatar' => $reply['avatar'],
                ];
            }
            $replyStmt->close();
        }
    }

    $messages = [];
    foreach ($rows as $row) {
        $replyId = !empty($row['reply_to_id']) ? (int)$row['reply_to_id'] : 0;
        $messages[] = [
            'id' => (int)$row['id'],
            'user_id' => (int)$row['user_id'],
            'username' => $row['username'],
            'avatar' => $row['avatar'],
            'message' => $parseMessage($row['message']),
            'reply_to' => $replyId > 0 ? ($replyMap[$replyId] ?? null) : null,
            'timestamp' => (int)$row['timestamp'],
            'ip' => $row['ip'],
            'recalled' => (bool)$row['recalled'],
        ];
    }

    $mysqli->close();

    markPublicMessagesAsRead($_SESSION['user_id']);

    echo json_encode([
        'success' => true,
        'messages' => $messages,
        'hasMore' => $hasMore,
        'count' => count($messages),
    ]);
} catch (Throwable $e) {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $mysqli->close();
    }
    echo json_encode(['success' => false, 'message' => '获取消息失败: ' . $e->getMessage()]);
}
