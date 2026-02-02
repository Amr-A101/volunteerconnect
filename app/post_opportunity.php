<?php

$page_title = "Post Opportunity";
require_once __DIR__ . "/views/layout/header.php";

// Must be logged in as organization
$user = current_user();
if (!$user || $user['role'] !== 'org') {
    header("Location: login.php");
    exit;
}

$org_id = $user['user_id'];
$errors = [];
$form_data = [];

// Fetch skills and interests for dropdowns
$skills = [];
$interests = [];
$skills_result = $dbc->query("SELECT skill_id, skill_name FROM skills ORDER BY skill_name");
$interests_result = $dbc->query("SELECT interest_id, interest_name FROM interests ORDER BY interest_name");

if ($skills_result) {
    $skills = $skills_result->fetch_all(MYSQLI_ASSOC);
}
if ($interests_result) {
    $interests = $interests_result->fetch_all(MYSQLI_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- Sanitize inputs ---
    $form_data = [
        'title' => trim($_POST['title'] ?? ''),
        'brief_summary' => trim($_POST['brief_summary'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'responsibilities' => trim($_POST['responsibilities'] ?? ''),
        'location_name' => trim($_POST['location_name'] ?? ''),
        'city' => trim($_POST['city'] ?? ''),
        'state' => trim($_POST['state'] ?? ''),
        'postcode' => trim($_POST['postcode'] ?? ''),
        'country' => trim($_POST['country'] ?? 'Malaysia'),
        'start_date' => $_POST['start_date'] ?? null,
        'end_date' => $_POST['end_date'] ?? null,
        'start_time' => $_POST['start_time'] ?? null,
        'end_time' => $_POST['end_time'] ?? null,
        'application_deadline' => $_POST['application_deadline'] ?? null,
        'number_of_volunteers' => intval($_POST['number_of_volunteers'] ?? 1),
        'min_age' => !empty($_POST['min_age']) ? intval($_POST['min_age']) : null,
        'required_skills' => $_POST['required_skills'] ?? [],
        'preferred_interests' => $_POST['preferred_interests'] ?? [],
        'requirements' => trim($_POST['requirements'] ?? ''),
        'benefits' => trim($_POST['benefits'] ?? ''),
        'safety_notes' => trim($_POST['safety_notes'] ?? ''),
        'transportation_info' => trim($_POST['transportation_info'] ?? ''),
        'status' => $_POST['save_as_draft'] ? 'draft' : 'open'
    ];

    $form_data['responsibilities'] = strip_tags($form_data['responsibilities'], '<p><ul><ol><li><strong><em><a>');

    if (strlen($form_data['responsibilities']) < 10) {
        $errors[] = "Responsibilities must be at least 10 characters long.";
    }

    // --- Validation ---
    if (empty($form_data['title'])) $errors[] = "Title is required.";
    if (empty($form_data['brief_summary'])) $errors[] = "Brief summary is required.";
    if (empty($form_data['description'])) $errors[] = "Description is required.";
    
    if (empty($form_data['responsibilities'])) {
        $errors[] = "Responsibilities are required.";
    }

    if (empty($form_data['city'])) $errors[] = "City is required.";
    if (empty($form_data['state'])) $errors[] = "State is required.";
    if (empty($form_data['postcode'])) $errors[] = "Postcode is required.";
    if ($form_data['number_of_volunteers'] < 1) $errors[] = "Number of volunteers must be at least 1.";
    
    if (!empty($form_data['min_age']) && ($form_data['min_age'] < 16 || $form_data['min_age'] > 100)) {
        $errors[] = "Minimum age must be between 16 and 100.";
    }

    $contact_persons = [];
    if (isset($_POST['contact_name']) && is_array($_POST['contact_name'])) {
        foreach ($_POST['contact_name'] as $index => $name) {
            $name = trim($name);
            $email = trim($_POST['contact_email'][$index] ?? '');
            $phone = trim($_POST['contact_phone'][$index] ?? '');
            $is_primary = isset($_POST['is_primary']) && $_POST['is_primary'] == $index;
            
            if (!empty($name)) {
                // Validate email if provided
                if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "Invalid email format for contact person #" . ($index + 1);
                }
                
                // Validate phone if provided
                if (!empty($phone)) {
                    $clean_phone = str_replace([' ', '-'], '', $phone);
                    if (!preg_match('/^(?:\+?60|0)(?:1[0-9]\d{7,8}|[3-9][0-9]\d{7})$/', $clean_phone)) {
                        $errors[] = "Please enter a valid Malaysian phone number for contact person #" . ($index + 1);
                    }
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
    
    // Date validation
    $today = date('Y-m-d');
    if (!empty($form_data['start_date']) && $form_data['start_date'] < $today) {
        $errors[] = "Start date cannot be in the past.";
    }
    
    if (!empty($form_data['start_date']) && !empty($form_data['end_date']) && $form_data['end_date'] < $form_data['start_date']) {
        $errors[] = "End date cannot be earlier than start date.";
    }
    
    if (!empty($form_data['application_deadline']) && $form_data['application_deadline'] < $today) {
        $errors[] = "Application deadline cannot be in the past.";
    }
    
    // Time validation
    if (!empty($form_data['start_time']) && !empty($form_data['end_time'])) {
        if ($form_data['start_date'] == $form_data['end_date'] && $form_data['end_time'] <= $form_data['start_time']) {
            $errors[] = "End time must be later than start time on the same day.";
        }
    }

    // --- Handle cover image upload ---
    $image_url = $image_url ?? null;

    if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['cover_image'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Error uploading cover image: " . getUploadError($file['error']);
        } else {
            $result = uploadImage($file, $org_id, 'cover');

            if ($result['success']) {
                $image_url = $result['path'];
            } else {
                $errors[] = $result['error'];
            }
        }
    }

    // --- Handle additional images ---
    $additional_images = [];

    if (isset($_FILES['additional_images']) && !empty($_FILES['additional_images']['name'][0])) {
        
        $files = $_FILES['additional_images'];
        
        foreach ($files['tmp_name'] as $index => $tmp_name) {
            if ($files['error'][$index] === UPLOAD_ERR_OK && $tmp_name) {
                $file = [
                    'name' => $files['name'][$index],
                    'type' => $files['type'][$index],
                    'tmp_name' => $tmp_name,
                    'error' => $files['error'][$index],
                    'size' => $files['size'][$index]
                ];
                
                $upload_result = uploadImage($file, $org_id);
                
                if ($upload_result['success']) {
                    $additional_images[] = $upload_result['path'];
                }
            }
        }
        
        if (count($_FILES['additional_images']['name']) > 5) {
            $errors[] = "Maximum 5 additional images allowed.";
        }
    }

    // --- Insert into DB ---
    if (empty($errors)) {
        $dbc->begin_transaction();
        
        try {
            // Insert opportunity
            $stmt = $dbc->prepare("
                INSERT INTO opportunities 
                (org_id, title, brief_summary, description, responsibilities, location_name, city, state, postcode, country,
                start_date, end_date, start_time, end_time, application_deadline,
                number_of_volunteers, min_age, requirements, benefits, safety_notes, transportation_info,
                image_url, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->bind_param(
                "isssssssssssssiisssssss",

                $org_id,
                $form_data['title'],                   
                $form_data['brief_summary'],          
                $form_data['description'],     
                $form_data['responsibilities'],       
                $form_data['location_name'],          
                $form_data['city'],                   
                $form_data['state'],                  
                $form_data['postcode'],               
                $form_data['country'],                
                $form_data['start_date'],
                $form_data['end_date'],
                $form_data['start_time'],
                $form_data['end_time'],  
                $form_data['application_deadline'],
                $form_data['number_of_volunteers'],
                $form_data['min_age'],
                $form_data['requirements'],
                $form_data['benefits'],
                $form_data['safety_notes'],
                $form_data['transportation_info'],
                $image_url,
                $form_data['status']   
            );

            if (!$stmt->execute()) {
                throw new Exception("Failed to create opportunity: " . $stmt->error);
            }
            
            $opportunity_id = $stmt->insert_id;
            $stmt->close();

            // Insert contact persons
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

            // Insert required skills
            if (!empty($form_data['required_skills'])) {
                $skill_stmt = $dbc->prepare("
                    INSERT INTO opportunity_skills (opportunity_id, skill_id) 
                    VALUES (?, ?)
                ");
                
                foreach ($form_data['required_skills'] as $skill_value) {
                    // Check if skill_value is numeric (existing skill) or string (new skill)
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

            // Insert preferred interests
            if (!empty($form_data['preferred_interests'])) {
                $interest_stmt = $dbc->prepare("
                    INSERT INTO opportunity_interests (opportunity_id, interest_id) 
                    VALUES (?, ?)
                ");
                
                foreach ($form_data['preferred_interests'] as $interest_value) {
                    // Check if interest_value is numeric (existing interest) or string (new interest)
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

            // Insert additional images
            if (!empty($additional_images)) {
                $img_stmt = $dbc->prepare("
                    INSERT INTO opportunity_images (opportunity_id, image_url) 
                    VALUES (?, ?)
                ");
                
                foreach ($additional_images as $image_url) {
                    $img_stmt->bind_param("is", $opportunity_id, $image_url);
                    $img_stmt->execute();
                }
                $img_stmt->close();
            }

            $dbc->commit();
            
            $message = $form_data['status'] === 'draft' 
                ? "Opportunity saved as draft." 
                : "Opportunity posted successfully!";
            
            flash('success', $message);
            header("Location: dashboard_org.php");
            exit;

        } catch (Exception $e) {
            $dbc->rollback();
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

// Helper functions
function getUploadError($error_code) {
    $errors = [
        UPLOAD_ERR_INI_SIZE => 'File size exceeds server limit.',
        UPLOAD_ERR_FORM_SIZE => 'File size exceeds form limit.',
        UPLOAD_ERR_PARTIAL => 'File upload was incomplete.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION => 'File upload stopped by extension.'
    ];
    return $errors[$error_code] ?? 'Unknown upload error.';
}

function uploadImage(array $file, int $org_id, string $type = 'additional') : array
{
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp'
    ];

    $mime = mime_content_type($file['tmp_name']);

    if (!isset($allowed[$mime])) {
        return ['success' => false, 'error' => "Only JPG, PNG, GIF or WebP images allowed."];
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        return ['success' => false, 'error' => "File size must be less than 5MB."];
    }

    /* ===============================
       FILESYSTEM PATH (REAL PATH)
       =============================== */

    // Project root = /volcon
    $projectRoot = realpath(__DIR__ . '/..'); 
    // e.g. /var/www/html/volcon

    $uploadDir = $projectRoot . "/assets/uploads/opps/org_{$org_id}";

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        return ['success' => false, 'error' => 'Failed to create upload directory.'];
    }

    /* ===============================
       FILE NAMING
       =============================== */

    $ext = $allowed[$mime];
    $unique = time() . '_' . bin2hex(random_bytes(8));

    $filename = ($type === 'cover')
        ? "cover_{$unique}.{$ext}"
        : "{$unique}.{$ext}";

    $dest = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['success' => false, 'error' => 'Failed to save uploaded image.'];
    }

    /* ===============================
       URL PATH (FOR DB)
       =============================== */

    $urlPath = "/volcon/assets/uploads/opps/org_{$org_id}/{$filename}";

    return [
        'success' => true,
        'path'    => $urlPath
    ];
}


?>

<link rel="stylesheet" href="/volcon/assets/css/opportunity_form.css">
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.css" rel="stylesheet">

<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
<script src="/volcon/assets/js/utils/location_selector.js"></script>
<script src="/volcon/assets/js/form_opportunity.js" defer></script>

<div class="vc-form-container">

    <div class="vc-form-header">
        <h1><i class="fas fa-plus-circle"></i> Post New Opportunity</h1>
        <p class="vc-form-subtitle">Fill in the details below to create a new volunteer opportunity</p>
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

    <form method="post" enctype="multipart/form-data" class="vc-form" id="opportunityForm">
        <input type="hidden" name="save_as_draft" id="saveAsDraft" value="0">

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
                       value="<?= htmlspecialchars($form_data['title'] ?? '') ?>" 
                       placeholder="e.g., Beach Cleanup Volunteer" 
                       maxlength="200" required>
                <div class="vc-form-hint">Clear, concise title that attracts volunteers</div>
            </div>

            <div class="vc-form-group">
                <label class="required">
                    <i class="fas fa-file-alt"></i> Brief Summary
                </label>
                <textarea name="brief_summary" 
                          placeholder="Brief summary (max 150 characters)" 
                          maxlength="150" 
                          required><?= htmlspecialchars($form_data['brief_summary'] ?? '') ?></textarea>
                <div class="vc-form-hint">Short summary that appears in opportunity listings</div>
            </div>

            <div class="vc-form-group">
                <label class="required">
                    <i class="fas fa-align-left"></i> Full Description
                </label>
                <textarea name="description" 
                          placeholder="Detailed description of the opportunity, tasks, impact, etc." 
                          rows="6" required><?= htmlspecialchars($form_data['description'] ?? '') ?></textarea>
                <div class="vc-form-hint">Provide detailed information about the opportunity</div>
            </div>
            <div class="vc-form-group">
                <label for="responsibilities" style="display: block; margin-bottom: 8px; font-weight: 600;">Responsibilities</label>

                <textarea name="responsibilities" id="responsibilities">
                    <?= htmlspecialchars($_POST['responsibilities'] ?? '') ?>
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
                       value="<?= htmlspecialchars($form_data['location_name'] ?? '') ?>" 
                       placeholder="e.g., Taman Negara Community Center">
            </div>

            <div class="vc-form-row-2">
                <div class="vc-form-group">
                    <label class="required"><i class="fas fa-landmark"></i> State</label>
                    <select id="state_org" name="state" class="vc-form-control" required>
                        <option value="">-- Select State --</option>
                    </select>
                </div>
                <div class="vc-form-group">
                    <label class="required"><i class="fas fa-city"></i> Area/Town</label>
                    <select id="city_org" name="city" class="vc-form-control" required>
                        <option value="">-- Select Town or Area --</option>
                    </select>
                </div>
            </div>

            <div class="vc-form-row-2">
                <div class="vc-form-group">
                    <label class="required"><i class="fas fa-location-dot"></i> Postcode</label>
                    <input type="text" name="postcode" 
                       value="<?= htmlspecialchars($form_data['postcode'] ?? '') ?>" 
                       placeholder="e.g., 50350" required>
                </div>
                <div class="vc-form-group">
                    <label class="required"><i class="fas fa-globe-asia"></i> Country</label>
                    <select name="country" class="vc-form-control" required>
                        <option value="Malaysia" <?= ($form_data['country'] ?? 'Malaysia') == 'Malaysia' ? 'selected' : '' ?>>Malaysia</option>
                    </select>
                </div>
            </div>

            <div class="vc-form-row-2">
                <div class="vc-form-group">
                    <label><i class="fas fa-calendar-day"></i> Start Date</label>
                    <input type="date" name="start_date" 
                           value="<?= htmlspecialchars($form_data['start_date'] ?? '') ?>"
                           min="<?= date('Y-m-d') ?>">
                    <div class="vc-form-hint">Leave blank for flexible dates</div>
                </div>
                <div class="vc-form-group">
                    <label><i class="fas fa-calendar-day"></i> End Date</label>
                    <input type="date" name="end_date" 
                           value="<?= htmlspecialchars($form_data['end_date'] ?? '') ?>"
                           min="<?= date('Y-m-d') ?>">
                </div>
            </div>

            <div class="vc-form-row-2">
                <div class="vc-form-group">
                    <label><i class="fas fa-clock"></i> Start Time</label>
                    <input type="time" name="start_time" 
                           value="<?= htmlspecialchars($form_data['start_time'] ?? '') ?>">
                </div>
                <div class="vc-form-group">
                    <label><i class="fas fa-clock"></i> End Time</label>
                    <input type="time" name="end_time" 
                           value="<?= htmlspecialchars($form_data['end_time'] ?? '') ?>">
                </div>
            </div>

            <div class="vc-form-group">
                <label><i class="fas fa-hourglass-end"></i> Application Deadline</label>
                <input type="datetime-local" name="application_deadline" 
                       value="<?= htmlspecialchars($form_data['application_deadline'] ?? '') ?>"
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
                    <label class="required"><i class="fas fa-users"></i> Volunteers Needed</label>
                    <input type="number" name="number_of_volunteers" 
                           value="<?= $form_data['number_of_volunteers'] ?? 1 ?>" 
                           min="1" max="1000" required>
                </div>
                <div class="vc-form-group">
                    <label><i class="fas fa-birthday-cake"></i> Minimum Age</label>
                    <input type="number" name="min_age" 
                           value="<?= $form_data['min_age'] ?? '' ?>" 
                           min="16" max="100" placeholder="16">
                    <div class="vc-form-hint">Leave blank if no age restriction</div>
                </div>
            </div>

            <!-- Required Skills Section -->
            <div class="vc-form-group">
                <label><i class="fas fa-tools"></i> Required Skills (Optional)</label>
                <select name="required_skills[]" 
                        id="requiredSkills" 
                        placeholder="Search and select skills..." 
                        multiple>
                    <?php foreach ($skills as $skill): ?>
                        <option value="<?= $skill['skill_id'] ?>" 
                                <?= in_array($skill['skill_id'], $form_data['required_skills'] ?? []) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($skill['skill_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="vc-form-hint">Type to search or select from the list (max 10 skills)</div>
            </div>

            <!-- Preferred Interests Section -->
            <div class="vc-form-group">
                <label><i class="fas fa-heart"></i> Preferred Interests (Optional)</label>
                <select name="preferred_interests[]" 
                        id="preferredInterests" 
                        placeholder="Search and select interests..." 
                        multiple>
                    <?php foreach ($interests as $interest): ?>
                        <option value="<?= $interest['interest_id'] ?>" 
                                <?= in_array($interest['interest_id'], $form_data['preferred_interests'] ?? []) ? 'selected' : '' ?>>
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
                          rows="3"><?= htmlspecialchars($form_data['requirements'] ?? '') ?></textarea>
            </div>

            <div class="vc-form-group">
                <label><i class="fas fa-car"></i> Transportation Information</label>
                <textarea name="transportation_info" 
                          placeholder="Parking availability, public transport access, carpooling options" 
                          rows="3"><?= htmlspecialchars($form_data['transportation_info'] ?? '') ?></textarea>
            </div>
        </div>

        <!-- SECTION 4: CONTACT & SAFETY -->
        <div class="vc-form-section">
            <div class="vc-section-header">
                <i class="fas fa-address-book"></i>
                <h2>Contact & Additional Information</h2>
            </div>
            
            <div class="vc-form-hint" style="margin-bottom: 20px;">
                <i class="fas fa-info-circle"></i> Add one or more contact persons for this opportunity
            </div>
            
            <div id="contactPersonsContainer">
                <!-- Default first contact -->
                <div class="vc-contact-person" data-index="0">
                    <div class="vc-contact-header">
                        <h4>Contact Person #1</h4>
                    </div>
                    
                    <div class="vc-form-row-3">
                        <div class="vc-form-group">
                            <label class="required">
                                <i class="fas fa-user"></i> Name
                            </label>
                            <input type="text" 
                                name="contact_name[]" 
                                placeholder="Full name"
                                required>
                        </div>
                        <div class="vc-form-group">
                            <label><i class="fas fa-envelope"></i> Email</label>
                            <input type="email" 
                                name="contact_email[]" 
                                placeholder="email@example.com">
                        </div>
                        <div class="vc-form-group">
                            <label><i class="fas fa-phone"></i> Phone</label>
                            <input type="tel" 
                                name="contact_phone[]" 
                                placeholder="+60 12-345 6789">
                        </div>
                    </div>
                    
                    <div class="vc-form-group">
                        <label class="vc-checkbox-label">
                            <input type="radio" 
                                name="is_primary" 
                                value="0"
                                checked
                                class="vc-primary-contact">
                            <span><i class="fas fa-star"></i> Set as Primary Contact</span>
                        </label>
                        <div class="vc-form-hint">
                            Primary contact will be displayed to volunteers
                        </div>
                    </div>
                </div>
            </div>
            
            <button type="button" id="addContactBtn" class="vc-btn vc-btn-outline">
                <i class="fas fa-plus"></i> Add Another Contact Person
            </button><br><br>

            <div class="vc-form-group">
                <label><i class="fas fa-exclamation-triangle"></i> Safety Notes & Warnings</label>
                <textarea name="safety_notes" 
                          placeholder="Important safety information, risks, required precautions" 
                          rows="3"><?= htmlspecialchars($form_data['safety_notes'] ?? '') ?></textarea>
                <div class="vc-form-hint">Any safety concerns volunteers should be aware of</div>
            </div>

            <div class="vc-form-group">
                <label><i class="fas fa-gift"></i> Benefits for Volunteers</label>
                <textarea name="benefits" 
                          placeholder="e.g., Certificate provided, Meals included, Training provided" 
                          rows="3"><?= htmlspecialchars($form_data['benefits'] ?? '') ?></textarea>
                <div class="vc-form-hint">What volunteers will gain from participating</div>
            </div>
        </div>

        <!-- SECTION 5: IMAGES -->
        <div class="vc-form-section">
            <div class="vc-section-header">
                <i class="fas fa-images"></i>
                <h2>Images</h2>
            </div>

            <div class="vc-form-group">
                <label><i class="fas fa-image"></i> Cover Image (Recommended)</label>
                <div class="vc-file-upload">
                    <input type="file" name="cover_image" accept="image/*" 
                           id="coverImage" class="vc-file-input">
                    <label for="coverImage" class="vc-file-label">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <span style="margin-left: 8px;">Choose cover image...</span>
                    </label>
                    <div class="vc-file-preview" id="coverPreview"></div>
                </div>
                <div class="vc-form-hint">Main image displayed in listings (max 5MB, JPG/PNG/GIF/WebP)</div>
            </div>

            <div class="vc-form-group">
                <label><i class="fas fa-images"></i> Additional Images (Optional, max 5)</label>
                <div class="vc-file-upload">
                    <input type="file" name="additional_images[]" accept="image/*" 
                           multiple id="additionalImages" class="vc-file-input">
                    <label for="additionalImages" class="vc-file-label">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <span style="margin-left: 8px;">Choose up to 5 images...</span>
                    </label>
                    <div class="vc-file-preview" id="additionalPreview"></div>
                </div>
                <div class="vc-form-hint">Additional photos to showcase the opportunity</div>
            </div>
        </div>

        <!-- FORM ACTIONS -->
        <div class="vc-form-actions">
            <button type="submit" class="vc-btn vc-btn-primary vc-btn-lg">
                <i class="fas fa-paper-plane"></i> Publish Opportunity
            </button>
            
            <button type="button" id="saveDraftBtn" class="vc-btn vc-btn-secondary vc-btn-lg">
                <i class="fas fa-save"></i> Save as Draft
            </button>
            
            <a href="dashboard_org.php" class="vc-btn vc-btn-outline">
                <i class="fas fa-arrow-left"></i> Cancel
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
    var OLD_STATE  = "<?= $form_data['state'] ?? '' ?>";
    var OLD_CITY   = "<?= $form_data['city'] ?? '' ?>";
</script>

<script>
    // Contact persons management
    let contactCount = 1;

    document.getElementById('addContactBtn')?.addEventListener('click', function() {
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

    // Update contact numbers when removing
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
</script>

<?php require_once __DIR__ . "/views/layout/footer.php"; ?>