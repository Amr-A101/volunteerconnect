<?php
// core/auto_opp_trans.php

require_once __DIR__ . '/../api/notify.php';

/**
 * Run automatic opportunity state transitions
 * + emit system notifications safely
 */
function runOpportunityAutoTransitions(mysqli $dbc, int $org_id): void
{
    $dbc->begin_transaction();

    try {

        /* =====================================================
           1. OPEN → CLOSED (deadline passed)
           ===================================================== */

        // 1A. Detect opportunities that WILL close
        $stmt = $dbc->prepare("
            SELECT opportunity_id, title
            FROM opportunities
            WHERE org_id = ?
              AND status = 'open'
              AND application_deadline IS NOT NULL
              AND application_deadline < NOW()
        ");
        $stmt->bind_param("i", $org_id);
        $stmt->execute();
        $toClose = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // 1B. Apply transition
        if ($toClose) {
            $stmt = $dbc->prepare("
                UPDATE opportunities
                SET status = 'closed',
                    closed_at = NOW()
                WHERE org_id = ?
                  AND status = 'open'
                  AND application_deadline IS NOT NULL
                  AND application_deadline < NOW()
            ");
            $stmt->bind_param("i", $org_id);
            $stmt->execute();
            $stmt->close();
        }

        // 1C. Notify accepted volunteers
        foreach ($toClose as $opp) {

            $stmt = $dbc->prepare("
                SELECT volunteer_id
                FROM applications
                WHERE opportunity_id = ?
                  AND status = 'accepted'
            ");
            $stmt->bind_param("i", $opp['opportunity_id']);
            $stmt->execute();

            $volunteerIds = array_column(
                $stmt->get_result()->fetch_all(MYSQLI_ASSOC),
                'volunteer_id'
            );
            $stmt->close();

            if ($volunteerIds) {
                notifyUsers($volunteerIds, [
                    'title' => 'Opportunity Closed',
                    'message' => "The opportunity \"{$opp['title']}\" is now closed.",
                    'type' => 'info',
                    'action_url' => "/volcon/app/view_opportunity.php?id={$opp['opportunity_id']}",
                    'context_type' => 'opportunity',
                    'context_id' => $opp['opportunity_id']
                ]);
            }
        }

        /* =====================================================
           2. OPEN → CANCELED (no volunteers at start time)
           ===================================================== */

        $stmt = $dbc->prepare("
            SELECT opportunity_id, title
            FROM opportunities
            WHERE org_id = ?
              AND status = 'open'
              AND start_date IS NOT NULL
              AND CONCAT(start_date,' ',COALESCE(start_time,'00:00:00')) <= NOW()
              AND NOT EXISTS (
                SELECT 1 FROM applications
                WHERE opportunity_id = opportunities.opportunity_id
                  AND status = 'accepted'
              )
              AND start_date != '0000-00-00'
        ");
        $stmt->bind_param("i", $org_id);
        $stmt->execute();
        $toCancel = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if ($toCancel) {
            $dbc->query("
                UPDATE opportunities
                SET status = 'canceled',
                    closed_at = NOW()
                WHERE org_id = {$org_id}
                  AND status = 'open'
                  AND start_date IS NOT NULL
                  AND CONCAT(start_date,' ',COALESCE(start_time,'00:00:00')) <= NOW()
                  AND start_date != '0000-00-00'
            ");
        }

        foreach ($toCancel as $opp) {

            $stmt = $dbc->prepare("
                SELECT volunteer_id
                FROM applications
                WHERE opportunity_id = ?
            ");
            $stmt->bind_param("i", $opp['opportunity_id']);
            $stmt->execute();

            $volunteerIds = array_column(
                $stmt->get_result()->fetch_all(MYSQLI_ASSOC),
                'volunteer_id'
            );
            $stmt->close();

            if ($volunteerIds) {
                notifyUsers($volunteerIds, [
                    'title' => 'Opportunity Canceled',
                    'message' => "The opportunity \"{$opp['title']}\" was canceled due to no volunteers.",
                    'type' => 'warning',
                    'action_url' => "/volcon/app/view_opportunity.php?id={$opp['opportunity_id']}",
                    'context_type' => 'opportunity',
                    'context_id' => $opp['opportunity_id']
                ]);
            }
        }

        /* =====================================================
           3. OPEN / CLOSED → ONGOING
           ===================================================== */

        $stmt = $dbc->prepare("
            SELECT opportunity_id, title
            FROM opportunities
            WHERE org_id = ?
              AND status IN ('open','closed')
              AND start_date IS NOT NULL
              AND CONCAT(start_date,' ',COALESCE(start_time,'00:00:00')) <= NOW()
        ");
        $stmt->bind_param("i", $org_id);
        $stmt->execute();
        $toOngoing = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if ($toOngoing) {
            $stmt = $dbc->prepare("
                UPDATE opportunities
                SET status = 'ongoing'
                WHERE org_id = ?
                  AND status IN ('open','closed')
                  AND start_date IS NOT NULL
                  AND CONCAT(start_date,' ',COALESCE(start_time,'00:00:00')) <= NOW()
            ");
            $stmt->bind_param("i", $org_id);
            $stmt->execute();
            $stmt->close();
        }

        foreach ($toOngoing as $opp) {

            $stmt = $dbc->prepare("
                SELECT volunteer_id
                FROM applications
                WHERE opportunity_id = ?
                  AND status = 'accepted'
            ");
            $stmt->bind_param("i", $opp['opportunity_id']);
            $stmt->execute();

            $volunteerIds = array_column(
                $stmt->get_result()->fetch_all(MYSQLI_ASSOC),
                'volunteer_id'
            );
            $stmt->close();

            if ($volunteerIds) {
                notifyUsers($volunteerIds, [
                    'title' => 'Opportunity Started',
                    'message' => "The opportunity \"{$opp['title']}\" is now ongoing.",
                    'type' => 'success',
                    'action_url' => "/volcon/app/participation_manager.php?id={$opp['opportunity_id']}",
                    'context_type' => 'opportunity',
                    'context_id' => $opp['opportunity_id']
                ]);
            }
        }

        /* =====================================================
           4. ONGOING → COMPLETED
           ===================================================== */

        $stmt = $dbc->prepare("
            SELECT opportunity_id, title
            FROM opportunities
            WHERE org_id = ?
              AND status = 'ongoing'
              AND end_date IS NOT NULL
              AND CONCAT(end_date,' ',COALESCE(end_time,'23:59:59')) < NOW()
        ");
        $stmt->bind_param("i", $org_id);
        $stmt->execute();
        $toCompleted = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if ($toCompleted) {
            $stmt = $dbc->prepare("
                UPDATE opportunities
                SET status = 'completed'
                WHERE org_id = ?
                  AND status = 'ongoing'
                  AND end_date IS NOT NULL
                  AND CONCAT(end_date,' ',COALESCE(end_time,'23:59:59')) < NOW()
            ");
            $stmt->bind_param("i", $org_id);
            $stmt->execute();
            $stmt->close();
        }

        foreach ($toCompleted as $opp) {

            $stmt = $dbc->prepare("
                SELECT volunteer_id
                FROM participation
                WHERE opportunity_id = ?
            ");
            $stmt->bind_param("i", $opp['opportunity_id']);
            $stmt->execute();

            $volunteerIds = array_column(
                $stmt->get_result()->fetch_all(MYSQLI_ASSOC),
                'volunteer_id'
            );
            $stmt->close();

            if ($volunteerIds) {
                notifyUsers($volunteerIds, [
                    'title' => 'Opportunity Completed',
                    'message' => "Thank you for participating in \"{$opp['title']}\". Please give your review or feedback once your attendance is marked.",
                    'type' => 'success',
                    'action_url' => "/volcon/app/my_participation.php",
                    'context_type' => 'opportunity',
                    'context_id' => $opp['opportunity_id']
                ]);
            }
        }

        $dbc->commit();

    } catch (Throwable $e) {
        $dbc->rollback();
        throw $e;
    }
}




/**
 * for flexible date/time opportunities.
 */
function resolveOpportunityState(array $opp): array
{
    // ---- Date & time existence ----
    $has_start_date = !empty($opp['start_date']) && $opp['start_date'] !== '0000-00-00';
    $has_end_date   = !empty($opp['end_date']) && $opp['end_date'] !== '0000-00-00';

    $has_start_time = !empty($opp['start_time']);
    $has_end_time   = !empty($opp['end_time']);

    // ---- Flexibility ----
    $is_date_flexible = !$has_start_date && !$has_end_date;
    $is_time_flexible = !$has_start_time && !$has_end_time;
    $is_fully_flexible = $is_date_flexible && $is_time_flexible;

    // ---- Status flags ----
    $status = $opp['status'];

    $is_open      = $status === 'open';
    $is_closed    = $status === 'closed';
    $is_ongoing   = $status === 'ongoing';
    $is_completed = $status === 'completed';
    $is_blocked   = in_array($status, ['deleted','canceled','suspended'], true);

    // ---- Attendance eligibility ----
    $is_ongoing_effective =
        (
            in_array($status, ['ongoing','completed'], true)
            || ($is_fully_flexible && !$is_blocked)
        );

    // ---- Edit & read-only ----
    $can_edit  = $is_ongoing_effective && !$is_completed;
    $read_only = $is_completed;

    // ---- Date display ----
    if ($is_date_flexible) {
        $formatted_start = 'Flexible date';
        $formatted_end   = null;
    } else {
        $formatted_start = $has_start_date
            ? date('M d, Y', strtotime($opp['start_date']))
            : null;

        $formatted_end = ($has_end_date && $opp['end_date'] !== $opp['start_date'])
            ? date('M d, Y', strtotime($opp['end_date']))
            : null;
    }

    // ---- Time display ----
    if ($is_time_flexible) {
        $time_range = 'Flexible time';
    } else {
        $time_range = null;
        if ($has_start_time) {
            $time_range = date('g:i A', strtotime($opp['start_time']));
            if ($has_end_time) {
                $time_range .= ' - ' . date('g:i A', strtotime($opp['end_time']));
            }
        }
    }

    // ---- Total hours (safe) ----
    $total_hours_possible = null;

    if ($has_start_date && $has_end_date) {
        $start_date = new DateTime($opp['start_date']);
        $end_date   = new DateTime($opp['end_date']);

        if ($end_date >= $start_date) {

            // Inclusive day count
            $days = $start_date->diff($end_date)->days + 1;

            // Case 1: Both start & end time exist → exact hours
            if (!empty($opp['start_time']) && !empty($opp['end_time'])) {

                $start = new DateTime($opp['start_date'] . ' ' . $opp['start_time']);
                $end   = new DateTime($opp['end_date']   . ' ' . $opp['end_time']);

                if ($end > $start) {
                    $interval = $start->diff($end);
                    $total_hours_possible =
                        ($interval->days * 24) +
                        $interval->h +
                        round($interval->i / 60, 1);
                }
            }
            
        }
    } // Case 2: No time at all → assume 8 hours per day
    else {
        $total_hours_possible = 'N/A';
    }


    return [
        // status
        'status' => $status,
        'is_open' => $is_open,
        'is_closed' => $is_closed,
        'is_ongoing' => $is_ongoing,
        'is_completed' => $is_completed,
        'is_blocked' => $is_blocked,

        // flexibility
        'is_date_flexible' => $is_date_flexible,
        'is_time_flexible' => $is_time_flexible,
        'is_fully_flexible' => $is_fully_flexible,

        // permissions
        'is_ongoing_effective' => $is_ongoing_effective,
        'can_edit' => $can_edit,
        'read_only' => $read_only,

        // display
        'formatted_start' => $formatted_start,
        'formatted_end' => $formatted_end,
        'time_range' => $time_range,
        'total_hours_possible' => $total_hours_possible,
    ];
}


function getAttendanceGraceInfo(array $opportunity, int $graceHours = 48): array
{
    $endTs = strtotime($opportunity['end_date'] ?: $opportunity['start_date']);
    $lockTs = $endTs + ($graceHours * 3600);
    $now = time();

    $remainingSeconds = max(0, $lockTs - $now);

    return [
        'lock_ts' => $lockTs,
        'remaining_seconds' => $remainingSeconds,
        'remaining_hours' => (int)ceil($remainingSeconds / 3600),
        'is_locked' => $now >= $lockTs
    ];
}

function hasGraceNotificationBeenSent(
    int $userId,
    int $opportunityId,
    string $marker
): bool {
    global $dbc;

    $stmt = $dbc->prepare("
        SELECT 1 FROM notifications
        WHERE user_id = ?
          AND context_type = 'attendance_grace'
          AND context_id = ?
          AND message LIKE ?
          AND is_deleted = 0
        LIMIT 1
    ");

    $like = "%{$marker}%";
    $stmt->bind_param("iis", $userId, $opportunityId, $like);
    $stmt->execute();

    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();

    return $exists;
}

function notifyAttendanceGraceCountdown(array $opportunity, int $orgId): void
{
    $grace = getAttendanceGraceInfo($opportunity);
    $oppId = (int)$opportunity['opportunity_id'];

    /* =========================
       FINAL LOCK NOTIFICATION
    ========================= */
    if ($grace['is_locked']) {

        if (!hasGraceNotificationBeenSent($orgId, $oppId, '[LOCKED]')) {
            createNotification([
                'user_id' => $orgId,
                'role_target' => 'organization',
                'title' => 'Attendance Locked',
                'message' =>
                    "[LOCKED] Attendance records for \"{$opportunity['title']}\" are now locked. "
                    . "Contact an administrator if changes are required.",
                'type' => 'system',
                'action_url' =>
                    "/volcon/app/participation_manager.php?id={$oppId}&tab=summary",
                'context_type' => 'attendance_grace',
                'context_id' => $oppId,
                'is_dismissible' => 1,
                'created_by' => 'system',
                'created_by_id' => null
            ]);
        }

        return; // stop here
    }

    /* =========================
       COUNTDOWN NOTIFICATIONS
    ========================= */
    $remainingHours = $grace['remaining_hours'];

    // Only notify every 6 hours, skip 0
    if ($remainingHours <= 0 || $remainingHours % 6 !== 0) {
        return;
    }

    $marker = "[LOCK_IN_{$remainingHours}H]";

    if (hasGraceNotificationBeenSent($orgId, $oppId, $marker)) {
        return;
    }

    createNotification([
        'user_id' => $orgId,
        'role_target' => 'organization',
        'title' => 'Attendance Lock Countdown',
        'message' =>
            "{$marker} Attendance records for \"{$opportunity['title']}\" will be locked "
            . "in {$remainingHours} hours. Please finalize attendance.",
        'type' => $remainingHours <= 12 ? 'warning' : 'info',
        'action_url' =>
            "/volcon/app/participation_manager.php?id={$oppId}&tab=attendance",
        'context_type' => 'attendance_grace',
        'context_id' => $oppId,
        'is_dismissible' => 1,
        'created_by' => 'system',
        'created_by_id' => null
    ]);
}
