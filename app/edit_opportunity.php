<?php

require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/flash.php';

require_role('org');

// Must be logged in as organization
$user = current_user();
if (!$user || $user['role'] !== 'org') {
    header("Location: login.php");
    exit;
}

$org_id = $user['user_id'];
$errors = [];
$success = false;

// Get opportunity ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: dashboard_org.php");
    exit;
}

$opportunity_id = (int)$_GET['id'];

// Check if opportunity belongs to this organization
$stmt = $dbc->prepare("
    SELECT o.*, 
           COUNT(DISTINCT a.application_id) as applied_count,
           COUNT(CASE WHEN a.status = 'accepted' THEN 1 END) as accepted_count
    FROM opportunities o
    LEFT JOIN applications a ON a.opportunity_id = o.opportunity_id
    WHERE o.opportunity_id = ? AND o.org_id = ? AND o.status != 'deleted'
    GROUP BY o.opportunity_id
");
$stmt->bind_param("ii", $opportunity_id, $org_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: dashboard_org.php");
    exit;
}

$opportunity = $result->fetch_assoc();
$stmt->close();

// Format dates for form inputs
$start_date = $opportunity['start_date'] ? date('Y-m-d', strtotime($opportunity['start_date'])) : '';
$end_date = $opportunity['end_date'] ? date('Y-m-d', strtotime($opportunity['end_date'])) : '';
$start_time = $opportunity['start_time'] ? date('H:i', strtotime($opportunity['start_time'])) : '';
$end_time = $opportunity['end_time'] ? date('H:i', strtotime($opportunity['end_time'])) : '';
$app_deadline = $opportunity['application_deadline'] ? date('Y-m-d\TH:i', strtotime($opportunity['application_deadline'])) : '';

// Get current skills and interests
$skills = [];
$interests = [];
$all_skills = $dbc->query("SELECT skill_id, skill_name FROM skills ORDER BY skill_name");
$all_interests = $dbc->query("SELECT interest_id, interest_name FROM interests ORDER BY interest_name");

if ($all_skills) $skills = $all_skills->fetch_all(MYSQLI_ASSOC);
if ($all_interests) $interests = $all_interests->fetch_all(MYSQLI_ASSOC);

// Get selected skills and interests
$selected_skills = [];
$selected_interests = [];
$skills_result = $dbc->prepare("SELECT skill_id FROM opportunity_skills WHERE opportunity_id = ?");
$skills_result->bind_param("i", $opportunity_id);
$skills_result->execute();
$skills_result->bind_result($skill_id);
while ($skills_result->fetch()) {
    $selected_skills[] = $skill_id;
}
$skills_result->close();

$interests_result = $dbc->prepare("SELECT interest_id FROM opportunity_interests WHERE opportunity_id = ?");
$interests_result->bind_param("i", $opportunity_id);
$interests_result->execute();
$interests_result->bind_result($interest_id);
while ($interests_result->fetch()) {
    $selected_interests[] = $interest_id;
}
$interests_result->close();

// Get contact persons
$contacts = [];
$contacts_result = $dbc->prepare("
    SELECT contact_id, contact_name, contact_email, contact_phone, is_primary 
    FROM opportunity_contacts 
    WHERE opportunity_id = ? 
    ORDER BY is_primary DESC, contact_name
");
$contacts_result->bind_param("i", $opportunity_id);
$contacts_result->execute();
$contacts_result = $contacts_result->get_result();
$contacts = $contacts_result->fetch_all(MYSQLI_ASSOC);
$contacts_result->close();

// Get additional images
$additional_images = [];
$images_result = $dbc->prepare("SELECT image_url FROM opportunity_images WHERE opportunity_id = ? ORDER BY img_id");
$images_result->bind_param("i", $opportunity_id);
$images_result->execute();
$images_result = $images_result->get_result();
$additional_images = $images_result->fetch_all(MYSQLI_ASSOC);
$images_result->close();

// If no contacts exist, create a default one
if (empty($contacts)) {
    $contacts = [[
        'contact_id' => null,
        'contact_name' => '',
        'contact_email' => '',
        'contact_phone' => '',
        'is_primary' => true
    ]];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Determine action
    $action = $_POST['action'] ?? 'update';
    
    // Handle different actions
    switch ($action) {
        case 'duplicate':
            duplicateOpportunity($opportunity_id, $org_id);
            break;
            
        case 'publish':
            publishOpportunity($opportunity_id);
            break;
            
        case 'close':
            closeOpportunity($opportunity_id);
            break;
            
        case 'reopen':
            reopenOpportunity($opportunity_id);
            break;
            
        case 'complete':
            completeOpportunity($opportunity_id);
            break;
            
        case 'update':
        default:
            updateOpportunity($opportunity_id, $org_id, $opportunity);
            break;
    }
}

// Helper function to upload images with new directory structure
function uploadOpportunityImage(array $file, int $opportunity_id, int $org_id, bool $is_cover = false): array
{
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp'
    ];

    $mime = mime_content_type($file['tmp_name']);

    if (!isset($allowed[$mime])) {
        return ['success' => false, 'error' => 'Only JPG, PNG, GIF or WebP images allowed.'];
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        return ['success' => false, 'error' => 'File size must be less than 5MB.'];
    }

    /* ===============================
       DIRECTORY STRUCTURE
       =============================== */

    $projectRoot = realpath(__DIR__ . '/..');

    $baseDir = $projectRoot . "/assets/uploads/opps/org_{$org_id}/opp_{$opportunity_id}";
    $targetDir = $is_cover
        ? $baseDir . "/cover"
        : $baseDir . "/additional";

    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
        return ['success' => false, 'error' => 'Failed to create upload directory.'];
    }

    /* ===============================
       FILE NAMING
       =============================== */

    $ext = $allowed[$mime];
    $unique = time() . '_' . bin2hex(random_bytes(6));

    $filename = $is_cover
        ? "cover_{$unique}.{$ext}"
        : "{$unique}.{$ext}";

    $dest = $targetDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['success' => false, 'error' => 'Failed to save uploaded image.'];
    }

    $urlPath = $is_cover
        ? "/volcon/assets/uploads/opps/org_{$org_id}/opp_{$opportunity_id}/cover/{$filename}"
        : "/volcon/assets/uploads/opps/org_{$org_id}/opp_{$opportunity_id}/additional/{$filename}";

    return [
        'success' => true,
        'path'    => $urlPath
    ];
}


// Helper functions for actions (same as before, but with proper error handling)
function duplicateOpportunity($opportunity_id, $org_id) {
    global $dbc, $errors;
    
    $dbc->begin_transaction();
    try {
        // Copy opportunity
        $stmt = $dbc->prepare("
            INSERT INTO opportunities (
                org_id, title, brief_summary, description, responsibilities, 
                location_name, city, state, country, postcode,
                start_date, end_date, start_time, end_time, application_deadline,
                number_of_volunteers, min_age, requirements, benefits, safety_notes, transportation_info,
                image_url, status
            )
            SELECT 
                org_id, CONCAT(title, ' (Copy)'), brief_summary, description, responsibilities,
                location_name, city, state, country, postcode, start_date, end_date, 
                start_time, end_time, application_deadline, number_of_volunteers, 
                min_age, requirements, benefits, safety_notes, 
                transportation_info, image_url, 'draft'
            FROM opportunities 
            WHERE opportunity_id = ? AND org_id = ?
        ");
        $stmt->bind_param("ii", $opportunity_id, $org_id);
        $stmt->execute();
        $new_id = $stmt->insert_id;
        $stmt->close();
        
        // Copy skills
        $dbc->query("
            INSERT INTO opportunity_skills (opportunity_id, skill_id)
            SELECT $new_id, skill_id 
            FROM opportunity_skills 
            WHERE opportunity_id = $opportunity_id
        ");
        
        // Copy interests
        $dbc->query("
            INSERT INTO opportunity_interests (opportunity_id, interest_id)
            SELECT $new_id, interest_id 
            FROM opportunity_interests 
            WHERE opportunity_id = $opportunity_id
        ");
        
        // Copy images (if we want to copy images too)
        $images_result = $dbc->query("SELECT image_url FROM opportunity_images WHERE opportunity_id = $opportunity_id");
        if ($images_result->num_rows > 0) {
            $img_stmt = $dbc->prepare("INSERT INTO opportunity_images (opportunity_id, image_url) VALUES (?, ?)");
            while ($image = $images_result->fetch_assoc()) {
                $img_stmt->bind_param("is", $new_id, $image['image_url']);
                $img_stmt->execute();
            }
            $img_stmt->close();
        }
        
        // Copy contacts
        $dbc->query("
            INSERT INTO opportunity_contacts (opportunity_id, contact_name, contact_email, contact_phone, is_primary)
            SELECT $new_id, contact_name, contact_email, contact_phone, is_primary
            FROM opportunity_contacts 
            WHERE opportunity_id = $opportunity_id
        ");
        
        $dbc->commit();
        flash('success', 'Opportunity duplicated successfully as draft.');
        header("Location: edit_opportunity.php?id=$new_id");
        exit;
        
    } catch (Exception $e) {
        $dbc->rollback();
        $errors[] = "Failed to duplicate opportunity: " . $e->getMessage();
    }
}

function publishOpportunity($opportunity_id) {
    global $dbc, $errors;
    
    $stmt = $dbc->prepare("
        UPDATE opportunities 
        SET status = 'open', 
            published_at = NOW() 
        WHERE opportunity_id = ? AND status = 'draft'
    ");
    $stmt->bind_param("i", $opportunity_id);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        flash('success', 'Opportunity published successfully!');
    } else {
        $errors[] = "Failed to publish opportunity. It might already be published.";
    }
    $stmt->close();
}

function closeOpportunity($opportunity_id) {
    global $dbc, $errors;
    
    $stmt = $dbc->prepare("
        UPDATE opportunities 
        SET status = 'closed', 
            closed_at = NOW() 
        WHERE opportunity_id = ? AND status = 'open'
    ");
    $stmt->bind_param("i", $opportunity_id);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        flash('success', 'Opportunity closed successfully.');
    } else {
        $errors[] = "Failed to close opportunity.";
    }
    $stmt->close();
}

function reopenOpportunity($opportunity_id) {
    global $dbc, $errors;
    
    $stmt = $dbc->prepare("
        UPDATE opportunities 
        SET status = 'open' 
        WHERE opportunity_id = ? AND status = 'closed'
    ");
    $stmt->bind_param("i", $opportunity_id);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        flash('success', 'Opportunity reopened successfully.');
    } else {
        $errors[] = "Failed to reopen opportunity.";
    }
    $stmt->close();
}

function completeOpportunity($opportunity_id) {
    global $dbc, $errors;
    
    $stmt = $dbc->prepare("
        UPDATE opportunities 
        SET status = 'completed', 
            completed_at = NOW() 
        WHERE opportunity_id = ? AND status = 'closed'
    ");
    $stmt->bind_param("i", $opportunity_id);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        flash('success', 'Opportunity marked as completed.');
    } else {
        $errors[] = "Failed to mark opportunity as completed.";
    }
    $stmt->close();
}

function updateOpportunity($opportunity_id, $org_id, $current_opp) {
    global $dbc, $errors;
    
    // Get form data
    $form_data = [
        'title' => trim($_POST['title'] ?? ''),
        'brief_summary' => trim($_POST['brief_summary'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'responsibilities' => trim($_POST['responsibilities'] ?? ''),
        'location_name' => trim($_POST['location_name'] ?? ''),
        'city' => trim($_POST['city'] ?? ''),
        'state' => trim($_POST['state'] ?? ''),
        'country' => trim($_POST['country'] ?? 'Malaysia'),
        'postcode' => trim($_POST['postcode'] ?? ''),
        'start_date' => $_POST['start_date'] ?? null,
        'end_date' => $_POST['end_date'] ?? null,
        'start_time' => $_POST['start_time'] ?? null,
        'end_time' => $_POST['end_time'] ?? null,
        'application_deadline' => $_POST['application_deadline'] ?? null,
        'number_of_volunteers' => intval($_POST['number_of_volunteers'] ?? 1),
        'min_age' => !empty($_POST['min_age']) ? intval($_POST['min_age']) : null,
        'requirements' => trim($_POST['requirements'] ?? ''),
        'transportation_info' => trim($_POST['transportation_info'] ?? ''),
        'safety_notes' => trim($_POST['safety_notes'] ?? ''),
        'benefits' => trim($_POST['benefits'] ?? ''),
        'required_skills' => $_POST['required_skills'] ?? [],
        'preferred_interests' => $_POST['preferred_interests'] ?? []
    ];

    $plain_responsibilities = strip_tags($form_data['responsibilities'], '<p><ul><ol><li><strong><em><a>'); 

    if (strlen($plain_responsibilities) < 10) {
        $errors[] = "Responsibilities must be at least 10 characters long.";
    }
    
    // Validation
    if (empty($form_data['title'])) $errors[] = "Title is required.";
    if (empty($form_data['brief_summary'])) $errors[] = "Brief summary is required.";
    if (empty($form_data['description'])) $errors[] = "Description is required.";
    if (empty($form_data['responsibilities'])) $errors[] = "Responsibilities are required.";
    if (empty($form_data['city'])) $errors[] = "City is required.";
    if (empty($form_data['state'])) $errors[] = "State is required.";
    if ($form_data['number_of_volunteers'] < 1) $errors[] = "Number of volunteers must be at least 1.";
    
    // Calculate minimum volunteers based on accepted applications
    $applied_count = (int)$current_opp['accepted_count'] ?? 0;
    if ($form_data['number_of_volunteers'] < $applied_count) {
        $errors[] = "Number of volunteers cannot be less than currently accepted volunteers ($applied_count).";
    }
    
    // Date validation
    $today = date('Y-m-d');
    if (!empty($form_data['start_date']) && $form_data['start_date'] < $today) {
        $errors[] = "Start date cannot be in the past.";
    }
    
    if (!empty($form_data['start_date']) && !empty($form_data['end_date']) && $form_data['end_date'] < $form_data['start_date']) {
        $errors[] = "End date cannot be earlier than start date.";
    }

    $min_age = $form_data['min_age'];
    
    // Time validation
    if (!empty($form_data['start_time']) && !empty($form_data['end_time'])) {
        if ($form_data['start_date'] == $form_data['end_date'] && $form_data['end_time'] <= $form_data['start_time']) {
            $errors[] = "End time must be later than start time on the same day.";
        }
    }
    
    // Validate contact persons
    $contact_persons = [];
    if (isset($_POST['contact_name']) && is_array($_POST['contact_name'])) {
        foreach ($_POST['contact_name'] as $index => $name) {
            $name = trim($name);
            $email = trim($_POST['contact_email'][$index] ?? '');
            $phone = trim($_POST['contact_phone'][$index] ?? '');
            $is_primary = isset($_POST['is_primary']) && $_POST['is_primary'] == $index;
            
            if (!empty($name)) {
                if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "Invalid email format for contact person #" . ($index + 1);
                }
                
                $contact_persons[] = [
                    'contact_name' => $name,
                    'contact_email' => $email,
                    'contact_phone' => $phone,
                    'is_primary' => $is_primary
                ];
            }
        }
    }
    
    if (empty($contact_persons)) {
        $errors[] = "At least one contact person is required.";
    }
    
    // Check primary contact
    $has_primary = false;
    foreach ($contact_persons as $contact) {
        if ($contact['is_primary']) {
            $has_primary = true;
            break;
        }
    }
    
    if (!$has_primary) {
        $errors[] = "Please designate a primary contact person.";
    }
    
    // Handle cover image upload
    $image_url = $current_opp['image_url'];
    
    if (isset($_POST['remove_cover_image']) && $_POST['remove_cover_image'] == '1') {
        $image_url = null;
    } elseif (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['cover_image'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Error uploading cover image.";
        } else {
            $upload_result = uploadOpportunityImage($file, $opportunity_id, $org_id, true);
            if ($upload_result['success']) {
                $image_url = $upload_result['path'];
            } else {
                $errors[] = $upload_result['error'];
            }
        }
    }
    
    // Handle additional images
    $new_additional_images = [];
    
    if (isset($_FILES['additional_images']) && !empty($_FILES['additional_images']['name'][0])) {
        $files = $_FILES['additional_images'];
        
        if (count($_FILES['additional_images']['name']) > 5) {
            $errors[] = "Maximum 5 additional images allowed.";
        }

        foreach ($files['tmp_name'] as $index => $tmp_name) {
            if ($files['error'][$index] === UPLOAD_ERR_OK && $tmp_name) {
                $file = [
                    'name' => $files['name'][$index],
                    'type' => $files['type'][$index],
                    'tmp_name' => $tmp_name,
                    'error' => $files['error'][$index],
                    'size' => $files['size'][$index]
                ];
                
                $upload_result = uploadOpportunityImage($file, $opportunity_id, $org_id, false);
                if ($upload_result['success']) {
                    $new_additional_images[] = $upload_result['path'];
                }
            }
        }
    }
    
    // Handle image removal
    $images_to_remove = $_POST['remove_additional_images'] ?? [];
    
    if (empty($errors)) {
        $dbc->begin_transaction();
        try {
            // Debug: Count parameters
            $params_count = 0;
            $types = '';
            
            // Prepare SQL with correct number of placeholders
            $sql = "
                UPDATE opportunities 
                SET title = ?, brief_summary = ?, description = ?, responsibilities = ?,
                    location_name = ?, city = ?, state = ?, country = ?, postcode = ?,
                    start_date = ?, end_date = ?, start_time = ?, end_time = ?,
                    application_deadline = ?, number_of_volunteers = ?,
                    min_age = ?, requirements = ?, transportation_info = ?,
                    safety_notes = ?, benefits = ?, image_url = ?, updated_at = NOW()
                WHERE opportunity_id = ? AND org_id = ?
            ";
            
            $stmt = $dbc->prepare($sql);
            
            if (!$stmt) {
                throw new Exception("Failed to prepare statement: " . $dbc->error);
            }
            
            // For nullable fields, we need to bind them properly
            $start_date = $form_data['start_date'] ?: null;
            $end_date = $form_data['end_date'] ?: null;
            $start_time = $form_data['start_time'] ?: null;
            $end_time = $form_data['end_time'] ?: null;
            $application_deadline = $form_data['application_deadline'] ?: null;
            
            // Create types string dynamically
            $types = 'ssssssssssssssiisssssii';
            
            $stmt->bind_param(
                $types,
                $form_data['title'],
                $form_data['brief_summary'],
                $form_data['description'],
                $form_data['responsibilities'],
                $form_data['location_name'],
                $form_data['city'],
                $form_data['state'],
                $form_data['country'],
                $form_data['postcode'],
                $start_date,
                $end_date,
                $start_time,
                $end_time,
                $application_deadline,
                $form_data['number_of_volunteers'],
                $min_age,
                $form_data['requirements'],
                $form_data['transportation_info'],
                $form_data['safety_notes'],
                $form_data['benefits'],
                $image_url,
                $opportunity_id,
                $org_id
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update opportunity: " . $stmt->error);
            }
            $stmt->close();
            
            // Update skills
            $dbc->query("DELETE FROM opportunity_skills WHERE opportunity_id = $opportunity_id");
            if (!empty($form_data['required_skills'])) {
                $skill_stmt = $dbc->prepare("INSERT INTO opportunity_skills (opportunity_id, skill_id) VALUES (?, ?)");
                foreach ($form_data['required_skills'] as $skill_value) {
                    // Handle both numeric IDs and new skill names
                    if (is_numeric($skill_value)) {
                        $skill_id = (int)$skill_value;
                    } else {
                        // Insert new skill
                        $new_skill_stmt = $dbc->prepare("INSERT INTO skills (skill_name) VALUES (?)");
                        $new_skill_stmt->bind_param("s", $skill_value);
                        $new_skill_stmt->execute();
                        $skill_id = $new_skill_stmt->insert_id;
                        $new_skill_stmt->close();
                    }
                    $skill_stmt->bind_param("ii", $opportunity_id, $skill_id);
                    $skill_stmt->execute();
                }
                $skill_stmt->close();
            }
            
            // Update interests
            $dbc->query("DELETE FROM opportunity_interests WHERE opportunity_id = $opportunity_id");
            if (!empty($form_data['preferred_interests'])) {
                $interest_stmt = $dbc->prepare("INSERT INTO opportunity_interests (opportunity_id, interest_id) VALUES (?, ?)");
                foreach ($form_data['preferred_interests'] as $interest_value) {
                    // Handle both numeric IDs and new interest names
                    if (is_numeric($interest_value)) {
                        $interest_id = (int)$interest_value;
                    } else {
                        // Insert new interest
                        $new_interest_stmt = $dbc->prepare("INSERT INTO interests (interest_name) VALUES (?) ON DUPLICATE KEY UPDATE interest_id = LAST_INSERT_ID(interest_id)");
                        $new_interest_stmt->bind_param("s", $interest_value);
                        $new_interest_stmt->execute();
                        $interest_id = $new_interest_stmt->insert_id;
                        $new_interest_stmt->close();
                    }
                    $interest_stmt->bind_param("ii", $opportunity_id, $interest_id);
                    $interest_stmt->execute();
                }
                $interest_stmt->close();
            }
            
            // Update contacts
            $dbc->query("DELETE FROM opportunity_contacts WHERE opportunity_id = $opportunity_id");
            if (!empty($contact_persons)) {
                $contact_stmt = $dbc->prepare("
                    INSERT INTO opportunity_contacts 
                    (opportunity_id, contact_name, contact_email, contact_phone, is_primary) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                
                foreach ($contact_persons as $contact) {
                    $contact_stmt->bind_param(
                        "isssi", 
                        $opportunity_id, 
                        $contact['contact_name'],
                        $contact['contact_email'],
                        $contact['contact_phone'],
                        $contact['is_primary']
                    );
                    $contact_stmt->execute();
                }
                $contact_stmt->close();
            }
            
            // Handle additional images removal
            $images_to_remove = $_POST['remove_additional_images'] ?? [];
            if (!empty($images_to_remove)) {
                $remove_stmt = $dbc->prepare("DELETE FROM opportunity_images WHERE img_id = ?");
                foreach ($images_to_remove as $img_id) {
                    $remove_stmt->bind_param("i", $img_id);
                    $remove_stmt->execute();
                }
                $remove_stmt->close();
            }
            
            // Add new additional images
            if (!empty($new_additional_images)) {
                $img_stmt = $dbc->prepare("INSERT INTO opportunity_images (opportunity_id, image_url) VALUES (?, ?)");
                foreach ($new_additional_images as $new_image_url) {
                    $img_stmt->bind_param("is", $opportunity_id, $new_image_url);
                    $img_stmt->execute();
                }
                $img_stmt->close();
            }
            
            $dbc->commit();
            flash('success', 'Opportunity updated successfully!');
            header("Location: edit_opportunity.php?id=$opportunity_id");
            exit;
            
        } catch (Exception $e) {
            $dbc->rollback();
            $errors[] = "Failed to update opportunity: " . $e->getMessage();
            // For debugging:
            $errors[] = "SQL: " . $sql;
            $errors[] = "Parameters: " . print_r($form_data, true);
        }
    }
}

$page_title = "Edit Opportunity";
require_once __DIR__ . "/views/layout/header.php";

?>

<link rel="stylesheet" href="/volcon/assets/css/opportunity_form.css">
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.css" rel="stylesheet">
<script src="/volcon/assets/js/utils/location_selector.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
<script src="/volcon/assets/js/form_opportunity.js" defer></script>

<div class="vc-form-container">

    <!-- Header with status and actions -->
    <div class="vc-form-header">
        <div class="vc-header-top">
            <h1><i class="fas fa-edit"></i> Edit Opportunity</h1>
            <div class="vc-opportunity-status-badge vc-status-<?= $opportunity['status'] ?>">
                <?= strtoupper($opportunity['status']) ?>
                <?php if ($opportunity['status'] === 'open'): ?>
                    <span class="vc-volunteer-count">
                        (<?= $opportunity['accepted_count'] ?? 0 ?>/<?= $opportunity['number_of_volunteers'] ?> filled)
                    </span>
                <?php endif; ?>
            </div>
        </div>
        
        <p class="vc-form-subtitle">Editing: <?= htmlspecialchars($opportunity['title']) ?></p>
        
        <!-- Action buttons based on status -->
        <div class="vc-action-buttons">
            <button type="button" class="vc-btn" id="viewBtn" onclick="window.location.href='view_opportunity.php?id=<?= (int)$opportunity['opportunity_id'] ?>'">
                <i class="fas fa-eye"></i> View Opportunity
            </button>

            <?php if ($opportunity['status'] === 'draft'): ?>
                <button type="button" class="vc-btn vc-btn-success" id="publishBtn">
                    <i class="fas fa-paper-plane"></i> Publish Now
                </button>
                <span class="vc-action-hint">Ready to receive applications</span>
                
            <?php elseif ($opportunity['status'] === 'open'): ?>
                <button type="button" class="vc-btn vc-btn-warning" id="closeBtn">
                    <i class="fas fa-lock"></i> Close Opportunity
                </button> 
                <span class="vc-action-hint">Stop receiving new applications</span>
            <?php elseif ($opportunity['status'] === 'closed'): ?>
                <button type="button" class="vc-btn vc-btn-secondary" id="reopenBtn">
                    <i class="fas fa-redo"></i> Reopen
                </button>
                
                <?php if ($opportunity['accepted_count'] > 0): ?>
                    <button type="button" class="vc-btn vc-btn-success" id="markCompletedBtn">
                        <i class="fas fa-check-circle"></i> Mark as Completed
                    </button>
                    <span class="vc-action-hint">All volunteers have completed their work</span>
                <?php endif; ?>
                
                <div class="vc-suggestions">
                    <h4><i class="fas fa-lightbulb"></i> Suggestions:</h4>
                    <ul>
                        <li>Create a new opportunity based on this one</li>
                        <li>Review volunteer feedback</li>
                        <li>Update and reopen if needed</li>
                    </ul>
                </div>
            <?php endif; ?>
            
            <!-- Always show duplicate button -->
            <button type="button" class="vc-btn vc-btn-outline" id="duplicateBtn">
                <i class="fas fa-copy"></i> Duplicate as Draft
            </button>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="vc-alert vc-alert-danger">
            <i class="fas fa-exclamation-triangle"></i>
            <div>
                <strong>Please fix the following errors:</strong>
                <ul>
                    <?php foreach ($errors as $e): ?>
                        <li><?= htmlspecialchars($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <!-- Main edit form -->
    <form method="post" enctype="multipart/form-data" class="vc-form" id="opportunityForm">
        <input type="hidden" name="action" id="formAction" value="">
        
        <!-- SECTION 1: BASIC INFORMATION -->
        <div class="vc-form-section">
            <div class="vc-section-header">
                <i class="fas fa-info-circle"></i>
                <h2>Basic Information</h2>
            </div>
            
            <div class="vc-form-group">
                <label class="required">
                    <i class="fas fa-heading"></i> Opportunity Title
                </label>
                <input type="text" name="title" 
                       value="<?= htmlspecialchars($opportunity['title']) ?>" 
                       maxlength="200" required>
            </div>

            <div class="vc-form-group">
                <label class="required">
                    <i class="fas fa-file-alt"></i> Brief Summary
                </label>
                <textarea name="brief_summary" maxlength="150" required><?= 
                    htmlspecialchars($opportunity['brief_summary'] ?? '') 
                ?></textarea>
            </div>

            <div class="vc-form-group">
                <label class="required">
                    <i class="fas fa-align-left"></i> Full Description
                </label>
                <textarea name="description" rows="6" required><?= 
                    htmlspecialchars($opportunity['description']) 
                ?></textarea>
            </div>

            <div class="vc-form-group">
                <label for="responsibilities" style="display: block; margin-bottom: 8px; font-weight: 600;">Responsibilities</label>

                <textarea name="responsibilities" id="responsibilities" required>
                    <?= htmlspecialchars($_POST['responsibilities'] ?? $opportunity['responsibilities'] ?? '') ?>
                </textarea>

                <small class="vc-muted" style="display: block; margin-top: 8px; color: #6c757d;">
                    Use the bullet or number buttons to list specific volunteer tasks.
                </small>
            </div>
        </div>

        <!-- SECTION 2: LOCATION & SCHEDULE -->
        <div class="vc-form-section">
            <div class="vc-section-header">
                <i class="fas fa-map-marked-alt"></i>
                <h2>Location & Schedule</h2>
            </div>

            <div class="vc-form-group">
                <label><i class="fas fa-map-pin"></i> Location Name (Optional)</label>
                <input type="text" name="location_name" 
                    value="<?= htmlspecialchars($opportunity['location_name'] ?? '') ?>"
                    placeholder="e.g., Taman Negara Community Center">
            </div>

            <div class="vc-form-row-4">
                <div class="vc-form-group">
                    <label class="required"><i class="fas fa-landmark"></i> State</label>
                    <select id="state_org" name="state" class="vc-form-control" required>
                        <option value="">-- Select State --</option>
                        <option value="<?= htmlspecialchars($opportunity['state']) ?>" selected>
                            <?= htmlspecialchars($opportunity['state']) ?>
                        </option>
                    </select>
                </div>
                <div class="vc-form-group">
                    <label class="required"><i class="fas fa-city"></i> Area/Town</label>
                    <select id="city_org" name="city" class="vc-form-control" required>
                        <option value="">-- Select Town or Area --</option>
                        <option value="<?= htmlspecialchars($opportunity['city']) ?>" selected>
                            <?= htmlspecialchars($opportunity['city']) ?>
                        </option>
                    </select>
                </div>
                <div class="vc-form-group">
                    <label><i class="fas fa-map-marker-alt"></i> Postcode</label>
                    <input type="text" name="postcode" 
                        value="<?= htmlspecialchars($opportunity['postcode'] ?? '') ?>"
                        placeholder="e.g., 50000" maxlength="10">
                </div>
                <div class="vc-form-group">
                    <label><i class="fas fa-globe"></i> Country</label>
                    <input type="text" name="country" 
                        value="<?= htmlspecialchars($opportunity['country'] ?? 'Malaysia') ?>">
                </div>
            </div>

            <div class="vc-form-row-2">
                <div class="vc-form-group">
                    <label><i class="fas fa-calendar-day"></i> Start Date</label>
                    <input type="date" name="start_date" 
                        value="<?= $start_date ?>"
                        min="<?= date('Y-m-d') ?>">
                    <div class="vc-form-hint">Leave blank for flexible dates</div>
                </div>
                <div class="vc-form-group">
                    <label><i class="fas fa-calendar-day"></i> End Date</label>
                    <input type="date" name="end_date" 
                        value="<?= $end_date ?>"
                        min="<?= $start_date ?: date('Y-m-d') ?>">
                </div>
            </div>

            <div class="vc-form-row-2">
                <div class="vc-form-group">
                    <label><i class="fas fa-clock"></i> Start Time</label>
                    <input type="time" name="start_time" 
                        value="<?= $start_time ?>">
                </div>
                <div class="vc-form-group">
                    <label><i class="fas fa-clock"></i> End Time</label>
                    <input type="time" name="end_time" 
                        value="<?= $end_time ?>">
                </div>
            </div>

            <div class="vc-form-group">
                <label><i class="fas fa-hourglass-end"></i> Application Deadline</label>
                <input type="datetime-local" name="application_deadline" 
                    value="<?= $app_deadline ?>"
                    min="<?= date('Y-m-d\TH:i') ?>">
                <div class="vc-form-hint">Opportunity will auto-close after this date</div>
            </div>
        </div>

        <!-- SECTION 3: VOLUNTEER REQUIREMENTS -->
        <div class="vc-form-section">
            <div class="vc-section-header">
                <i class="fas fa-user-check"></i>
                <h2>Volunteer Requirements</h2>
            </div>

            <div class="vc-form-row-2">
                <div class="vc-form-group">
                    <label class="required">
                        <i class="fas fa-users"></i> Volunteers Needed
                    </label>
                    <input type="number" name="number_of_volunteers" 
                        value="<?= $opportunity['number_of_volunteers'] ?>" 
                        min="<?= max(1, $opportunity['accepted_count'] ?? 0) ?>" 
                        max="1000" required>
                    <div class="vc-form-hint">
                        Minimum: <?= max(1, $opportunity['accepted_count'] ?? 0) ?> 
                        (based on accepted volunteers)
                    </div>
                </div>
                <div class="vc-form-group">
                    <label><i class="fas fa-birthday-cake"></i> Minimum Age</label>
                    <input type="number" name="min_age" 
                        value="<?= $opportunity['min_age'] ?? '' ?>" 
                        min="16" max="100" placeholder="16">
                    <div class="vc-form-hint">Leave blank if no age restriction</div>
                </div>
            </div>

            <!-- Skills with TomSelect -->
            <div class="vc-form-group">
                <label><i class="fas fa-tools"></i> Required Skills (Optional)</label>
                <select name="required_skills[]" id="requiredSkills" placeholder="Search and select skills..." multiple>
                    <?php foreach ($skills as $skill): ?>
                        <option value="<?= $skill['skill_id'] ?>" 
                                <?= in_array($skill['skill_id'], $selected_skills) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($skill['skill_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="vc-form-hint">Type to search or select from the list (max 10 skills)</div>
            </div>

            <!-- Interests with TomSelect -->
            <div class="vc-form-group">
                <label><i class="fas fa-heart"></i> Preferred Interests (Optional)</label>
                <select name="preferred_interests[]" id="preferredInterests" placeholder="Search and select interests..." multiple>
                    <?php foreach ($interests as $interest): ?>
                        <option value="<?= $interest['interest_id'] ?>" 
                                <?= in_array($interest['interest_id'], $selected_interests) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($interest['interest_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="vc-form-hint">Type to search or select from the list (max 10 interests)</div>
            </div>

            <div class="vc-form-group">
                <label><i class="fas fa-clipboard-list"></i> Specific Requirements</label>
                <textarea name="requirements" 
                        placeholder="e.g., Must attend orientation, Bring own equipment, etc." 
                        rows="3"><?= htmlspecialchars($opportunity['requirements'] ?? '') ?></textarea>
                <div class="vc-form-hint">List any specific requirements or prerequisites</div>
            </div>

            <div class="vc-form-group">
                <label><i class="fas fa-car"></i> Transportation Information</label>
                <textarea name="transportation_info" 
                        placeholder="Parking availability, public transport access, carpooling options" 
                        rows="3"><?= htmlspecialchars($opportunity['transportation_info'] ?? '') ?></textarea>
                <div class="vc-form-hint">Information to help volunteers plan their travel</div>
            </div>
        </div>

        <!-- SECTION 4: CONTACT & ADDITIONAL INFO -->
        <div class="vc-form-section">
            <div class="vc-section-header">
                <i class="fas fa-address-book"></i>
                <h2>Contact & Additional Information</h2>
            </div>
            
            <!-- Contact Persons (same as before) -->
            <div id="contactPersonsContainer">
                <?php foreach ($contacts as $index => $contact): ?>
                    <div class="vc-contact-person" data-index="<?= $index ?>">
                        <div class="vc-contact-header">
                            <h4>Contact Person #<?= $index + 1 ?></h4>
                            <?php if ($index > 0): ?>
                                <button type="button" class="vc-btn vc-btn-sm vc-btn-danger remove-contact">
                                    <i class="fas fa-trash"></i> Remove
                                </button>
                            <?php endif; ?>
                        </div>
                        
                        <div class="vc-form-row-3">
                            <div class="vc-form-group">
                                <label class="<?= $index === 0 ? 'required' : '' ?>">
                                    <i class="fas fa-user"></i> Name
                                </label>
                                <input type="text" 
                                    name="contact_name[]" 
                                    value="<?= htmlspecialchars($contact['contact_name']) ?>"
                                    <?= $index === 0 ? 'required' : '' ?>
                                    placeholder="Full name">
                            </div>
                            <div class="vc-form-group">
                                <label><i class="fas fa-envelope"></i> Email</label>
                                <input type="email" 
                                    name="contact_email[]" 
                                    value="<?= htmlspecialchars($contact['contact_email']) ?>"
                                    placeholder="email@example.com">
                            </div>
                            <div class="vc-form-group">
                                <label><i class="fas fa-phone"></i> Phone</label>
                                <input type="tel" 
                                    name="contact_phone[]" 
                                    value="<?= htmlspecialchars($contact['contact_phone']) ?>"
                                    placeholder="+60 12-345 6789">
                            </div>
                        </div>
                        
                        <div class="vc-form-group">
                            <label class="vc-checkbox-label">
                                <input type="radio" 
                                    name="is_primary" 
                                    value="<?= $index ?>"
                                    <?= $contact['is_primary'] ? 'checked' : '' ?>
                                    class="vc-primary-contact">
                                <span><i class="fas fa-star"></i> Set as Primary Contact</span>
                            </label>
                            <div class="vc-form-hint">
                                Primary contact will be displayed to volunteers
                            </div>
                        </div>
                        
                        <?php if ($index < count($contacts) - 1): ?>
                            <hr class="vc-contact-divider">
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <button type="button" id="addContactBtn" class="vc-btn vc-btn-outline">
                <i class="fas fa-plus"></i> Add Another Contact Person
            </button>
            
            <div class="vc-form-group" style="margin-top: 30px;">
                <label><i class="fas fa-exclamation-triangle"></i> Safety Notes & Warnings</label>
                <textarea name="safety_notes" 
                        placeholder="Important safety information, risks, required precautions" 
                        rows="3"><?= htmlspecialchars($opportunity['safety_notes'] ?? '') ?></textarea>
                <div class="vc-form-hint">Any safety concerns volunteers should be aware of</div>
            </div>

            <div class="vc-form-group">
                <label><i class="fas fa-gift"></i> Benefits for Volunteers</label>
                <textarea name="benefits" 
                        placeholder="e.g., Certificate provided, Meals included, Training provided" 
                        rows="3"><?= htmlspecialchars($opportunity['benefits'] ?? '') ?></textarea>
                <div class="vc-form-hint">What volunteers will gain from participating</div>
            </div>
        </div>

        <!-- SECTION 5: IMAGES -->
        <div class="vc-form-section">
            <div class="vc-section-header">
                <i class="fas fa-images"></i>
                <h2>Images</h2>
            </div>

            <!-- Current cover image preview -->
            <?php if (!empty($opportunity['image_url'])): ?>
                <div class="vc-current-image">
                    <label>Current Cover Image</label>
                    <div class="vc-image-preview">
                        <img src="<?= htmlspecialchars($opportunity['image_url']) ?>" 
                            alt="Current cover">
                        <div class="vc-image-actions">
                            <a href="<?= htmlspecialchars($opportunity['image_url']) ?>" 
                            target="_blank" class="vc-btn vc-btn-sm vc-btn-outline">
                                <i class="fas fa-expand"></i> View Full
                            </a>
                            <button type="button" class="vc-btn vc-btn-sm vc-btn-danger" id="removeCoverBtn">
                                <i class="fas fa-trash"></i> Remove
                            </button>
                        </div>
                    </div>
                    <input type="hidden" name="current_cover_image" 
                        value="<?= htmlspecialchars($opportunity['image_url']) ?>">
                </div>
            <?php endif; ?>

            <!-- New cover image upload -->
            <div class="vc-form-group">
                <label><i class="fas fa-image"></i> 
                    <?= empty($opportunity['image_url']) ? 'Cover Image' : 'Change Cover Image' ?>
                </label>
                <div class="vc-file-upload">
                    <input type="file" name="cover_image" accept="image/*" 
                        id="coverImage" class="vc-file-input">
                    <label for="coverImage" class="vc-file-label">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <span>Choose new cover image...</span>
                    </label>
                    <div class="vc-file-preview" id="coverPreview"></div>
                </div>
                <div class="vc-form-hint">Main image displayed in listings (max 5MB, JPG/PNG/GIF/WebP)</div>
            </div>

            <!-- Current additional images -->
            <?php if (!empty($additional_images)): ?>
                <div class="vc-current-additional-images">
                    <label>Current Additional Images</label>
                    <div class="vc-image-grid">
                        <?php foreach ($additional_images as $img): ?>
                            <div class="vc-image-item">
                                <img src="<?= htmlspecialchars($img['image_url']) ?>" 
                                    alt="Additional image">
                                <div class="vc-image-overlay">
                                    <a href="<?= htmlspecialchars($img['image_url']) ?>" 
                                    target="_blank" class="vc-btn vc-btn-sm vc-btn-outline">
                                        <i class="fas fa-expand"></i>
                                    </a>
                                    <button type="button" class="vc-btn vc-btn-sm vc-btn-danger remove-existing-image">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                                <input type="hidden" name="existing_images[]" value="<?= $img['image_url'] ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- New additional images upload -->
            <div class="vc-form-group">
                <label><i class="fas fa-images"></i> Additional Images (Optional, max 5)</label>
                <div class="vc-file-upload">
                    <input type="file" name="additional_images[]" accept="image/*" 
                        multiple id="additionalImages" class="vc-file-input">
                    <label for="additionalImages" class="vc-file-label">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <span>Choose up to 5 additional images...</span>
                    </label>
                    <div class="vc-file-preview" id="additionalPreview"></div>
                </div>
                <div class="vc-form-hint">Additional photos to showcase the opportunity</div>
            </div>
        </div>

        <!-- FORM ACTIONS -->
        <div class="vc-form-actions">
            <button type="submit" class="vc-btn vc-btn-primary vc-btn-lg">
                <i class="fas fa-save"></i> Save Changes
            </button>
            
            <a href="dashboard_org.php" class="vc-btn vc-btn-outline" onclick="return confirmCancel()">
                <i class="fas fa-arrow-left"></i> Cancel
            </a>
            
            <a href="applicants_manager.php?id=<?= $opportunity_id ?>" 
               class="vc-btn vc-btn-secondary">
                <i class="fas fa-users"></i> View Applicants
            </a>
            
            <div class="vc-form-progress">
                <div class="vc-progress-bar">
                    <div class="vc-progress-fill" id="formProgress"></div>
                </div>
                <div class="vc-progress-text">Form completion: <span id="progressText">0%</span></div>
            </div>
        </div>
    </form>
</div>

<script src="https://cdn.ckeditor.com/ckeditor5/41.0.0/classic/ckeditor.js"></script>
<script src="/volcon/assets/js/utils/scroll-to-top.js"></script>


<script>
    ClassicEditor
        .create(document.querySelector('#responsibilities'), {
            toolbar: {
                items: [
                    'bold', 'italic', 'link', '|',
                    'bulletedList', 'numberedList', '|',
                    'undo', 'redo'
                ],
                shouldNotGroupWhenFull: true
            },
            placeholder: 'e.g. Assist in event setup, Manage guest registrations...'
        })
        .then(editor => {
            // This ensures $_POST['responsibilities'] is always populated correctly
            editor.model.document.on('change:data', () => {
                document.querySelector('#responsibilities').value = editor.getData();
            });
        })
        .catch(error => {
            console.error('There was a problem initializing CKEditor 5:', error);
        });
</script>

<script>
new TomSelect('#preferredInterests', {
    plugins: ['remove_button'],
    maxItems: 10,

    valueField: 'value',
    labelField: 'text',
    searchField: 'text',

    create: function(input) {
        return {
            value: input,
            text: input
        };
    },

    persist: false,
});
</script>


<script>
new TomSelect('#requiredSkills', {
    plugins: ['remove_button'],
    maxItems: 10,
    persist: false
});
</script>



<script>
    window.OLD_STATE = "<?= htmlspecialchars($opportunity['state'] ?? '') ?>";
    window.OLD_CITY  = "<?= htmlspecialchars($opportunity['city'] ?? '') ?>";
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        function submitAction(action, message) {
            if (!confirm(message)) return;

            const form = document.getElementById('opportunityForm');
            const actionInput = form.querySelector('#formAction');

            if (!actionInput) {
                console.error('formAction input not found');
                return;
            }

            actionInput.value = action;
            form.submit();
        }

        document.getElementById('publishBtn')?.addEventListener('click', () => {
            submitAction(
                'publish',
                'Publish this opportunity? It will be visible to volunteers.'
            );
        });

        document.getElementById('closeBtn')?.addEventListener('click', () => {
            submitAction(
                'close',
                'Close this opportunity? It will stop receiving new applications.'
            );
        });

        document.getElementById('reopenBtn')?.addEventListener('click', () => {
            submitAction(
                'reopen',
                'Reopen this opportunity? It will start receiving applications again.'
            );
        });

        document.getElementById('markCompletedBtn')?.addEventListener('click', () => {
            submitAction(
                'complete',
                'Mark this opportunity as completed? This indicates all volunteer work has been already finished.'
            );
        });

        document.getElementById('duplicateBtn')?.addEventListener('click', () => {
            submitAction(
                'duplicate',
                'Create a duplicate of this opportunity as a draft?'
            );
        });
        
        // Contact persons management
        let contactCount = <?= count($contacts) ?>;
        
        document.getElementById('addContactBtn').addEventListener('click', function() {
            const container = document.getElementById('contactPersonsContainer');
            const newContact = document.createElement('div');
            newContact.className = 'vc-contact-person';
            newContact.dataset.index = contactCount;
            
            newContact.innerHTML = `
                <div class="vc-contact-header">
                    <h4>Contact Person #${contactCount + 1}</h4>
                    <button type="button" class="vc-btn vc-btn-sm vc-btn-danger remove-contact">
                        <i class="fas fa-trash"></i> Remove
                    </button>
                </div>
                
                <div class="vc-form-row-3">
                    <div class="vc-form-group">
                        <label>Name</label>
                        <input type="text" name="contact_name[]" placeholder="Full name">
                    </div>
                    <div class="vc-form-group">
                        <label>Email</label>
                        <input type="email" name="contact_email[]" placeholder="email@example.com">
                    </div>
                    <div class="vc-form-group">
                        <label>Phone</label>
                        <input type="tel" name="contact_phone[]" placeholder="+60 12-345 6789">
                    </div>
                </div>
                
                <div class="vc-form-group">
                    <label class="vc-checkbox-label">
                        <input type="radio" name="is_primary" value="${contactCount}" class="vc-primary-contact">
                        <span><i class="fas fa-star"></i> Set as Primary Contact</span>
                    </label>
                </div>
                
                <hr class="vc-contact-divider">
            `;
            
            container.appendChild(newContact);
            contactCount++;
            
            // Add remove functionality
            newContact.querySelector('.remove-contact').addEventListener('click', function() {
                newContact.remove();
                updateContactNumbers();
            });
        });
        
        // Remove existing contact persons
        document.querySelectorAll('.remove-contact').forEach(btn => {
            btn.addEventListener('click', function() {
                this.closest('.vc-contact-person').remove();
                updateContactNumbers();
            });
        });

        function confirmCancel() {
            return confirm("Are you sure you want to cancel and leave this page? Returning to dashboard.");
        }
        
        function updateContactNumbers() {
            document.querySelectorAll('.vc-contact-person').forEach((contact, index) => {
                const header = contact.querySelector('.vc-contact-header h4');
                if (header) {
                    header.textContent = `Contact Person #${index + 1}`;
                }
                
                // Update radio button value
                const radio = contact.querySelector('.vc-primary-contact');
                if (radio) {
                    radio.value = index;
                }
            });
        }
        
        // Remove cover image
        document.getElementById('removeCoverBtn')?.addEventListener('click', function() {
            if (confirm('Remove the current cover image?')) {
                // Hide the current image preview
                const currentImageSection = document.querySelector('.vc-current-image');
                if (currentImageSection) {
                    // Create hidden input to mark for removal
                    const removeInput = document.createElement('input');
                    removeInput.type = 'hidden';
                    removeInput.name = 'remove_cover_image';
                    removeInput.value = '1';
                    
                    // Replace the entire current image section
                    currentImageSection.innerHTML = `
                        <div class="vc-removed-notice">
                            <i class="fas fa-check-circle"></i> Cover image will be removed
                            ${removeInput.outerHTML}
                        </div>
                    `;
                    
                    // Also clear any new cover image preview
                    const coverPreview = document.getElementById('coverPreview');
                    if (coverPreview) {
                        coverPreview.innerHTML = '';
                    }
                    
                    // Clear the file input
                    const coverImageInput = document.getElementById('coverImage');
                    if (coverImageInput) {
                        coverImageInput.value = '';
                    }
                }
            }
        });

        // Remove existing additional images
        document.querySelectorAll('.remove-existing-image').forEach(btn => {
            btn.addEventListener('click', function() {
                const imageItem = this.closest('.vc-image-item');
                const imgId = imageItem.querySelector('input[name="existing_images[]"]')?.value;
                
                if (imgId && confirm('Remove this image?')) {
                    // Create hidden input to mark image for removal
                    const removeInput = document.createElement('input');
                    removeInput.type = 'hidden';
                    removeInput.name = 'remove_additional_images[]';
                    removeInput.value = imgId;
                    
                    // Add to form
                    document.getElementById('opportunityForm').appendChild(removeInput);
                    
                    // Remove from display
                    imageItem.remove();
                }
            });
        });

        function updateImageCount() {
            const existingCount = document.querySelectorAll('.vc-image-item').length;
            const newFilesInput = document.getElementById('additionalImages');
            const newFilesCount = newFilesInput ? newFilesInput.files.length : 0;
            
            // Update any count display if needed
            console.log(`Images: ${existingCount} existing + ${newFilesCount} new`);
        }

        // Image preview for additional images with limit
        const additionalImagesInput = document.getElementById('additionalImages');
        if (additionalImagesInput) {
            additionalImagesInput.addEventListener('change', function(e) {
                const previewContainer = document.getElementById('additionalPreview');
                const existingCount = document.querySelectorAll('.vc-image-item').length;
                const newFiles = Array.from(e.target.files);
                
                // Check total count
                if (existingCount + newFiles.length > 5) {
                    alert('Maximum 5 additional images allowed. You already have ' + existingCount + ' images.');
                    e.target.value = '';
                    return;
                }
                
                previewContainer.innerHTML = '';
                
                newFiles.forEach((file, index) => {
                    const reader = new FileReader();
                    
                    reader.onload = function(e) {
                        const previewItem = document.createElement('div');
                        previewItem.className = 'vc-preview-item';
                        
                        const img = document.createElement('img');
                        img.src = e.target.result;
                        img.alt = `Preview ${index + 1}`;
                        
                        const removeBtn = document.createElement('button');
                        removeBtn.type = 'button';
                        removeBtn.className = 'vc-preview-remove';
                        removeBtn.innerHTML = '×';
                        removeBtn.title = 'Remove image';
                        removeBtn.addEventListener('click', function() {
                            previewItem.remove();
                            removeFileFromInput(additionalImagesInput, index);
                        });
                        
                        previewItem.appendChild(img);
                        previewItem.appendChild(removeBtn);
                        previewContainer.appendChild(previewItem);
                    };
                    
                    reader.readAsDataURL(file);
                });
            });
        }
        
        function removeFileFromInput(input, indexToRemove) {
            const dt = new DataTransfer();
            const files = input.files;
            
            for (let i = 0; i < files.length; i++) {
                if (i !== indexToRemove) {
                    dt.items.add(files[i]);
                }
            }
            
            input.files = dt.files;
        }
        
        // Form validation for additional images count
        const opportunityForm = document.getElementById('opportunityForm');
        if (opportunityForm) {
            opportunityForm.addEventListener('submit', function(e) {
                const additionalImages = document.getElementById('additionalImages');
                const existingImages = document.querySelectorAll('.vc-image-item').length;
                
                if (additionalImages && additionalImages.files.length + existingImages > 5) {
                    e.preventDefault();
                    alert('Total images cannot exceed 5. Please remove some images.');
                    return false;
                }
                
                // Validate application deadline is not in past
                const deadlineInput = document.querySelector('input[name="application_deadline"]');
                if (deadlineInput && deadlineInput.value) {
                    const deadline = new Date(deadlineInput.value);
                    const now = new Date();
                    
                    if (deadline < now) {
                        e.preventDefault();
                        alert('Application deadline cannot be in the past.');
                        deadlineInput.focus();
                        return false;
                    }
                }
            });
        }
    });
</script>

<?php require_once __DIR__ . "/views/layout/footer.php"; ?>