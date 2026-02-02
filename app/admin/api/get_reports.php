<?php
// api/get_reports.php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // For testing
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log request for debugging
error_log("Reports API called: " . $_SERVER['REQUEST_URI']);

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Database connection
$conn = new mysqli("localhost", "root", "", "volcon");
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$type = $_GET['type'] ?? 'overview';
$startDate = $_GET['start_date'] ?? null;
$endDate = $_GET['end_date'] ?? null;

error_log("Report type: $type, Start: $startDate, End: $endDate");

try {
    $response = [
        'success' => true,
        'data' => []
    ];
    
    // Get basic stats
    $response['data']['stats'] = getBasicStats($conn);
    
    // Get data based on type
    switch ($type) {
        case 'users':
            $response['data']['user_registrations'] = getUserRegistrations($conn, $startDate, $endDate);
            $response['data']['top_interests'] = getTopInterests($conn);
            $response['data']['top_skills'] = getTopSkills($conn);
            break;
            
        case 'opportunities':
            $response['data']['opportunity_status'] = getOpportunityStatus($conn);
            $response['data']['monthly_activity'] = getMonthlyActivity($conn);
            break;
            
        case 'applications':
            $response['data']['application_status'] = getApplicationStatus($conn);
            break;
            
        case 'overview':
        default:
            $response['data']['user_registrations'] = getUserRegistrations($conn, $startDate, $endDate);
            $response['data']['opportunity_status'] = getOpportunityStatus($conn);
            $response['data']['application_status'] = getApplicationStatus($conn);
            $response['data']['monthly_activity'] = getMonthlyActivity($conn);
            $response['data']['top_interests'] = getTopInterests($conn);
            $response['data']['top_skills'] = getTopSkills($conn);
            break;
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();

function getBasicStats($conn) {
    $stats = [];
    
    $queries = [
        'total_users' => "SELECT COUNT(*) as count FROM users WHERE status = 'verified'",
        'total_organizations' => "SELECT COUNT(*) as count FROM users WHERE role = 'org' AND status = 'verified'",
        'total_volunteers' => "SELECT COUNT(*) as count FROM users WHERE role = 'vol' AND status = 'verified'",
        'total_opportunities' => "SELECT COUNT(*) as count FROM opportunities WHERE status != 'deleted'",
        'total_applications' => "SELECT COUNT(*) as count FROM applications",
        'pending_applications' => "SELECT COUNT(*) as count FROM applications WHERE status = 'pending'"
    ];
    
    foreach ($queries as $key => $query) {
        $result = $conn->query($query);
        if ($result && $row = $result->fetch_assoc()) {
            $stats[$key] = (int)$row['count'];
        } else {
            $stats[$key] = 0;
        }
    }
    
    return $stats;
}

function getUserRegistrations($conn, $startDate, $endDate) {
    $data = ['labels' => [], 'volunteers' => [], 'organizations' => []];
    
    // Default to last 7 days if no dates provided
    if (!$startDate || !$endDate) {
        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime('-7 days'));
    }
    
    // Generate date labels
    $current = strtotime($startDate);
    $end = strtotime($endDate);
    $days = [];
    
    while ($current <= $end) {
        $date = date('M d', $current);
        $days[$date] = [
            'volunteers' => 0,
            'organizations' => 0
        ];
        $current = strtotime('+1 day', $current);
    }
    
    // Get volunteer registrations
    $volQuery = "SELECT DATE(created_at) as date, COUNT(*) as count 
                 FROM users 
                 WHERE role = 'vol' AND status = 'verified' 
                   AND DATE(created_at) BETWEEN ? AND ?
                 GROUP BY DATE(created_at)";
    $stmt = $conn->prepare($volQuery);
    $stmt->bind_param("ss", $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $dateKey = date('M d', strtotime($row['date']));
        if (isset($days[$dateKey])) {
            $days[$dateKey]['volunteers'] = (int)$row['count'];
        }
    }
    
    // Get organization registrations
    $orgQuery = "SELECT DATE(created_at) as date, COUNT(*) as count 
                 FROM users 
                 WHERE role = 'org' AND status = 'verified' 
                   AND DATE(created_at) BETWEEN ? AND ?
                 GROUP BY DATE(created_at)";
    $stmt = $conn->prepare($orgQuery);
    $stmt->bind_param("ss", $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $dateKey = date('M d', strtotime($row['date']));
        if (isset($days[$dateKey])) {
            $days[$dateKey]['organizations'] = (int)$row['count'];
        }
    }
    
    // Format data for chart
    foreach ($days as $date => $counts) {
        $data['labels'][] = $date;
        $data['volunteers'][] = $counts['volunteers'];
        $data['organizations'][] = $counts['organizations'];
    }
    
    return $data;
}

function getOpportunityStatus($conn) {
    $data = ['labels' => [], 'values' => []];
    
    $query = "SELECT status, COUNT(*) as count 
              FROM opportunities 
              WHERE status != 'deleted'
              GROUP BY status 
              ORDER BY count DESC";
    
    $result = $conn->query($query);
    while ($row = $result->fetch_assoc()) {
        $data['labels'][] = ucfirst($row['status']);
        $data['values'][] = (int)$row['count'];
    }
    
    return $data;
}

function getApplicationStatus($conn) {
    $data = ['labels' => [], 'values' => []];
    
    $query = "SELECT status, COUNT(*) as count 
              FROM applications 
              GROUP BY status 
              ORDER BY FIELD(status, 'pending', 'accepted', 'rejected', 'shortlisted')";
    
    $result = $conn->query($query);
    while ($row = $result->fetch_assoc()) {
        $data['labels'][] = ucfirst($row['status']);
        $data['values'][] = (int)$row['count'];
    }
    
    return $data;
}

function getMonthlyActivity($conn) {
    $data = ['labels' => [], 'users' => [], 'opportunities' => [], 'applications' => []];
    
    // Get last 6 months
    for ($i = 5; $i >= 0; $i--) {
        $month = date('M Y', strtotime("-$i months"));
        $data['labels'][] = $month;
        $data['users'][] = 0;
        $data['opportunities'][] = 0;
        $data['applications'][] = 0;
    }
    
    // User registrations
    $userQuery = "SELECT DATE_FORMAT(created_at, '%b %Y') as month, COUNT(*) as count 
                  FROM users 
                  WHERE status = 'verified' 
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                  GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                  ORDER BY DATE_FORMAT(created_at, '%Y-%m')";
    
    $result = $conn->query($userQuery);
    while ($row = $result->fetch_assoc()) {
        $index = array_search($row['month'], $data['labels']);
        if ($index !== false) {
            $data['users'][$index] = (int)$row['count'];
        }
    }
    
    // Opportunities
    $oppQuery = "SELECT DATE_FORMAT(created_at, '%b %Y') as month, COUNT(*) as count 
                 FROM opportunities 
                 WHERE status != 'deleted' 
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                 GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                 ORDER BY DATE_FORMAT(created_at, '%Y-%m')";
    
    $result = $conn->query($oppQuery);
    while ($row = $result->fetch_assoc()) {
        $index = array_search($row['month'], $data['labels']);
        if ($index !== false) {
            $data['opportunities'][$index] = (int)$row['count'];
        }
    }
    
    // Applications
    $appQuery = "SELECT DATE_FORMAT(applied_at, '%b %Y') as month, COUNT(*) as count 
                 FROM applications 
                 WHERE applied_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                 GROUP BY DATE_FORMAT(applied_at, '%Y-%m')
                 ORDER BY DATE_FORMAT(applied_at, '%Y-%m')";
    
    $result = $conn->query($appQuery);
    while ($row = $result->fetch_assoc()) {
        $index = array_search($row['month'], $data['labels']);
        if ($index !== false) {
            $data['applications'][$index] = (int)$row['count'];
        }
    }
    
    return $data;
}

function getTopInterests($conn) {
    $data = ['labels' => [], 'values' => []];
    
    $query = "SELECT i.interest_name, COUNT(vi.vol_id) as count 
              FROM volunteer_interests vi
              JOIN interests i ON vi.interest_id = i.interest_id
              GROUP BY i.interest_id, i.interest_name
              ORDER BY count DESC
              LIMIT 8";
    
    $result = $conn->query($query);
    while ($row = $result->fetch_assoc()) {
        $data['labels'][] = $row['interest_name'];
        $data['values'][] = (int)$row['count'];
    }
    
    return $data;
}

function getTopSkills($conn) {
    $data = ['labels' => [], 'values' => []];
    
    $query = "SELECT s.skill_name, COUNT(vs.vol_id) as count 
              FROM volunteer_skills vs
              JOIN skills s ON vs.skill_id = s.skill_id
              GROUP BY s.skill_id, s.skill_name
              ORDER BY count DESC
              LIMIT 8";
    
    $result = $conn->query($query);
    while ($row = $result->fetch_assoc()) {
        $data['labels'][] = $row['skill_name'];
        $data['values'][] = (int)$row['count'];
    }
    
    return $data;
}
?>