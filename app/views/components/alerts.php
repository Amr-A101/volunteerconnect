<?php
// app/views/components/alerts.php
// Expected variables (set before include):
// $alert_msg    = string message
// $alert_type   = 'success' | 'error' | 'info'   (default: 'info')
// $alert_timeout = integer seconds to auto-dismiss (0 => never auto-dismiss) (default: 5)

$alert_msg = $alert_msg ?? '';
$alert_type = $alert_type ?? 'info';
$alert_timeout = isset($alert_timeout) ? (int)$alert_timeout : 5;

if (!empty($alert_msg)):
    // ensure safe attributes
    $safe_msg = htmlspecialchars($alert_msg, ENT_QUOTES, 'UTF-8');
    $data_timeout = $alert_timeout;
?>
<div class="vc-alert vc-alert-<?= htmlspecialchars($alert_type, ENT_QUOTES, 'UTF-8') ?>"
     role="alert"
     data-timeout="<?= $data_timeout ?>">
    <div class="vc-alert__icon" aria-hidden="true">
        <?php if ($alert_type === 'success'): ?>
            ✓
        <?php elseif ($alert_type === 'error'): ?>
            ✖
        <?php else: ?>
            ℹ
        <?php endif; ?>
    </div>

    <div class="vc-alert__text"><?= $safe_msg ?></div>

    <button type="button" class="vc-alert__close" aria-label="Close notification">&times;</button>
</div>
<?php
endif;
?>
