
// /volcon/assets/js/update/update_profile_org.js

import { regex, normalizePhone } from "../utils/validators.js";

/* --------------------------------
   Validation rules
---------------------------------- */
const contactRules = {
    phone: "phone",
    whatsapp: "phone",
    landline: "phone",
    fax: "phone",
    email: "email",
    contact_form: "url"
};

const linkRules = {
    website: "url",
    facebook: "url",
    instagram: "url",
    linkedin: "url",
    tiktok: "url",
    youtube: "url",
    twitter: "url",
    donation: "url",
    blog: "url"
};

/* --------------------------------
   Inline error helpers
---------------------------------- */
function showInlineError(input, msg) {
    removeInlineError(input);
    const err = document.createElement("div");
    err.className = "field-error";
    err.textContent = msg;
    input.after(err);
}

function removeInlineError(input) {
    const next = input.nextElementSibling;
    if (next && next.classList.contains("field-error")) {
        next.remove();
    }
}

/* --------------------------------
   Row validator
---------------------------------- */
function validateRow(selectEl, inputEl, rulesMap) {
    if (!selectEl || !inputEl) return true;

    const key = selectEl.value;
    let val = inputEl.value.trim();

    removeInlineError(inputEl);

    if (!val || !rulesMap[key]) return true;

    let ok = true;
    let msg = "";

    switch (rulesMap[key]) {
        case "phone":
            ok = regex.phoneMY.test(normalizePhone(val));
            msg = "Invalid Malaysian phone number.";
            break;

        case "email":
            ok = regex.email.test(val);
            msg = "Invalid email address.";
            break;

        case "url":
            ok = regex.url.test(val);
            msg = "Invalid URL (must start with http:// or https://).";
            break;
    }

    if (!ok) {
        // Prevent adding error inline within the row, and show it below the section title
        showSectionError(selectEl.closest(".vc-up-section"), msg);
    }
    return ok;
}


/* --------------------------------
   Section error helpers (new)
---------------------------------- */

function showSectionError(sectionEl, message) {
    // Find the error container within the section
    const errorMessageContainer = sectionEl.querySelector(".vc-up-error-message");

    // Set the error message
    errorMessageContainer.textContent = message;

    // Show the error container
    errorMessageContainer.style.display = 'block';
}

function removeSectionError(sectionEl) {
    // Find the error container within the section
    const errorMessageContainer = sectionEl.querySelector(".vc-up-error-message");

    // Clear and hide the error container
    errorMessageContainer.textContent = '';
    errorMessageContainer.style.display = 'none';
}



document.addEventListener("DOMContentLoaded", () => {

    const form = document.querySelector(".vc-up-form");
    if (!form) return;

    /* ===============================
       BEFORE UNLOAD
    =============================== */
    let dirty = false;
    form.addEventListener("input", () => dirty = true);

    window.addEventListener("beforeunload", e => {
        if (dirty) {
            e.preventDefault();
            e.returnValue = "";
        }
    });

    form.addEventListener("submit", () => dirty = false);

    // ---------------------------
    // CONTACT ROWS
    // ---------------------------
    const contactOptions = {
        phone: "Phone Number",
        email: "Email Address",
        whatsapp: "WhatsApp Number",
        landline: "Office Landline",
        fax: "Fax",
        contact_form: "Website Contact Form"
    };

    function createContactRow(key = "phone", value = "") {
        const row = document.createElement("div");
        row.className = "vc-up-ec-row";

        row.innerHTML = `
            <select name="contact_key[]" class="vc-up-select">
                ${Object.entries(contactOptions)
                    .map(([k, label]) => `<option value="${k}" ${k === key ? "selected" : ""}>${label}</option>`)
                    .join("")}
            </select>

            <input type="text" name="contact_value[]" class="vc-up-input"
                   placeholder="Enter value..." value="${value}">

            <button type="button" class="vc-btn-ec-remove">×</button>
        `;

        return row;
    }

    document.getElementById("vc-btn-add-contact").addEventListener("click", () => {
        document.getElementById("contact_list").appendChild(createContactRow());
    });

    // ---------------------------
    // EXTERNAL LINKS ROWS
    // ---------------------------
    const linkOptions = {
        website: "Official Website",
        facebook: "Facebook Page",
        instagram: "Instagram",
        linkedin: "LinkedIn",
        tiktok: "TikTok",
        youtube: "YouTube Channel",
        twitter: "X / Twitter",
        signup_page: "Volunteer Signup Page",
        donation: "Donation Page",
        blog: "Blog / News Page"
    };

    function createLinkRow(key = "website", url = "") {
        const row = document.createElement("div");
        row.className = "vc-up-ec-row";

        row.innerHTML = `
            <select name="link_key[]" class="vc-up-select">
                ${Object.entries(linkOptions)
                    .map(([k, label]) => `<option value="${k}" ${k === key ? "selected" : ""}>${label}</option>`)
                    .join("")}
            </select>

            <input type="url" name="link_url[]" class="vc-up-input"
                   placeholder="https://..." value="${url}">

            <button type="button" class="vc-btn-ec-remove">×</button>
        `;

        return row;
    }

    document.getElementById("vc-btn-add-link").addEventListener("click", () => {
        document.getElementById("links_list").appendChild(createLinkRow());
    });

    // Row removal (delegated)
    document.addEventListener("click", (e) => {
        if (e.target.classList.contains("vc-btn-ec-remove")) {
            const row = e.target.closest(".vc-up-ec-row");
            if (row) row.remove();
        }
    });

    /* ===============================
       DISABLE DUPLICATE CONTACT TYPES
    =============================== */
    function syncContactSelects() {
        const selects = [...document.querySelectorAll('select[name="contact_key[]"]')];
        const used = selects.map(s => s.value);

        selects.forEach(sel => {
            [...sel.options].forEach(opt => {
                opt.disabled = used.includes(opt.value) && opt.value !== sel.value;
            });
        });
    }

    document.addEventListener("change", e => {
        if (e.target.name === "contact_key[]") syncContactSelects();
    });

    document.addEventListener("click", e => {
        if (e.target.classList.contains("vc-btn-ec-remove")) {
            e.target.closest(".vc-up-ec-row")?.remove();
            syncContactSelects();
        }
    });

    syncContactSelects();

    /* ===============================
       LIVE PER-ROW VALIDATION
    =============================== */
    document.addEventListener("blur", e => {

        // Handle contact information validation
        if (e.target.name === "contact_value[]") {
            const row = e.target.closest(".vc-up-ec-row");
            const section = row.closest(".vc-up-section");
            const errorMessageContainer = section.querySelector(".vc-up-error-message");

            // Perform validation
            const isValid = validateRow(
                row.querySelector('select[name="contact_key[]"]'),
                e.target,
                contactRules
            );

            // Show or hide error message below the section title
            if (!isValid) {
                errorMessageContainer.textContent = "Please enter a valid contact value."; // Customize the error message as needed
                errorMessageContainer.style.display = 'block'; // Show the error message
            } else {
                errorMessageContainer.textContent = "";
                errorMessageContainer.style.display = 'none'; // Hide the error message
            }
        }

        // Handle external link validation
        if (e.target.name === "link_url[]") {
            const row = e.target.closest(".vc-up-ec-row");
            const section = row.closest(".vc-up-section");
            const errorMessageContainer = section.querySelector(".vc-up-error-message");

            // Perform validation
            const isValid = validateRow(
                row.querySelector('select[name="link_key[]"]'),
                e.target,
                linkRules
            );

            // Show or hide error message below the section title
            if (!isValid) {
                errorMessageContainer.textContent = "Please enter a valid URL."; // Customize the error message as needed
                errorMessageContainer.style.display = 'block'; // Show the error message
            } else {
                errorMessageContainer.textContent = "";
                errorMessageContainer.style.display = 'none'; // Hide the error message
            }
        }

    }, true);

    /* ===============================
       FINAL SUBMIT GUARD
    =============================== */
    form.addEventListener("submit", e => {
        let ok = true;
        let firstErrorSection = null;

        // Validate each contact row
        document.querySelectorAll("#contact_list .vc-up-ec-row").forEach(row => {
            const section = row.closest(".vc-up-section");
            const selectEl = row.querySelector('select[name="contact_key[]"]');
            const inputEl = row.querySelector('input[name="contact_value[]"]');

            if (!validateRow(selectEl, inputEl, contactRules)) {
                ok = false;

                // Check if this section has an error message (if it's the first one found)
                if (!firstErrorSection && section.querySelector(".vc-up-error-message").style.display !== 'none') {
                    firstErrorSection = section;
                }
            }
        });

        // Validate each link row
        document.querySelectorAll("#links_list .vc-up-ec-row").forEach(row => {
            const section = row.closest(".vc-up-section");
            const selectEl = row.querySelector('select[name="link_key[]"]');
            const inputEl = row.querySelector('input[name="link_url[]"]');

            if (!validateRow(selectEl, inputEl, linkRules)) {
                ok = false;

                // Check if this section has an error message (if it's the first one found)
                if (!firstErrorSection && section.querySelector(".vc-up-error-message").style.display !== 'none') {
                    firstErrorSection = section;
                }
            }
        });

        // If validation fails, prevent form submission and scroll to the first error
        if (!ok) {
            e.preventDefault();

            // Scroll to the first error section, if it exists
            if (firstErrorSection) {
                firstErrorSection.scrollIntoView({ behavior: "smooth", block: "center", inline: "nearest" });
            }
        }
    });
});
