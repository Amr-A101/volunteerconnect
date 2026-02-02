<?php
// variables available: $errors, $old
$old = $old ?? [];

if (empty($ec_text) && !empty($old['emergency_contacts'])) {
    // if view was shown via GET, convert associative contacts to lines
    $lines = [];
    foreach ($old['emergency_contacts'] as $n => $p) $lines[] = "{$n}: {$p}";
    $ec_text = implode("\n", $lines);
}
?>

<link rel="stylesheet" href="/volcon/assets/css/up_profile_vol.css">
<link rel="stylesheet" href="/volcon/assets/css/components/tom-select.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2/dist/css/tom-select.css">

<div class="vc-up-container">

    <div class="vc-up-header">
        <h1>Edit Profile</h1>
        <a href="profile_vol.php?id=<?= $volId ?>" class="vc-btn vc-btn-light">Cancel</a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="vc-up-errors">
            <?php foreach ($errors as $e): ?>
                <div><?= htmlspecialchars($e) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" action="update_profile_vol.php" enctype="multipart/form-data" class="vc-up-form" novalidate>

        <!-- PROFILE PICTURE -->
        <div class="vc-up-section">
            <label class="vc-up-label">Profile Picture</label>

            <div class="vc-up-avatar-wrapper">
                <img src="<?= htmlspecialchars($old['profile_picture'] ?? '/volcon/assets/uploads/default-avatar.png') ?>"
                     class="vc-up-avatar">

                <input type="file" name="profile_picture" accept="image/*" class="vc-up-input-file">
            </div>
        </div>

        <!-- NAME -->
        <div class="vc-up-row-2">
            <div>
                <label class="vc-up-label">First Name</label>
                <input type="text" name="first_name" class="vc-up-input"
                    value="<?= htmlspecialchars($old['first_name'] ?? '') ?>" required>
            </div>

            <div>
                <label class="vc-up-label">Last Name</label>
                <input type="text" name="last_name" class="vc-up-input"
                    value="<?= htmlspecialchars($old['last_name'] ?? '') ?>" required>
            </div>
        </div>

        <!-- AVAILABILITY -->
        <div class="vc-up-row">
            <div>
                <label class="vc-up-label">Availability</label>
                <select name="availability" class="vc-up-select">
                    <option value="flexible" <?= (($old['availability'] ?? '') === 'flexible') ? 'selected' : '' ?>>Flexible</option>
                    <option value="weekdays" <?= (($old['availability'] ?? '') === 'weekdays') ? 'selected' : '' ?>>Weekdays</option>
                    <option value="weekends" <?= (($old['availability'] ?? '') === 'weekends') ? 'selected' : '' ?>>Weekends</option>
                    <option value="part-time" <?= (($old['availability'] ?? '') === 'part-time') ? 'selected' : '' ?>>Part-time</option>
                </select>
            </div>
        </div>

        <!-- LOCATION -->
        <div class="vc-up-row-3">
            <div>
                <label class="vc-up-label">State</label>
                <select id="state_vol" name="state" class="vc-up-select" required>
                    <option value="">-- Select State --</option>
                </select>
            </div>

            <div>
                <label class="vc-up-label">Town/Area</label>
                <select id="city_vol" name="city" class="vc-up-select" required>
                    <option value="">-- Select Town or Area --</option>
                </select>
            </div>

            <div>
                <label class="vc-up-label">Country</label>
                <select id="country_vol" name="country" class="vc-up-select">
                    <option value="Malaysia" <?= (($old['country'] ?? '') === 'Malaysia') ? 'selected' : '' ?>>Malaysia</option>
                </select>
            </div>
        </div>

        <!-- PHONE + DOB -->
        <div class="vc-up-row-2">
            <div>
                <label class="vc-up-label">Phone Number</label>
                <input type="text" name="phone_no" class="vc-up-input"
                    value="<?= htmlspecialchars($old['phone_no'] ?? '') ?>" required>
            </div>

            <div>
                <label class="vc-up-label">Birthdate</label>
                <input type="date" name="birthdate" class="vc-up-input"
                    value="<?= htmlspecialchars($old['birthdate'] ?? '') ?>" required>
            </div>
        </div>

        <!-- BIO -->
        <div class="vc-up-section">
            <label class="vc-up-label">Bio</label>
            <textarea name="bio" class="vc-up-textarea"><?= htmlspecialchars($old['bio'] ?? '') ?></textarea>
        </div>

        <!-- SKILLS -->
        <div class="vc-up-section">
            <label class="vc-up-label">Skills</label>
            <select id="skills" name="skills[]" multiple class="vc-up-multiselect">
                <?php foreach ($old['allSkills'] as $s): ?>
                    <option value="<?= $s['skill_id'] ?>" <?= in_array($s['skill_id'], $old['userSkillIds']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['skill_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- INTERESTS -->
        <div class="vc-up-section">
            <label class="vc-up-label">Interests</label>
            <select id="interests" name="interests[]" multiple class="vc-up-multiselect">
                <?php foreach ($old['allInterests'] as $i): ?>
                    <option value="<?= $i['interest_id'] ?>" <?= in_array($i['interest_id'], $old['userInterestIds']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($i['interest_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- EMERGENCY CONTACTS -->
        <div class="vc-up-section">
            <label class="vc-up-label">Emergency Contacts</label>

            <div id="ec_list" class="vc-up-ec-list">
                <?php if (!empty($old['emergency_contacts'])): ?>
                    <?php foreach ($old['emergency_contacts'] as $name => $phone): ?>
                        <div class="vc-up-ec-row">
                            <input type="text" name="ec_name[]" class="vc-up-input" placeholder="Name" value="<?= htmlspecialchars($name) ?>" required>
                            <input type="text" name="ec_phone[]" class="vc-up-input" placeholder="Phone" value="<?= htmlspecialchars($phone) ?>" required>
                            <button type="button" class="vc-btn-ec-remove">×</button>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="vc-up-ec-row">
                        <input type="text" name="ec_name[]" class="vc-up-input" placeholder="Name">
                        <input type="text" name="ec_phone[]" class="vc-up-input" placeholder="Phone">
                        <button type="button" class="vc-btn-ec-remove">×</button>
                    </div>
                <?php endif; ?>
            </div>

            <button type="button" id="vc-btn-add-ec" class="vc-btn vc-btn-secondary">Add Contact</button>
        </div>

        <!-- SUBMIT -->
        <div class="vc-up-submit">
            <button type="submit" class="vc-btn vc-btn-primary">Save Changes</button>
        </div>

    </form>
</div>

<script>
    window.OLD_STATE = "<?= htmlspecialchars($old['state'] ?? '') ?>";
    window.OLD_CITY  = "<?= htmlspecialchars($old['city'] ?? '') ?>";
</script>

<script src="https://cdn.jsdelivr.net/npm/tom-select@2/dist/js/tom-select.complete.min.js"></script>
<script src="/volcon/assets/js/utils/location_selector.js"></script>

<script type="module" src="/volcon/assets/js/utils/validators.js"></script>
<script type="module" src="/volcon/assets/js/utils/form_utils.js"></script>

<script type="module" src="/volcon/assets/js/update/update_profile_vol.js"></script>
