<?php

require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/auth.php';

$loggedUser = current_user();
$role = $_SESSION['role'] ?? 'guest';

$is_vol   = ($role === 'vol');
$is_org   = ($role === 'org');
$is_admin = ($role === 'admin');

$volunteer_id = $is_vol ? (int)$loggedUser['user_id'] : null;

    /* ---------------------------
    Pagination
    --------------------------- */
    $per_page = 12;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $offset = ($page - 1) * $per_page;

    /* ---------------------------
    Filters (from GET)
    --------------------------- */
    $q = trim($_GET['q'] ?? '');
    $city = trim($_GET['city'] ?? '');
    $state = trim($_GET['state'] ?? '');
    $status = trim($_GET['status'] ?? 'open'); // default show open
    $skill_ids = isset($_GET['skills']) ? (array)$_GET['skills'] : [];
    $interest_ids = isset($_GET['interests']) ? (array)$_GET['interests'] : [];
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    $time_from = $_GET['time_from'] ?? '';
    $time_to = $_GET['time_to'] ?? '';
    $flexible = isset($_GET['flexible']) && $_GET['flexible'] === '1';

    /* ---------------------------
    Load filter options (skills & interests)
    --------------------------- */
    $skills = [];
    $interests = [];

    $res = $dbc->query("SELECT skill_id, skill_name FROM skills ORDER BY skill_name");
    if ($res) $skills = $res->fetch_all(MYSQLI_ASSOC);

    $res = $dbc->query("SELECT interest_id, interest_name FROM interests ORDER BY interest_name");
    if ($res) $interests = $res->fetch_all(MYSQLI_ASSOC);

    /* ---------------------------
    Build query with filters
    --------------------------- */

    $public_statuses = ['open', 'ongoing', 'closed', 'completed'];

    $where = [ "o.status IN ('" . implode("','", $public_statuses) . "')" ];  // exclude these from public browse
    $types = '';
    $params = [];

    /* Status filter (if 'all' show all except deleted/draft/cancelled/suspended) */
    if ($status && $status !== 'all') {
        $where[] = "o.status = ?";
        $types .= 's';
        $params[] = $status;
    }

    $allowed_status_filters = ['open','ongoing','closed','completed','all'];

    if (!in_array($status, $allowed_status_filters)) {
        $status = 'open';
    }

    /* Keyword search (title, brief_summary, description, org name) */
    if ($q !== '') {
        $where[] = "(o.title LIKE ? OR o.brief_summary LIKE ? OR o.description LIKE ? OR org.name LIKE ?)";
        $q_like = '%' . $q . '%';
        $types .= 'ssss';
        $params[] = $q_like; $params[] = $q_like; $params[] = $q_like; $params[] = $q_like;
    }

    /* City / State filter */
    if ($city !== '') {
        $where[] = "o.city LIKE ?";
        $types .= 's'; $params[] = '%' . $city . '%';
    }
    if ($state !== '') {
        $where[] = "o.state LIKE ?";
        $types .= 's'; $params[] = '%' . $state . '%';
    }

    /* Date filters */
    if ($date_from !== '') {
        $where[] = "(o.start_date IS NULL OR o.start_date >= ?)";
        $types .= 's'; $params[] = $date_from;
    }
    if ($date_to !== '') {
        $where[] = "(o.end_date IS NULL OR o.end_date <= ?)";
        $types .= 's'; $params[] = $date_to;
    }

    /* Flexible checkbox - when checked, INCLUDE flexible opportunities */
    if ($flexible) {
        // When checkbox is NOT checked, EXCLUDE completely flexible opportunities
        $where[] = "o.start_date IS NULL AND o.end_date IS NULL";
    }

    /* Time filters (if your DB has time columns) */
    if ($time_from !== '') {
        $where[] = "(o.start_time IS NULL OR o.start_time >= ?)";
        $types .= 's'; $params[] = $time_from;
    }
    if ($time_to !== '') {
        $where[] = "(o.end_time IS NULL OR o.end_time <= ?)";
        $types .= 's'; $params[] = $time_to;
    }

    /* Skills filtering */
    if (!empty($skill_ids)) {
        $skill_ids = array_values(array_filter($skill_ids, function($v){ return is_numeric($v) && (int)$v>0; }));
        if (!empty($skill_ids)) {
            $in = implode(',', array_fill(0, count($skill_ids), '?'));
            $where[] = "EXISTS (SELECT 1 FROM opportunity_skills os WHERE os.opportunity_id = o.opportunity_id AND os.skill_id IN ($in))";
            $types .= str_repeat('i', count($skill_ids));
            foreach ($skill_ids as $id) $params[] = (int)$id;
        }
    }

    /* Interests filtering */
    if (!empty($interest_ids)) {
        $interest_ids = array_values(array_filter($interest_ids, function($v){ return is_numeric($v) && (int)$v>0; }));
        if (!empty($interest_ids)) {
            $in = implode(',', array_fill(0, count($interest_ids), '?'));
            $where[] = "EXISTS (SELECT 1 FROM opportunity_interests oi WHERE oi.opportunity_id = o.opportunity_id AND oi.interest_id IN ($in))";
            $types .= str_repeat('i', count($interest_ids));
            foreach ($interest_ids as $id) $params[] = (int)$id;
        }
    }

    /* Build WHERE clause */
    $where_sql = '';
    if (!empty($where)) $where_sql = 'WHERE ' . implode(' AND ', $where);

    /* ---------------------------
    Count total results
    --------------------------- */
    $count_sql = "
        SELECT COUNT(DISTINCT o.opportunity_id) AS total
        FROM opportunities o
        JOIN organizations org ON org.org_id = o.org_id
        $where_sql
    ";

    $count_stmt = $dbc->prepare($count_sql);
    if ($count_stmt === false) {
        die("Prepare failed: " . $dbc->error);
    }
    if ($types !== '') {
        $bind_names = [];
        $bind_names[] = $types;
        for ($i=0; $i<count($params); $i++) {
            $bind_names[] = &$params[$i];
        }
        call_user_func_array([$count_stmt, 'bind_param'], $bind_names);
    }

    $count_stmt->execute();
    $total = 0;
    $res = $count_stmt->get_result();
    
    if ($res) {
        $row = $res->fetch_assoc();
        $total = (int)$row['total'];
    }
    
    $count_stmt->close();

    $total_pages = max(1, (int)ceil($total / $per_page));

    $select_sql = "
        SELECT
            o.*,
            org.name AS org_name,
            COUNT(DISTINCT a.application_id) AS applied_count,
            SUM(a.status = 'accepted') AS accepted_count
        FROM opportunities o
        JOIN organizations org ON org.org_id = o.org_id
        LEFT JOIN applications a ON a.opportunity_id = o.opportunity_id
        $where_sql
        GROUP BY o.opportunity_id
        ORDER BY
            CASE o.status
                WHEN 'open' THEN 1
                WHEN 'ongoing' THEN 2
                WHEN 'closed' THEN 3
                WHEN 'completed' THEN 4
                ELSE 5
            END,
            o.start_date IS NULL,
            o.start_date ASC,
            o.created_at DESC
        LIMIT ? OFFSET ?
    ";

    $select_stmt = $dbc->prepare($select_sql);
    if ($select_stmt === false) {
        die("Prepare failed: " . $dbc->error);
    }

    /* bind params again + pagination integers */
    $all_params = $params;
    $all_types = $types;
    $all_params[] = $per_page;
    $all_params[] = $offset;
    $all_types .= 'ii';

    $bind_names = [];
    $bind_names[] = $all_types;
    for ($i=0; $i<count($all_params); $i++) {
        $bind_names[] = &$all_params[$i];
    }
    call_user_func_array([$select_stmt, 'bind_param'], $bind_names);

    $select_stmt->execute();
    $result = $select_stmt->get_result();

    /* fetch saved/applied flags for volunteer in batch */
    $opportunity_ids = [];
    $opps = [];
    while ($row = $result->fetch_assoc()) {
        $opportunity_ids[] = (int)$row['opportunity_id'];
        $opps[$row['opportunity_id']] = $row;
    }
    $select_stmt->close();

    $saved_map = [];
    $applied_map = [];
    if ($is_vol && !empty($opportunity_ids)) {
        $in = implode(',', array_fill(0, count($opportunity_ids), '?'));
        $sql = "SELECT opportunity_id FROM saved_opportunities WHERE volunteer_id = ? AND opportunity_id IN ($in)";
        $stmt = $dbc->prepare($sql);
        $types2 = 'i' . str_repeat('i', count($opportunity_ids));
        $params2 = array_merge([$volunteer_id], $opportunity_ids);
        $bind = array_merge([$types2], $params2);
        $tmp = [];
        foreach ($bind as $k => $v) $tmp[$k] = &$bind[$k];
        call_user_func_array([$stmt, 'bind_param'], $tmp);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) {
            $saved_map[(int)$r['opportunity_id']] = true;
        }
        $stmt->close();

        // applications for this volunteer
        $sql = "SELECT opportunity_id, status FROM applications WHERE volunteer_id = ? AND opportunity_id IN ($in)";
        $stmt = $dbc->prepare($sql);
        $bind = array_merge([$types2], $params2);
        $tmp = [];
        foreach ($bind as $k => $v) $tmp[$k] = &$bind[$k];
        call_user_func_array([$stmt, 'bind_param'], $tmp);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) {
            $applied_map[(int)$r['opportunity_id']] = $r['status'];
        }
        $stmt->close();
    }

    function formatOpportunityDate($start, $end) {
        // Check if both dates are NULL
        if (is_null($start) && is_null($end)) {
            return 'Flexible dates';
        }
        
        // Check if start is NULL but end exists
        if (is_null($start) && !is_null($end)) {
            return 'Until ' . date('M j, Y', strtotime($end));
        }
        
        // Check if end is NULL but start exists
        if (!is_null($start) && is_null($end)) {
            return 'From ' . date('M j, Y', strtotime($start));
        }
        
        // Both dates exist
        if ($start == $end) {
            return date('M j, Y', strtotime($start));
        }
        
        return date('M j, Y', strtotime($start)) . ' - ' . date('M j, Y', strtotime($end));
    }

    $page_title = 'Browse Opportunities';
    require_once __DIR__ . "/views/layout/header.php";

?>

<link rel="stylesheet" href="/volcon/assets/css/opportunity_browse.css">

<div class="vc-browse-container">

    <header class="vc-page-header">
        <h1>Discover Opportunities</h1>
        <p class="vc-sub">Find volunteering events matching your interests.</p>
    </header>

    <!-- FILTERS -->
    <section class="vc-filters" id="filterSection">
        <div class="vc-filter-header" onclick="toggleFilters()">
            <div class="vc-filter-header-left">
                <i class="fas fa-filter"></i>
                <h2 class="vc-filter-header-title">Filters</h2>
                <?php 
                $active_filters = 0;
                if ($q) $active_filters++;
                if ($city) $active_filters++;
                if ($state) $active_filters++;
                if ($status && $status !== 'open') $active_filters++;
                if (!empty($skill_ids)) $active_filters++;
                if (!empty($interest_ids)) $active_filters++;
                if ($date_from) $active_filters++;
                if ($date_to) $active_filters++;
                if ($time_from) $active_filters++;
                if ($time_to) $active_filters++;
                if ($flexible) $active_filters++;
                
                if ($active_filters > 0): ?>
                    <span class="vc-filter-count"><?= $active_filters ?></span>
                <?php endif; ?>
            </div>
            <div class="vc-filter-toggle">
                <span id="filterToggleText">Hide Filters</span>
                <i class="fas fa-chevron-up"></i>
            </div>
        </div>
        
        <div class="vc-filter-body">
        <form method="get" class="vc-filter-form" id="filterForm">
            
            <!-- Search & Location Row -->
            <div class="vc-filter-section">
                <h4 class="vc-filter-heading">Location & Status</h4>
                <div class="vc-filter-grid">
                    <div class="vc-form-group">
                        <label class="vc-label">Keywords</label>
                        <input type="text" name="q" placeholder="Search keywords like 'Beach'..." value="<?= htmlspecialchars($q) ?>" class="vc-input" />
                    </div>
                    <div class="vc-form-group">
                        <label class="vc-label">Area/Town</label>
                        <input type="text" name="city" placeholder="Enter city or area name.." value="<?= htmlspecialchars($city) ?>" class="vc-input" />
                    </div>
                    <div class="vc-form-group">
                        <label class="vc-label">State</label>
                        <input type="text" name="state" placeholder="Enter name of state..." value="<?= htmlspecialchars($state) ?>" class="vc-input" />
                    </div>
                    <div class="vc-form-group">
                        <label class="vc-label">Current Status</label>
                        <select name="status" class="vc-select">
                            <option value="open" <?= $status==='open' ? 'selected' : '' ?>>Open</option>
                            <option value="ongoing" <?= $status==='ongoing' ? 'selected' : '' ?>>Ongoing</option>
                            <option value="closed" <?= $status==='closed' ? 'selected' : '' ?>>Closed</option>
                            <option value="completed" <?= $status==='completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="all" <?= $status==='all' ? 'selected' : '' ?>>All</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Date & Time Row -->
            <div class="vc-filter-section">
                <h4 class="vc-filter-heading">Date & Time</h4>
                <div class="vc-filter-grid vc-filter-grid-datetime">
                    <div class="vc-form-group">
                        <label class="vc-label">Date From</label>
                        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" class="vc-input" />
                    </div>
                    <div class="vc-form-group">
                        <label class="vc-label">Date To</label>
                        <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" class="vc-input" />
                    </div>
                    <div class="vc-form-group">
                        <label class="vc-label">Time From</label>
                        <input type="time" name="time_from" value="<?= htmlspecialchars($time_from) ?>" class="vc-input" />
                    </div>
                    <div class="vc-form-group">
                        <label class="vc-label">Time To</label>
                        <input type="time" name="time_to" value="<?= htmlspecialchars($time_to) ?>" class="vc-input" />
                    </div>
                    <div class="vc-form-group vc-checkbox-group">
                        <label class="vc-checkbox-label">
                            <input type="checkbox" 
                                name="flexible" 
                                value="1" 
                                <?= $flexible ? 'checked' : '' ?> 
                                class="vc-checkbox" 
                                id="flexibleCheckbox" />
                            <span>Show only flexible dates</span>
                        </label>
                        <small class="vc-help-text">Opportunities with no fixed start/end dates</small>
                    </div>
                </div>
            </div>

            <!-- Skills & Interests Row -->
            <div class="vc-filter-section">
                <h4 class="vc-filter-heading">Skills & Interests</h4>
                <div class="vc-filter-grid vc-filter-grid-tags">
                    <div class="vc-form-group">
                        <label class="vc-label">Skills</label>
                        <div class="vc-tag-selector" id="skillSelector">
                            <div class="vc-tag-input-wrapper">
                                <input type="text" class="vc-tag-input" placeholder="Type to search skills..." />
                                <i class="fas fa-chevron-down"></i>
                            </div>
                            <div class="vc-tag-selected">
                                <?php foreach ($skill_ids as $sid): 
                                    $skill_name = '';
                                    foreach ($skills as $s) {
                                        if ($s['skill_id'] == $sid) {
                                            $skill_name = $s['skill_name'];
                                            break;
                                        }
                                    }
                                    if ($skill_name):
                                ?>
                                    <span class="vc-tag" data-value="<?= $sid ?>">
                                        <?= htmlspecialchars($skill_name) ?>
                                        <i class="fas fa-times"></i>
                                        <input type="hidden" name="skills[]" value="<?= $sid ?>" />
                                    </span>
                                <?php endif; endforeach; ?>
                            </div>
                            <div class="vc-tag-dropdown">
                                <?php foreach ($skills as $s): ?>
                                    <div class="vc-tag-option" data-value="<?= $s['skill_id'] ?>" data-label="<?= htmlspecialchars($s['skill_name']) ?>">
                                        <?= htmlspecialchars($s['skill_name']) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="vc-form-group">
                        <label class="vc-label">Interests</label>
                        <div class="vc-tag-selector" id="interestSelector">
                            <div class="vc-tag-input-wrapper">
                                <input type="text" class="vc-tag-input" placeholder="Type to search interests..." />
                                <i class="fas fa-chevron-down"></i>
                            </div>
                            <div class="vc-tag-selected">
                                <?php foreach ($interest_ids as $iid): 
                                    $interest_name = '';
                                    foreach ($interests as $i) {
                                        if ($i['interest_id'] == $iid) {
                                            $interest_name = $i['interest_name'];
                                            break;
                                        }
                                    }
                                    if ($interest_name):
                                ?>
                                    <span class="vc-tag" data-value="<?= $iid ?>">
                                        <?= htmlspecialchars($interest_name) ?>
                                        <i class="fas fa-times"></i>
                                        <input type="hidden" name="interests[]" value="<?= $iid ?>" />
                                    </span>
                                <?php endif; endforeach; ?>
                            </div>
                            <div class="vc-tag-dropdown">
                                <?php foreach ($interests as $i): ?>
                                    <div class="vc-tag-option" data-value="<?= $i['interest_id'] ?>" data-label="<?= htmlspecialchars($i['interest_name']) ?>">
                                        <?= htmlspecialchars($i['interest_name']) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="vc-filter-actions">
                <button type="submit" class="vc-btn vc-btn-primary">
                    <i class="fas fa-search"></i> Apply Filters
                </button>
                <a href="browse_opportunities.php" class="vc-btn vc-btn-outline">
                    <i class="fas fa-redo"></i> Reset All
                </a>
            </div>
        </form>
        </div>
    </section>

    <!-- RESULTS SUMMARY -->
    <section class="vc-results-summary">
        <div class="vc-results-info">
            <strong><?= $total ?></strong> <?= $total === 1 ? 'opportunity' : 'opportunities' ?> found
        </div>
        <div class="vc-results-page">Page <?= $page ?> of <?= $total_pages ?></div>
    </section>

    <!-- GRID OF CARDS -->
    <section class="vc-grid">
        <?php if (empty($opportunity_ids)): ?>
            <div class="vc-empty">
                <i class="fas fa-search fa-3x"></i>
                <h3>No opportunities found</h3>
                <p>Try adjusting your filters to see more results.</p>
            </div>
        <?php else: ?>
            <?php foreach ($opps as $opp): 
                $opp_id = (int)$opp['opportunity_id'];
                $status = $opp['status'];
                $applied_count = (int)$opp['applied_count'];
                $accepted_count = (int)$opp['accepted_count'];
                $saved = $is_vol && !empty($saved_map[$opp_id]);
                $user_app_status = $is_vol && isset($applied_map[$opp_id]) ? $applied_map[$opp_id] : null;
                $cover = $opp['image_url'] ?: '/volcon/assets/uploads/placeholder.png';
            ?>
            <article class="vc-card">
                <div class="vc-card-media">
                    <img src="<?= htmlspecialchars($cover) ?>" alt="<?= htmlspecialchars($opp['title']) ?>" />
                    <span class="vc-badge vc-status-<?= htmlspecialchars($status) ?>"><?= htmlspecialchars(ucfirst($status)) ?></span>
                    
                    <?php if ($is_vol): ?>
                        <button class="vc-bookmark-btn <?= $saved ? 'vc-bookmarked' : '' ?>" 
                                data-id="<?= $opp_id ?>" 
                                title="<?= $saved ? 'Saved' : 'Save opportunity' ?>">
                            <i class="<?= $saved ? 'fas' : 'far' ?> fa-bookmark"></i>
                        </button>
                    <?php endif; ?>
                </div>

                <div class="vc-card-body">
                    <h3 class="vc-card-title"><?= htmlspecialchars($opp['title']) ?></h3>
                    <div class="vc-card-sub">
                        <a href="profile_org.php?id=<?= $opp['org_id'] ?>"><?= htmlspecialchars($opp['org_name']) ?></a>
                    </div>
                    <p class="vc-card-summary"><?= htmlspecialchars($opp['brief_summary'] ?? '') ?></p>

                    <div class="vc-card-meta">
                        <span><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars(implode(', ', array_filter([$opp['city'],$opp['state'],$opp['country']])) ) ?></span>
                        <span><i class="fas fa-calendar-alt"></i>
                            <?= formatOpportunityDate($opp['start_date'], $opp['end_date']) ?>
                        </span>
                        <span><i class="fas fa-users"></i> <?= (int)$opp['number_of_volunteers'] ?: '—' ?> needed</span>
                    </div>

                    <div class="vc-card-actions">
                        <a href="view_opportunity.php?id=<?= $opp_id ?>" class="vc-btn vc-btn-outline vc-btn-block">
                            <i class="fas fa-eye"></i> View Details
                        </a>

                        <?php 
                            $is_open       = $status === 'open';
                            $is_closed     = $status === 'closed';
                            $is_ongoing    = $status === 'ongoing';
                            $is_completed  = $status === 'completed';
                            $is_canceled   = in_array($status, ['canceled','deleted','suspended']);

                            $can_apply     = $is_open;
                            $can_manage    = !$is_canceled;
                        ?>

                        <?php if ($is_vol): ?>
                            <?php if ($can_apply): ?>

                                <?php if ($user_app_status === null): ?>
                                    <button type="button"
                                            class="vc-btn vc-btn-primary vc-btn-block"
                                            onclick="openCommitmentModal(() => {
                                                window.location.href = 'apply_opportunity.php?id=<?= $opp_id ?>';
                                            })">
                                        <i class="fas fa-paper-plane"></i> Apply Now
                                    </button>

                                <?php elseif (in_array($user_app_status, ['pending','shortlisted'])): ?>
                                    <a href="withdraw_application.php?id=<?= $opp_id ?>" 
                                    onclick="return confirm('Are you sure to withdraw this opportunity application?')"
                                    class="vc-btn vc-btn-warning vc-btn-block">
                                        <i class="fas fa-times-circle"></i> Withdraw Application
                                    </a>

                                <?php elseif ($user_app_status === 'accepted'): ?>
                                    <span class="vc-status-badge vc-badge-success">
                                        <i class="fas fa-check"></i> Accepted
                                    </span>

                                <?php elseif (in_array($user_app_status, ['rejected','withdrawn'])): ?>
                                    <a href="apply_opportunity.php?id=<?= $opp_id ?>"
                                    onclick="return confirm('Are you sure want to reapply this opportunity?')"  
                                    class="vc-btn vc-btn-secondary vc-btn-block">
                                        <i class="fas fa-redo"></i> Reapply
                                    </a>
                                <?php endif; ?>

                            <?php elseif ($is_ongoing): ?>

                                <?php if ($user_app_status === 'accepted'): ?>
                                    <a href="view_participation.php?id=<?= $opp_id ?>" 
                                    class="vc-btn vc-btn-success vc-btn-block">
                                        <i class="fas fa-user-check"></i> View Participation
                                    </a>
                                <?php else: ?>
                                    <span class="vc-status-badge vc-badge-muted">
                                        <i class="fas fa-running"></i>
                                        Opportunity in progress
                                    </span>
                                <?php endif; ?>

                            <?php else: ?>
                                <span class="vc-status-badge vc-badge-muted">
                                    <i class="fas fa-lock"></i>
                                    Not accepting applications
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>


                        <?php if ($is_org && (int)$opp['org_id'] === (int)$loggedUser['user_id']): ?>
                            <a href="applicants_manager.php?id=<?= $opp_id ?>" 
                            class="vc-btn vc-btn-primary">
                                <i class="fas fa-users"></i> Applicants (<?= $applied_count ?>)
                            </a>

                            <?php if ($status === 'draft' || $status === 'open'): ?>
                                <a href="edit_opportunity.php?id=<?= $opp_id ?>" 
                                class="vc-btn vc-btn-secondary">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                            <?php endif; ?>

                            <?php if ($status === 'open'): ?>
                                <a href="change_status.php?id=<?= $opp_id ?>&action=close"
                                onclick="return confirm('Close this opportunity?')"
                                class="vc-btn vc-btn-danger">
                                    <i class="fas fa-lock"></i> Close
                                </a>

                            <?php elseif ($status === 'closed'): ?>
                                <a href="change_status.php?id=<?= $opp_id ?>&action=reopen"
                                onclick="return confirm('Reopen this opportunity?')"
                                class="vc-btn vc-btn-secondary">
                                    <i class="fas fa-lock-open"></i> Reopen
                                </a>

                                <a href="change_status.php?id=<?= $opp_id ?>&action=cancel"
                                onclick="return confirm('Cancel this opportunity?')"
                                class="vc-btn vc-btn-danger">
                                    <i class="fas fa-ban"></i> Cancel
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($is_admin): ?>
                            <a href="admin/moderate_opportunity.php?id=<?= $opp_id ?>" class="vc-btn vc-btn-warning">Moderate</a>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <!-- PAGINATION -->
    <?php if ($total_pages > 1): ?>
    <nav class="vc-pagination">
        <?php if ($page > 1): ?>
            <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page-1]))) ?>" class="vc-page-btn vc-page-prev">
                <i class="fas fa-chevron-left"></i> Previous
            </a>
        <?php endif; ?>

        <div class="vc-page-numbers">
            <?php 
            $start = max(1, $page - 2);
            $end = min($total_pages, $page + 2);
            
            if ($start > 1): ?>
                <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['page' => 1]))) ?>" class="vc-page-num">1</a>
                <?php if ($start > 2): ?><span class="vc-page-dots">...</span><?php endif; ?>
            <?php endif; ?>
            
            <?php for ($i = $start; $i <= $end; $i++): ?>
                <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $i]))) ?>" 
                   class="vc-page-num <?= $i === $page ? 'vc-active' : '' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>
            
            <?php if ($end < $total_pages): ?>
                <?php if ($end < $total_pages - 1): ?><span class="vc-page-dots">...</span><?php endif; ?>
                <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $total_pages]))) ?>" class="vc-page-num"><?= $total_pages ?></a>
            <?php endif; ?>
        </div>

        <?php if ($page < $total_pages): ?>
            <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page+1]))) ?>" class="vc-page-btn vc-page-next">
                Next <i class="fas fa-chevron-right"></i>
            </a>
        <?php endif; ?>
    </nav>
    <?php endif; ?>

</div>

<?php include __DIR__ . '/views/components/volunteer_commitment_modal.php'; ?>

<script src="/volcon/assets/js/opportunity_browse.js"></script>
<script src="/volcon/assets/js/utils/scroll-to-top.js"></script>

<?php require_once __DIR__ . "/views/layout/footer.php"; ?>