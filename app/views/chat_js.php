<?php
$chatConfig = [
    'userId' => $_SESSION['user_id'] ?? 0,
    'csrfToken' => $_SESSION['csrf_token'] ?? '',
    'initialSettings' => $initialSettings ?? [],
    'presenceSyncIntervalMs' => intval($config['presence_sync_interval_ms'] ?? 30000),
];
$jsFiles = [
    'assets/js/chat/bootstrap.js',
    'assets/js/chat/utils.js',
    'assets/js/chat/ui.js',
    'assets/js/chat/attachments.js',
    'assets/js/chat/messages.js',
    'assets/js/chat/settings.js',
    'assets/js/chat/init.js',
];
?>
<script>
window.VENCHAT_CONFIG = <?php echo json_encode($chatConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<?php foreach ($jsFiles as $jsPath): ?>
<?php $fullPath = __DIR__ . '/../../' . $jsPath; ?>
<?php if (is_readable($fullPath)): ?>
<script src="<?php echo htmlspecialchars($jsPath . '?v=' . filemtime($fullPath), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<?php endif; ?>
<?php endforeach; ?>
