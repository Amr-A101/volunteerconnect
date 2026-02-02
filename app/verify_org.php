<?php
// app/verify_org.php

session_start();
require_once __DIR__ . "/core/db.php";
require_once __DIR__ . "/core/flash.php";

// ----------------------------------
// ACCESS CONTROL
// ----------------------------------
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'org') {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch user status
$stmt = $dbc->prepare("SELECT status FROM users WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$userStatus = $user['status'] ?? null;

if (!$userStatus) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// If already verified → dashboard
if ($userStatus === 'verified') {
    header("Location: /volcon/app/dashboard_org.php");
    exit;
}

// Suspended users blocked
if ($userStatus === 'suspended') {
    flash('error', 'Your account has been suspended. Contact support at support@volunteerconnect.org.');
    header("Location: login.php");
    exit;
}

// ----------------------------------
// FETCH EXISTING ORG DATA
// ----------------------------------
$stmt = $dbc->prepare("
    SELECT name, document_paths
    FROM organizations
    WHERE org_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$org = $stmt->get_result()->fetch_assoc();

if (!$org) {
    flash('error', 'Organization profile not found.');
    header("Location: login.php");
    exit;
}

$existingDocs = [];
if (!empty($org['document_paths'])) {
    $decoded = json_decode($org['document_paths'], true);
    if (is_array($decoded)) {
        $existingDocs = $decoded;
    }
}

// ----------------------------------
// HANDLE DOCUMENT UPLOAD
// ----------------------------------
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (empty($_FILES['documents']) || empty($_FILES['documents']['name'][0])) {
        $errors[] = "Please upload at least one verification document.";
    }

    if (empty($errors)) {

        $uploadDir = __DIR__ . "/../assets/uploads/org_docs";
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $allowedExt = ['pdf', 'jpg', 'jpeg', 'png'];
        $maxSize = 5 * 1024 * 1024; // 5MB per file

        foreach ($_FILES['documents']['tmp_name'] as $i => $tmp) {

            if ($_FILES['documents']['error'][$i] !== UPLOAD_ERR_OK) {
                continue;
            }

            if ($_FILES['documents']['size'][$i] > $maxSize) {
                $errors[] = "One of the files exceeds 5MB limit.";
                continue;
            }

            $ext = strtolower(pathinfo($_FILES['documents']['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt)) {
                $errors[] = "Only PDF, JPG, JPEG, PNG files are allowed.";
                continue;
            }

            $filename = "org_" . $user_id . "_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $ext;
            $dest = $uploadDir . "/" . $filename;

            if (move_uploaded_file($tmp, $dest)) {
                $existingDocs[] = [
                    'file' => "/volcon/assets/uploads/org_docs/" . $filename,
                    'original_name' => $_FILES['documents']['name'][$i],
                    'uploaded_at' => date('Y-m-d H:i:s')
                ];
            }
        }
    }

    // Save to DB if we have documents
    if (empty($errors) && !empty($existingDocs)) {

        $json = json_encode($existingDocs, JSON_UNESCAPED_SLASHES);

        $stmt = $dbc->prepare("
            UPDATE organizations
            SET document_paths = ?
            WHERE org_id = ?
        ");
        $stmt->bind_param("si", $json, $user_id);
        $stmt->execute();

        flash('success', 'Documents submitted successfully. Awaiting admin verification.');
        header("Location: verify_org.php");
        exit;
    }
}

// ----------------------------------
// HANDLE DOCUMENT DELETE
// ----------------------------------
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['delete_doc']) &&
    isset($_POST['doc_index'])
) {
    $idx = (int) $_POST['doc_index'];

    if (isset($existingDocs[$idx])) {

        // Delete physical file
        $filePath = __DIR__ . "/.." . $existingDocs[$idx]['file'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        // Remove from array
        array_splice($existingDocs, $idx, 1);

        // Prevent zero-doc state
        if (count($existingDocs) === 0) {
            $errors[] = "At least one verification document is required.";
        } else {
            $json = json_encode($existingDocs, JSON_UNESCAPED_SLASHES);

            $stmt = $dbc->prepare("
                UPDATE organizations
                SET document_paths = ?
                WHERE org_id = ?
            ");
            $stmt->bind_param("si", $json, $user_id);
            $stmt->execute();

            flash('success', 'Document removed successfully.');
            header("Location: verify_org.php");
            exit;
        }
    }
}


?>

<link rel="stylesheet" href="/volcon/assets/css/layout/body_base.css">

<style>
body {
    min-height: 100vh;
    background: linear-gradient(
        120deg,
        #0f172a,
        #1e3a8a,
        #2563eb,
        #38bdf8
    );
    background-size: 300% 300%;
    animation: vcGradient 12s ease infinite;
}

@keyframes vcGradient {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}

/* ---------- Layout ---------- */
.vc-ver-container {
    background: #ffffff;
    border-radius: 10px;
    padding: 28px;
    box-shadow: 0 10px 28px rgba(0,0,0,.08);
    margin: auto;
    max-width: 700px;
}

/* ---------- Headings ---------- */
.vc-ver-container h2 {
    margin-top: 0;
    margin-bottom: 12px;
    font-size: 24px;
    color: #1f2937;
}

.vc-ver-container p {
    color: #4b5563;
    line-height: 1.6;
}

/* ---------- Alerts ---------- */
.vc-alert {
    padding: 14px 16px;
    border-radius: 6px;
    margin-bottom: 18px;
    font-size: 14px;
}

.vc-alert-info {
    background: #eff6ff;
    border-left: 4px solid #3b82f6;
    color: #1e40af;
}

.vc-alert-danger {
    background: #fef2f2;
    border-left: 4px solid #dc2626;
    color: #7f1d1d;
}

.vc-alert ul {
    padding-left: 18px;
    margin: 8px 0 0;
}

/* ---------- Document List ---------- */
.vc-alert-info ul li {
    margin-bottom: 6px;
}

.vc-alert-info a {
    color: #1d4ed8;
    text-decoration: none;
    font-weight: 500;
}

.vc-alert-info a:hover {
    text-decoration: underline;
}

.vc-alert-info small {
    color: #6b7280;
}

/* ---------- Form ---------- */
.vc-form-group {
    margin-bottom: 20px;
}

.vc-form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 6px;
    color: #374151;
}

.vc-form-group input[type="file"] {
    width: 100%;
    padding: 10px;
    border: 1px dashed #cbd5e1;
    border-radius: 6px;
    background: #f8fafc;
    cursor: pointer;
}

/* ---------- Button ---------- */
.vc-btn {
    display: inline-block;
    padding: 12px 22px;
    border-radius: 6px;
    border: none;
    font-weight: 600;
    cursor: pointer;
    transition: all .2s ease;
}

.vc-btn-primary {
    background: #2563eb;
    color: #ffffff;
}

.vc-btn-primary:hover {
    background: #1e40af;
}

/* ---------- Footer Text ---------- */
.vc-ver-container hr {
    border: none;
    border-top: 1px solid #e5e7eb;
    margin: 26px 0;
}

.vc-ver-container p.note {
    font-size: 13px;
    color: #6b7280;
}

/* ---------- Pending Badge ---------- */
.vc-status-badge {
    display: inline-block;
    background: #fef3c7;
    color: #92400e;
    padding: 6px 10px;
    font-size: 12px;
    border-radius: 999px;
    font-weight: 600;
    margin-bottom: 14px;
}

/* Verification Documents */
.vc-docs {
    margin-top: 20px;
}

.vc-docs-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 14px;
    margin-top: 12px;
}

.vc-doc-card {
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 14px;
    background: #fff;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}

.vc-doc-title {
    font-size: 14px;
    font-weight: 600;
    color: #111827;
    margin-bottom: 6px;
    word-break: break-word;
}

.vc-doc-meta {
    font-size: 12px;
    color: #6b7280;
    margin-bottom: 12px;
}

.vc-doc-actions {
    display: flex;
    gap: 8px;
}

.vc-doc-actions a,
.vc-doc-actions button {
    font-size: 12px;
    padding: 6px 10px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
}

.vc-doc-view {
    background: #eef2ff;
    color: #3730a3;
    text-decoration: none;
    text-align: center;
}

.vc-doc-delete {
    background: #fee2e2;
    color: #991b1b;
}

.vc-doc-status {
    margin-top: 32px;
    font-size: 14px;
}

.vc-ver-container {
    backdrop-filter: blur(12px);
    background: rgba(255, 255, 255, 0.95);
}

/* ---------- Top Action Bar ---------- */
.vc-ver-topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 18px;
}

.vc-logout-btn {
    background: #ef4444;
    color: #fff;
    padding: 8px 14px;
    border-radius: 6px;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
}

.vc-logout-btn:hover {
    background: #b91c1c;
}

</style>


<div class="vc-ver-container">
    <div class="vc-ver-topbar">
        <h2>Organization Verification</h2>

        <a href="/volcon/app/logout.php"
        class="vc-logout-btn"
        onclick="return confirm('Are you sure you want to log out?');">
            Logout
        </a>
    </div>


    <?php
    $statusLabel = match ($userStatus) {
        'pending'   => 'Pending Admin Review',
        'verified'  => 'Verified',
        'suspended' => 'Suspended',
        default     => 'Unknown'
    };
    ?>

    <span class="vc-status-badge">
        <?= htmlspecialchars($statusLabel) ?>
    </span>

    <p>
        Hello <strong><?= htmlspecialchars($org['name']) ?></strong>,<br>
        To activate your organization account, please upload at least one
        official document for admin verification.
    </p>

    <?php if (!empty($existingDocs)): ?>
        <div class="vc-docs">
            <h4>Uploaded Verification Documents</h4>

            <div class="vc-docs-grid">
                <?php foreach ($existingDocs as $i => $doc): ?>
                    <div class="vc-doc-card">
                        <div>
                            <div class="vc-doc-title">
                                <?= htmlspecialchars($doc['original_name']) ?>
                            </div>
                            <div class="vc-doc-meta">
                                Uploaded on <?= htmlspecialchars($doc['uploaded_at']) ?>
                            </div>
                        </div>

                        <div class="vc-doc-actions">
                            <a href="<?= htmlspecialchars($doc['file']) ?>"
                            target="_blank"
                            class="vc-doc-view">
                                View
                            </a>

                            <form method="post" style="margin:0;">
                                <input type="hidden" name="doc_index" value="<?= $i ?>">
                                <button type="submit"
                                        name="delete_doc"
                                        class="vc-doc-delete"
                                        onclick="return confirm('Are you sure you want to delete this document?');">
                                    Remove
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="vc-doc-status">
                Status: <strong><?= htmlspecialchars($statusLabel) ?></strong>
            </div>
        </div>
    <?php endif; ?>



    <?php if (!empty($errors)): ?>
        <div class="vc-alert vc-alert-danger">
            <ul>
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <div class="vc-form-group">
            <label>
                <?= empty($existingDocs)
                    ? 'Upload verification documents (PDF / JPG / PNG)'
                    : 'Upload additional documents (PDF / JPG / PNG)'
                ?>
            </label>
            <input type="file" name="documents[]" multiple required>
        </div>

        <button type="submit" class="vc-btn vc-btn-primary">
            <?= empty($existingDocs)
                ? 'Submit for Verification'
                : 'Add Files'
            ?>
        </button>
    </form>

    <hr>

    <p style="font-size: 14px; color: #666;">
        Once submitted, documents will be reviewed by an administrator.
        You will gain full access after approval.
    </p>
</div>
