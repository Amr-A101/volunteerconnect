<?php
// app/views/org/update_profile_org_view.php
// Variables expected from controller: $errors, $old, $userId
$old = $old ?? [];
$old_contact = $old['contact_info'] ?? [];
$old_links   = $old['external_links'] ?? [];
$old_docs    = $old['document_paths'] ?? [];

?>
<link rel="stylesheet" href="/volcon/assets/css/up_profile_org.css">
<link rel="stylesheet" href="/volcon/assets/css/components/tom-select.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2/dist/css/tom-select.css">

<div class="vc-up-container">

    <div class="vc-up-header">
        <h1>Edit Organization Profile</h1>
        <a href="profile_org.php?id=<?= intval($orgId) ?>" class="vc-btn vc-btn-light">Cancel</a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="vc-up-errors">
            <?php foreach ($errors as $e): ?>
                <div><?= htmlspecialchars($e) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" action="update_profile_org.php" enctype="multipart/form-data" class="vc-up-form" novalidate>

        <!-- PROFILE PICTURE -->
        <div class="vc-up-section">
            <label class="vc-up-label">Profile Picture</label>
            <div class="vc-up-avatar-wrapper">
                <img src="<?= htmlspecialchars($old['profile_picture'] ?? '/volcon/assets/uploads/default-org.png') ?>"
                     class="vc-up-avatar">
                <input type="file" name="profile_picture" accept="image/*" class="vc-up-input-file">
            </div>
        </div>

        <!-- BASIC INFO -->
        <div class="vc-up-section">
            <label class="vc-up-label" for="name">Organization Name</label>
            <input 
                type="text" 
                name="name" 
                id="name"
                class="vc-up-input" 
                value="<?= htmlspecialchars($old['name'] ?? '') ?>" 
                required
                placeholder="Enter your organization's name">
        </div>

        <div class="vc-up-section vc-up-row">
            <div>
                <label class="vc-up-label" for="mission">Mission</label>
                <textarea 
                    name="mission" 
                    id="mission" 
                    class="vc-up-textarea" 
                    required
                    placeholder="Describe your organization's mission..."><?= htmlspecialchars($old['mission'] ?? '') ?></textarea>
            </div>
            <div>
                <label class="vc-up-label" for="description">Description</label>
                <textarea 
                    name="description" 
                    id="description" 
                    class="vc-up-textarea" 
                    required
                    placeholder="Provide a brief description of your organization..."><?= htmlspecialchars($old['description'] ?? '') ?></textarea>
            </div>
        </div>


        <!-- ADDRESS: uses same location_selector.js (states/cities) -->
        <div class="vc-up-row-3">
            <div>
                <label class="vc-up-label">State</label>
                <select id="state_org" name="state" class="vc-up-select" required>
                    <option value="">-- Select State --</option>
                </select>
            </div>

            <div>
                <label class="vc-up-label">Town/Area</label>
                <select id="city_org" name="city" class="vc-up-select" required>
                    <option value="">-- Select Town or Area --</option>
                </select>
            </div>

            <div>
                <label class="vc-up-label">Country</label>
                <select id="country_org" name="country" class="vc-up-select">
                    <option value="Malaysia" <?= (($old['country'] ?? '') === 'Malaysia') ? 'selected' : '' ?>>Malaysia</option>
                </select>
            </div>
        </div>

        <div class="vc-up-row-2">
            <div>
                <label class="vc-up-label">Street Address</label>
                <input type="text" name="address" class="vc-up-input" value="<?= htmlspecialchars($old['address'] ?? '') ?>">
            </div>

            <div>
                <label class="vc-up-label">Postcode</label>
                <input type="text" name="postcode" class="vc-up-input" value="<?= htmlspecialchars($old['postcode'] ?? '') ?>">
            </div>
        </div>

        <!-- CONTACT INFORMATION -->
        <div class="vc-up-section">
            <label class="vc-up-label">Contact Information</label>
            <div class="vc-up-error-message"></div>

            <div id="contact_list" class="vc-up-ec-list">

                <?php
                $contactOptions = [
                    "phone"        => "Phone Number",
                    "email"        => "Email Address",
                    "whatsapp"     => "WhatsApp Number",
                    "landline"     => "Office Landline",
                    "fax"          => "Fax",
                    "contact_form" => "Website Contact Form"
                ];
                ?>

                <?php if (!empty($old_contact)): ?>
                    <?php foreach ($old_contact as $key => $val): ?>
                        <div class="vc-up-ec-row">
                            <select name="contact_key[]" class="vc-up-select">
                                <?php foreach ($contactOptions as $optKey => $label): ?>
                                    <option value="<?= $optKey ?>" <?= ($key === $optKey ? "selected" : "") ?>>
                                        <?= $label ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <input type="text" name="contact_value[]" class="vc-up-input"
                                placeholder="Enter value..." value="<?= htmlspecialchars($val) ?>">

                            <button type="button" class="vc-btn-ec-remove">×</button>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="vc-up-ec-row">
                        <select name="contact_key[]" class="vc-up-select">
                            <?php foreach ($contactOptions as $optKey => $label): ?>
                                <option value="<?= $optKey ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>

                        <input type="text" name="contact_value[]" class="vc-up-input" placeholder="Enter value...">
                        <button type="button" class="vc-btn-ec-remove">×</button>
                    </div>
                <?php endif; ?>

            </div>

            <button type="button" id="vc-btn-add-contact" class="vc-btn vc-btn-secondary">Add Contact</button>
        </div>

        <!-- EXTERNAL LINKS -->
        <div class="vc-up-section">
            <label class="vc-up-label">External Links</label>
            <div class="vc-up-error-message"></div>

            <?php
            $linkOptions = [
                "website"      => "Official Website",
                "facebook"     => "Facebook Page",
                "instagram"    => "Instagram",
                "linkedin"     => "LinkedIn",
                "tiktok"       => "TikTok",
                "youtube"      => "YouTube Channel",
                "twitter"      => "X / Twitter",
                "donation"     => "Donation Page",
                "blog"         => "Blog / News Page"
            ];
            ?>

            <div id="links_list" class="vc-up-ec-list">

                <?php if (!empty($old_links)): ?>
                    <?php foreach ($old_links as $key => $url): ?>
                        <div class="vc-up-ec-row">

                            <select name="link_key[]" class="vc-up-select">
                                <?php foreach ($linkOptions as $optKey => $label): ?>
                                    <option value="<?= $optKey ?>" <?= ($key === $optKey ? "selected" : "") ?>>
                                        <?= $label ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <input type="url" name="link_url[]" class="vc-up-input"
                                placeholder="https://..." value="<?= htmlspecialchars($url) ?>">

                            <button type="button" class="vc-btn-ec-remove">×</button>

                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="vc-up-ec-row">

                        <select name="link_key[]" class="vc-up-select">
                            <?php foreach ($linkOptions as $optKey => $label): ?>
                                <option value="<?= $optKey ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>

                        <input type="url" name="link_url[]" class="vc-up-input" placeholder="https://...">

                        <button type="button" class="vc-btn-ec-remove">×</button>

                    </div>
                <?php endif; ?>

            </div>

            <button type="button" id="vc-btn-add-link" class="vc-btn vc-btn-secondary">Add Link</button>
        </div>

        
        <!-- DOCUMENTS (multiple) -->
        <div class="vc-up-section">
            <label class="vc-up-label">Documents (PDF/JPG/PNG) — you may upload multiple files</label>

            <input type="file" name="documents[]" id="org_documents" multiple accept=".pdf,image/*" class="vc-up-input-file">

            <div id="doc_preview" style="margin-top:10px;">
                <?php if (!empty($old_docs)): ?>
                    <div><strong>Existing documents:</strong></div>
                    <ul>
                        <?php foreach ($old_docs as $p): ?>
                            <li><a href="<?= htmlspecialchars($p) ?>" target="_blank"><?= basename($p) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
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

<script src="/volcon/assets/js/utils/location_selector.js"></script>
<script type="module" src="/volcon/assets/js/update/update_profile_org.js"></script>
