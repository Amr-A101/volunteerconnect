<?php
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/flash.php';
require_once __DIR__ . '/api/notify.php';


require_role('org'); // stops non-org users early

$user = current_user();
$org_id = (int)$user['user_id'];


/* --------------------
   Input
---------------------*/
$opportunity_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = $_GET['action'] ?? '';
$allowed_actions = ['publish','close','reopen','cancel','delete','complete','hard_delete'];

if ($opportunity_id <= 0 || !in_array($action, $allowed_actions, true)) {
    flash('error', 'Invalid request.');
    header("Location: dashboard_org.php");
    exit;
}

function getVolunteerIdsByApplicationStatus(
    mysqli $dbc,
    int $opportunity_id,
    array $statuses
): array {
    if (empty($statuses)) return [];

    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    $types = 'i' . str_repeat('s', count($statuses));

    $stmt = $dbc->prepare("
        SELECT DISTINCT volunteer_id
        FROM applications
        WHERE opportunity_id = ?
          AND status IN ($placeholders)
    ");

    $stmt->bind_param($types, $opportunity_id, ...$statuses);
    $stmt->execute();

    $ids = array_column(
        $stmt->get_result()->fetch_all(MYSQLI_ASSOC),
        'volunteer_id'
    );

    $stmt->close();
    return $ids;
}

function getParticipantVolunteerIds(mysqli $dbc, int $opportunity_id): array {
    $stmt = $dbc->prepare("
        SELECT DISTINCT volunteer_id
        FROM participation
        WHERE opportunity_id = ?
          AND status IN ('attended', 'incomplete')
    ");
    $stmt->bind_param("i", $opportunity_id);
    $stmt->execute();

    $ids = array_column(
        $stmt->get_result()->fetch_all(MYSQLI_ASSOC),
        'volunteer_id'
    );

    $stmt->close();
    return $ids;
}



/* --------------------
   Fetch & lock the opportunity row to avoid races
---------------------*/
$stmt = $dbc->prepare("
    SELECT o.* 
    FROM opportunities o
    WHERE o.opportunity_id = ? AND o.org_id = ?
    FOR UPDATE
");
$stmt->bind_param("ii", $opportunity_id, $org_id);
$stmt->execute();
$opp = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$opp) {
    flash('error', 'Opportunity not found or you are not the owner.');
    header("Location: dashboard_org.php");
    exit;
}

/* --------------------
   Basic stats (up-to-date)
---------------------*/
$stmt = $dbc->prepare("
    SELECT 
        COUNT(*) AS total_apps,
        SUM(status = 'accepted') AS accepted_count
    FROM applications
    WHERE opportunity_id = ?
    FOR UPDATE
");
$stmt->bind_param("i", $opportunity_id);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$total_apps = (int) ($stats['total_apps'] ?? 0);
$accepted = (int) ($stats['accepted_count'] ?? 0);

/* --------------------
   Blocked quick check
---------------------*/
if (in_array($opp['status'], ['deleted','suspended'])) {
    flash('error', 'This opportunity cannot be modified.');
    header("Location: dashboard_org.php");
    exit;
}

/* --------------------
   Helper: delete files referenced in opportunity_images (careful)
---------------------*/
function deleteOpportunityFiles(mysqli $dbc, int $opportunity_id, int $org_id) : void {
    // collect image paths from db (these are URL paths in your setup)
    $stmt = $dbc->prepare("SELECT image_url FROM opportunity_images WHERE opportunity_id = ?");
    $stmt->bind_param("i", $opportunity_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($res as $r) {
        $path = $r['image_url'];
        // Convert URL path to filesystem path if it's under your project
        // adapt this mapping to your environment
        $baseUrl = '/volcon'; // change if different
        if (strpos($path, $baseUrl) === 0) {
            $file = __DIR__ . '/../' . ltrim(substr($path, strlen($baseUrl)), '/');
            if (is_file($file)) @unlink($file);
        }
    }

    // remove records (called from within transaction)
    $stmt = $dbc->prepare("DELETE FROM opportunity_images WHERE opportunity_id = ?");
    $stmt->bind_param("i", $opportunity_id);
    $stmt->execute();
    $stmt->close();

    // remove other child records if you want to hard-delete
    $dbc->query("DELETE FROM opportunity_skills WHERE opportunity_id = $opportunity_id");
    $dbc->query("DELETE FROM opportunity_interests WHERE opportunity_id = $opportunity_id");
    $dbc->query("DELETE FROM opportunity_contacts WHERE opportunity_id = $opportunity_id");
}

/* --------------------
   Transition engine
---------------------*/
$dbc->begin_transaction();
try {
    $current = $opp['status'];
    $new_status = null;
    $notify_payload = null;
    $notify_user_ids = [];

    switch ($action) {
        case 'publish':
            if ($current !== 'draft') {
                throw new Exception('Only draft opportunities can be published.');
            }
            $new_status = 'open';
            // optional: set published_at or application_deadline default
            break;

        case 'close':
            if ($current !== 'open') {
                throw new Exception('Only open opportunities can be closed.');
            }
            $new_status = 'closed';
            // set closed_at to now
            $stmt = $dbc->prepare("UPDATE opportunities SET status = 'closed', closed_at = NOW(), updated_at = NOW() WHERE opportunity_id = ? AND org_id = ?");
            $stmt->bind_param("ii", $opportunity_id, $org_id);
            $stmt->execute();
            $stmt->close();

            // Reject pending/shortlisted applications (cascade)
            $stmt = $dbc->prepare("
                UPDATE applications
                SET status = 'rejected',
                    response_at = NOW()
                WHERE opportunity_id = ?
                AND status IN ('pending','shortlisted')
            ");
            $stmt->bind_param("i", $opportunity_id);
            $stmt->execute();
            $stmt->close();

            $notify_user_ids = getVolunteerIdsByApplicationStatus(
                $dbc,
                $opportunity_id,
                ['pending', 'shortlisted']
            );

            $notify_payload = [
                'title' => 'Opportunity Closed',
                'message' => "This opportunity has been closed by the organization.",
                'type' => 'warning',
                'action_url' => "/volcon/app/view_opportunity.php?id={$opportunity_id}",
                'context_type' => 'opportunity',
                'context_id' => $opportunity_id
            ];

            // commit early to skip the generic update below
            $dbc->commit();

            if (!empty($notify_payload) && !empty($notify_user_ids)) {
                notifyUsers($notify_user_ids, $notify_payload);
            }

            flash('success', 'Opportunity closed and pending applications rejected.');
            header("Location: dashboard_org.php");
            exit;

        case 'reopen':
            if ($current !== 'closed') {
                throw new Exception('Only closed opportunities can be reopened.');
            }
            $new_status = 'open';
            // clear closed_at and application_deadline (as requested)
            $stmt = $dbc->prepare("
                UPDATE opportunities
                SET status = 'open', closed_at = NULL, application_deadline = NULL, updated_at = NOW()
                WHERE opportunity_id = ? AND org_id = ?
            ");
            $stmt->bind_param("ii", $opportunity_id, $org_id);
            $stmt->execute();
            $stmt->close();

            $notify_user_ids = getVolunteerIdsByApplicationStatus(
                $dbc,
                $opportunity_id,
                ['rejected', 'withdrawn']
            );

            $notify_payload = [
                'title' => 'Opportunity Reopened',
                'message' => "This opportunity has been reopened and is accepting applications again.",
                'type' => 'info',
                'action_url' => "/volcon/app/view_opportunity.php?id={$opportunity_id}",
                'context_type' => 'opportunity',
                'context_id' => $opportunity_id
            ];

            // NOTE: we **do not** resurrect previously rejected applications.
            $dbc->commit();

            if (!empty($notify_payload) && !empty($notify_user_ids)) {
                notifyUsers($notify_user_ids, $notify_payload);
            }

            flash('success', 'Opportunity reopened. Closed Date and Application Deadline have been cleared.');
            header("Location: dashboard_org.php");
            exit;

        case 'cancel':
            if (!in_array($current, ['open','closed'], true)) {
                throw new Exception('Only open or closed opportunities can be cancelled.');
            }
            if ($accepted > 0) {
                throw new Exception('Cannot cancel opportunity with accepted volunteers.');
            }
            $new_status = 'canceled';
            // mark pending/shortlisted -> rejected
            $stmt = $dbc->prepare("UPDATE opportunities SET status = 'canceled', updated_at = NOW() WHERE opportunity_id = ? AND org_id = ?");
            $stmt->bind_param("ii", $opportunity_id, $org_id);
            $stmt->execute();
            $stmt->close();

            $stmt = $dbc->prepare("
                UPDATE applications
                SET status = 'rejected',
                    response_at = NOW()
                WHERE opportunity_id = ?
                AND status IN ('pending','shortlisted')
            ");
            $stmt->bind_param("i", $opportunity_id);
            $stmt->execute();
            $stmt->close();

            $notify_user_ids = getVolunteerIdsByApplicationStatus(
                $dbc,
                $opportunity_id,
                ['accepted', 'pending']
            );

            $notify_payload = [
                'title' => 'Opportunity Cancelled',
                'message' => "This opportunity has been cancelled by the organization.",
                'type' => 'danger',
                'action_url' => "/volcon/app/view_opportunity.php?id={$opportunity_id}",
                'context_type' => 'opportunity',
                'context_id' => $opportunity_id
            ];

            $dbc->commit();

            if (!empty($notify_payload) && !empty($notify_user_ids)) {
                notifyUsers($notify_user_ids, $notify_payload);
            }

            flash('success', 'Opportunity cancelled and applicants notified (if notifications enabled).');
            header("Location: dashboard_org.php");
            exit;

        case 'delete':
            // Soft-delete rules: allow delete for draft, canceled, or open with no apps
            if ($current === 'draft' || ($current === 'open' && $total_apps === 0) || $current === 'canceled') {
                // Soft-delete
                $new_status = 'deleted';
                $stmt = $dbc->prepare("UPDATE opportunities SET status = 'deleted', updated_at = NOW() WHERE opportunity_id = ? AND org_id = ?");
                $stmt->bind_param("ii", $opportunity_id, $org_id);
                $stmt->execute();
                $stmt->close();

                // Optional: keep applications for audit, but you may want to mark them deleted or keep untouched.
                // Here we keep them for audit trail.
                $dbc->commit();
                flash('success', 'Opportunity marked as deleted.');
                header("Location: dashboard_org.php");
                exit;
            } else {
                throw new Exception('This opportunity cannot be deleted. You can delete drafts, canceled items, or open items with no applications.');
            }
            break;

        case 'hard_delete':
            // Danger: permanent delete. Only allow if no accepted participants and no participation rows.
            // Requires explicit confirmation (e.g. ?action=hard_delete&confirm=1).
            $confirm = isset($_GET['confirm']) && $_GET['confirm'] === '1';
            if (!$confirm) {
                throw new Exception('Hard delete requires explicit confirmation.');
            }
            // do not allow hard delete if there are accepted apps or participation
            if ($accepted > 0) {
                throw new Exception('Cannot hard-delete: accepted volunteers exist.');
            }
            // check participation
            $stmt = $dbc->prepare("SELECT COUNT(*) AS pc FROM participation WHERE opportunity_id = ?");
            $stmt->bind_param("i", $opportunity_id);
            $stmt->execute();
            $pc = (int)$stmt->get_result()->fetch_assoc()['pc'];
            $stmt->close();
            if ($pc > 0) {
                throw new Exception('Cannot hard-delete: participation records exist.');
            }

            // delete child rows and files (use helper)
            deleteOpportunityFiles($dbc, $opportunity_id, $org_id);

            // delete applications (should be zero or only withdrawn/rejected)
            $stmt = $dbc->prepare("DELETE FROM applications WHERE opportunity_id = ?");
            $stmt->bind_param("i", $opportunity_id);
            $stmt->execute();
            $stmt->close();

            // finally delete the opportunity record
            $stmt = $dbc->prepare("DELETE FROM opportunities WHERE opportunity_id = ? AND org_id = ?");
            $stmt->bind_param("ii", $opportunity_id, $org_id);
            $stmt->execute();
            $stmt->close();

            $dbc->commit();
            flash('success', 'Opportunity permanently deleted.');
            header("Location: dashboard_org.php");
            exit;

        case 'complete':
            if ($current !== 'ongoing') {
                throw new Exception('Only ongoing opportunities can be completed.');
            }

            // Participation summary: must have at least one and no pending
            $stmt = $dbc->prepare("
                SELECT 
                    COUNT(*) AS total_participants,
                    SUM(status = 'pending') AS pending_count
                FROM participation
                WHERE opportunity_id = ?
            ");
            $stmt->bind_param("i", $opportunity_id);
            $stmt->execute();
            $p = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $total_participants = (int)$p['total_participants'];
            $pending_count = (int)$p['pending_count'];

            if ($total_participants === 0) {
                throw new Exception('Cannot complete: no participation records. Record attendance first.');
            }
            if ($pending_count > 0) {
                throw new Exception("Cannot complete: {$pending_count} participant(s) still pending.");
            }

            $notify_user_ids = getParticipantVolunteerIds($dbc, $opportunity_id);

            $notify_payload = [
                'title' => 'Opportunity Completed',
                'message' => "Thank you for participating! This opportunity has been completed. Please give your review or feedback once your attendance is marked.",
                'type' => 'success',
                'action_url' => "/volcon/app/my_participation.php",
                'context_type' => 'opportunity',
                'context_id' => $opportunity_id
            ];

            $new_status = 'completed';
            // final update below
            break;

        default:
            throw new Exception('Unhandled action.');
    }

    // Generic update (used for publish, delete soft, complete)
    if ($new_status !== null) {
        $stmt = $dbc->prepare("UPDATE opportunities SET status = ?, updated_at = NOW() WHERE opportunity_id = ? AND org_id = ?");
        $stmt->bind_param("sii", $new_status, $opportunity_id, $org_id);
        $stmt->execute();
        $stmt->close();
    }

    $dbc->commit();

    if (!empty($notify_payload) && !empty($notify_user_ids)) {
        notifyUsers($notify_user_ids, $notify_payload);
    }
    
    flash('success', 'Opportunity status updated to ' . ($new_status ?? $action) . '.');
    header("Location: dashboard_org.php");
    exit();

} catch (Throwable $e) {
    $dbc->rollback();
    flash('error', $e->getMessage());
    header("Location: dashboard_org.php");
    exit();
}
