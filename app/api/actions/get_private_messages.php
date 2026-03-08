<?php
updateLastSeen($_SESSION['user_id']);

$receiver_id = intval($_POST['receiver_id'] ?? 0);
if ($receiver_id <= 0) {
    echo json_encode(['success' => false, 'message' => '请选择接收者']);
    exit;
}

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

    $params = [intval($_SESSION['user_id']), $receiver_id, $receiver_id, intval($_SESSION['user_id'])];
    $types = 'iiii';
    $where = 'WHERE ((pm.sender_id = ? AND pm.receiver_id = ?) OR (pm.sender_id = ? AND pm.receiver_id = ?)) AND pm.recalled = FALSE';

    if ($before_id > 0) {
        $where .= ' AND pm.id < ?';
        $params[] = $before_id;
        $types .= 'i';
    }

    $query = "
        SELECT
            pm.id,
            pm.sender_id,
            pm.receiver_id,
            pm.message,
            pm.reply_to_id,
            UNIX_TIMESTAMP(pm.timestamp) AS timestamp,
            pm.recalled,
            u1.username AS sender_username,
            u1.avatar AS sender_avatar,
            u2.username AS receiver_username
        FROM private_messages pm
        JOIN users u1 ON pm.sender_id = u1.id
        JOIN users u2 ON pm.receiver_id = u2.id
        $where
        ORDER BY pm.id DESC
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
        $replyStmt = $mysqli->prepare("
            SELECT pm.id, pm.message, u1.username AS sender_username, u1.avatar AS sender_avatar
            FROM private_messages pm
            JOIN users u1 ON pm.sender_id = u1.id
            WHERE pm.id IN ($placeholders)
        ");
        if ($replyStmt) {
            $replyStmt->bind_param(str_repeat('i', count($ids)), ...$ids);
            $replyStmt->execute();
            $replyResult = $replyStmt->get_result();
            while ($reply = $replyResult->fetch_assoc()) {
                $replyMap[(int)$reply['id']] = [
                    'message' => $parseMessage($reply['message']),
                    'username' => $reply['sender_username'],
                    'avatar' => $reply['sender_avatar'],
                ];
            }
            $replyStmt->close();
        }
    }

    $messages = [];
    foreach ($rows as $row) {
        $replyId = !empty($row['reply_to_id']) ? (int)$row['reply_to_id'] : 0;
        $row['message'] = $parseMessage($row['message']);
        $row['reply_to'] = $replyId > 0 ? ($replyMap[$replyId] ?? null) : null;
        $row['timestamp'] = (int)$row['timestamp'];
        $messages[] = $row;
    }

    $mysqli->close();

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
    echo json_encode(['success' => false, 'message' => '获取私聊消息失败: ' . $e->getMessage()]);
}
