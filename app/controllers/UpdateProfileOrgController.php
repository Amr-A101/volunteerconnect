<?php
// app/controllers/UpdateProfileOrgController.php

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/validators.php';
require_once __DIR__ . '/../models/OrganizationModel.php';

class UpdateProfileOrgController
{
    protected $dbc;
    protected $orgModel;
    protected $maxImageSize = 2 * 1024 * 1024;

    public function __construct()
    {
        $this->dbc = $GLOBALS['dbc'];
        $this->orgModel = new OrganizationModel($this->dbc);
    }

    public function handle()
    {
        $current = current_user();
        if (!$current) {
            header("Location: login.php"); exit;
        }

        if (!in_array($current['role'], ['org','admin'])) {
            die("Forbidden");
        }

        $orgId = $current['user_id'];
        $org = $this->orgModel->getByUserId($orgId);
        if (!$org) die("Organization profile not found.");

        $errors = [];

        /* ------------------------------
           GET -> SHOW THE VIEW
        ------------------------------ */
        if ($_SERVER["REQUEST_METHOD"] !== "POST") {

            $old = $org;

            $page_title = "Edit Organization Profile";
            require_once __DIR__ . '/../views/layout/header.php';
            require __DIR__ . '/../views/org/update_profile_org_view.php';
            require_once __DIR__ . '/../views/layout/footer.php';
            return;
        }

        /* ------------------------------
           POST -> PROCESS UPDATE
        ------------------------------ */

        // Input fields
        $name        = trim($_POST['name'] ?? '');
        $mission     = trim($_POST['mission'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $address     = trim($_POST['address'] ?? '');
        $city        = trim($_POST['city'] ?? '');
        $state       = trim($_POST['state'] ?? '');
        $postcode    = trim($_POST['postcode'] ?? '');
        $country     = trim($_POST['country'] ?? 'Malaysia');

        if ($name === "") $errors[] = "Organization name is required.";
        if (!preg_match('/^\d{5}$/', $postcode)) {
            $errors[] = 'Postcode must be 5 digits.';
        }

        $rules = [
            'contact' => [
                'phone'        => 'phone_my',
                'whatsapp'     => 'phone_my',
                'landline'     => 'phone_my',
                'fax'          => 'phone_my',
                'email'        => 'email',
                'contact_form' => 'url',
            ],

            'links' => [
                'website'   => 'url',
                'facebook'  => 'url',
                'instagram' => 'url',
                'linkedin'  => 'url',
                'tiktok'    => 'url',
                'youtube'   => 'url',
                'twitter'   => 'url',
                'donation'  => 'url',
                'blog'      => 'url',
            ],
        ];


        /* ------------------------------
           CONTACT INFO (Dynamic JSON)
        ------------------------------ */

        $contact_keys   = $_POST['contact_key'] ?? [];
        $contact_values = $_POST['contact_value'] ?? [];

        $contactInfo = [];
        $usedKeys = [];

        foreach ($contact_keys as $i => $key) {

            $key = trim($key);
            $val = trim($contact_values[$i] ?? '');

            if ($key === '' || $val === '') continue;

            // no duplicate types
            if (isset($usedKeys[$key])) {
                $errors[] = "Duplicate contact type: {$key}.";
                continue;
            }
            $usedKeys[$key] = true;

            // unknown type
            if (!isset($rules['contact'][$key])) {
                $errors[] = "Invalid contact type selected.";
                continue;
            }

            $rule = $rules['contact'][$key];

            if (!validate_value($rule, $val)) {
                $errors[] = "Invalid value for " . ucfirst(str_replace('_',' ', $key)) . ".";
                continue;
            }

            // normalize phone before saving
            if ($rule === 'phone_my') {
                $val = normalize_phone($val);
            }

            $contactInfo[$key] = $val;
        }

        $contact_info_json = $contactInfo ? json_encode($contactInfo, JSON_UNESCAPED_SLASHES) : null;


        /* ------------------------------
           EXTERNAL LINKS (Dynamic JSON)
        ------------------------------ */

        $link_keys = $_POST['link_key'] ?? [];
        $link_urls = $_POST['link_url'] ?? [];

        $externalLinks = [];
        $usedKeys = [];

        foreach ($link_keys as $i => $key) {

            $key = trim($key);
            $url = trim($link_urls[$i] ?? '');

            if ($key === '' || $url === '') continue;

            if (isset($usedKeys[$key])) {
                $errors[] = "Duplicate external link type: {$key}.";
                continue;
            }
            $usedKeys[$key] = true;

            if (!isset($rules['links'][$key])) {
                $errors[] = "Invalid external link type selected.";
                continue;
            }

            if (!validate_value('url', $url)) {
                $errors[] = "Invalid URL for " . ucfirst($key) . ".";
                continue;
            }

            $externalLinks[$key] = $url;
        }

        $external_links_json = $externalLinks ? json_encode($externalLinks, JSON_UNESCAPED_SLASHES) : null;

        /* ------------------------------
           DOCUMENT FILE UPLOADS (Multiple)
        ------------------------------ */
        $document_paths = $org['document_paths'] ?? [];

        if (!empty($_FILES['documents']['name'][0])) {

            $uploadDir = __DIR__ . "/../../assets/uploads/org_docs";
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            foreach ($_FILES['documents']['name'] as $i => $docName) {

                if ($_FILES['documents']['error'][$i] !== UPLOAD_ERR_OK)
                    continue;

                $tmp = $_FILES['documents']['tmp_name'][$i];
                $mime = mime_content_type($tmp);

                if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'])) {
                    $errors[] = "Invalid document type: only PDF/JPG/PNG allowed.";
                    continue;
                }

                $ext = pathinfo($docName, PATHINFO_EXTENSION);
                $filename = "org_doc_" . time() . "_" . bin2hex(random_bytes(6)) . "." . $ext;
                $dest = $uploadDir . "/" . $filename;

                if (move_uploaded_file($tmp, $dest)) {
                    $document_paths[] = "/volcon/assets/uploads/org_docs/" . $filename;
                }
            }
        }

        $document_paths_json = $document_paths ? json_encode($document_paths, JSON_UNESCAPED_SLASHES) : null;


        /* ------------------------------
           PROFILE PICTURE UPLOAD
        ------------------------------ */

        $profile_picture = $org['profile_picture'];

        if (isset($_FILES['profile_picture']) &&
            $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {

            $f = $_FILES['profile_picture'];

            if ($f['error'] !== UPLOAD_ERR_OK) {
                $errors[] = "Error uploading profile picture.";
            }
            elseif ($f['size'] > $this->maxImageSize) {
                $errors[] = "Profile image too large (max 2MB).";
            }
            else {
                $mime = mime_content_type($f['tmp_name']);
                $allowed = ['image/jpeg'=>'jpg','image/png'=>'png'];
                if (!isset($allowed[$mime])) {
                    $errors[] = "Profile picture must be JPG or PNG.";
                }
                else {
                    $uploadDir = __DIR__ . "/../../assets/uploads/org_pics";
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                    $ext = $allowed[$mime];
                    $filename = "org_pp_" . time() . "_" . bin2hex(random_bytes(6)) . "." . $ext;
                    $dest = $uploadDir . "/" . $filename;

                    if (move_uploaded_file($f['tmp_name'], $dest)) {
                        $profile_picture = "/volcon/assets/uploads/org_pics/" . $filename;
                    }
                }
            }
        }

        /* ------------------------------
           If errors -> re-render view
        ------------------------------ */

        if (!empty($errors)) {

            $old = $_POST;
            $old['contact_info']   = $contactInfo;
            $old['external_links'] = $externalLinks;
            $old['document_paths'] = $document_paths;
            $old['profile_picture'] = $profile_picture;

            $page_title = "Edit Organization Profile";
            require_once __DIR__ . '/../views/layout/header.php';
            require __DIR__ . '/../views/org/update_profile_org_view.php';
            require_once __DIR__ . '/../views/layout/footer.php';
            return;
        }

        /* ------------------------------
           SAVE TO DATABASE
        ------------------------------ */

        $ok = $this->orgModel->updateProfile(
            $orgId,
            $name,
            $mission,
            $description,
            $address,
            $city,
            $state,
            $postcode,
            $country,
            $profile_picture,
            $contact_info_json,
            $document_paths_json,
            $external_links_json
        );

        if (!$ok) die("Database update failed.");

        require_once __DIR__ . "/../core/flash.php";
        flash('success', 'Profile updated successfully.');
        header("Location: profile_org.php?id={$orgId}");
        exit;
    }
}
