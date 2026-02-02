// form_wizard.js
document.addEventListener('DOMContentLoaded', () => {

  /* ---------------------------
     REGEX / RULES (same as server)
     --------------------------- */
    const usernameRegex = /^[A-Za-z](?!.*__)[A-Za-z0-9_.]{1,18}[A-Za-z0-9]$/;
    const passwordRegex = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,16}$/;
    const phoneRegex = /^(?:\+?60|0)(?:1[0-9]\d{7,8}|[3-9][0-9]\d{7})$/;
    const postcodeRegex = /^\d{5}$/;

    // helper
    function el(sel) { return document.querySelector(sel); }
    function els(sel) { return Array.from(document.querySelectorAll(sel)); }

    function normalizePhone(input) {
        return input.replace(/[\s\-().]/g, ''); // remove spaces, hyphens, parentheses, dots
    }

    /* -------------------------------------------------------
     SHARED UTIL: Step 2 password strength + toggle + matching
    --------------------------------------------------------- */

    // Show/Hide toggles
    document.addEventListener("click", (e) => {
        if (!e.target.classList.contains("toggle-pw")) return;

        const targetId = e.target.dataset.target;
        const input = document.getElementById(targetId);
        if (!input) return;

        if (input.type === "password") {
            input.type = "text";
            e.target.textContent = "🙈";
        } else {
            input.type = "password";
            e.target.textContent = "👁️";
        }
    });

    // Strength meter calculator
    function updateStrengthMeter(input, meterId, hintId) {
        const val = input.value;
        const meter = document.getElementById(meterId);
        const hint  = document.getElementById(hintId);

        if (!meter) return;

        let score = 0;
        if (/[a-z]/.test(val)) score++;
        if (/[A-Z]/.test(val)) score++;
        if (/\d/.test(val)) score++;
        if (/[^A-Za-z0-9]/.test(val)) score++;
        if (val.length >= 12) score++;

        const pct = Math.min(100, (score / 5) * 100);
        meter.innerHTML = `<div style="width:${pct}%;height:100%;background:${
            pct < 40 ? "#e74c3c" :
            pct < 60 ? "#f39c12" :
            pct < 80 ? "#2ecc71" : "#27ae60"
        }"></div>`;

        let txt = "Weak";
        if (pct >= 80) txt = "Strong";
        else if (pct >= 60) txt = "Good";
        else if (pct >= 40) txt = "Fair";
        hint.textContent = `Password strength: ${txt}`;
    }

    // Password match checker
    function updatePasswordMatch(pw1, pw2, matchId) {
        const match = document.getElementById(matchId);
        if (!match) return;

        if (!pw2.value) {
            match.textContent = "";
            return;
        }

        if (pw1.value === pw2.value) {
            match.style.color = "green";
            match.textContent = "✓ Passwords match";
        } else {
            match.style.color = "red";
            match.textContent = "✗ Passwords do not match";
        }
    }


  /* ---------------------------
     Step 1 form validation
     --------------------------- */
    const step1Form = el('form[action="signup.php"]');
    if (step1Form) {
        const usernameInput = step1Form.querySelector('input[name="username"]');
        const emailInput = step1Form.querySelector('input[name="email"]');
        const passwordInput = step1Form.querySelector('input[name="password"]');
        const roleSelect = step1Form.querySelector('select[name="role"]');

        // Inline message container
        const mkError = (msg) => {
        const d = document.createElement('div');
        d.className = 'field-error';
        d.textContent = msg;
        return d;
        };

        // remove previous errors
        function clearFieldErrors(form) {
        els('.field-error').forEach(n => n.remove());
        }

        // Password strength meter
        const meterWrap = document.createElement('div');
        meterWrap.className = 'pw-meter-wrap';
        meterWrap.innerHTML = `<div id="pw-meter" aria-hidden="true" style="height:6px;background:#eee;border-radius:4px;margin-top:6px">
        <div id="pw-meter-bar" style="height:100%;width:0%"></div>
        </div>
        <small id="pw-hint" style="display:block;margin-top:6px;color:#666"></small>`;
        passwordInput.parentNode.insertBefore(meterWrap, passwordInput.nextSibling);

        function updatePwMeter(val) {
            const bar = el('#pw-meter-bar');
            const hint = el('#pw-hint');
            let score = 0;
            if (/[a-z]/.test(val)) score++;
            if (/[A-Z]/.test(val)) score++;
            if (/\d/.test(val)) score++;
            if (/[^A-Za-z0-9]/.test(val)) score++;
            if (val.length >= 12) score++; // bonus

            const pct = Math.min(100, (score / 5) * 100);
            bar.style.width = pct + '%';
            // color
            if (pct < 40) bar.style.background = '#e74c3c';
            else if (pct < 60) bar.style.background = '#f39c12';
            else if (pct < 80) bar.style.background = '#2ecc71';
            else bar.style.background = '#27ae60';

            let text = 'Weak';
            if (pct >= 80) text = 'Strong';
            else if (pct >= 60) text = 'Good';
            else if (pct >= 40) text = 'Fair';
            hint.textContent = `Password strength: ${text}`;
        }

        passwordInput.addEventListener('input', (e) => updatePwMeter(e.target.value));

        step1Form.addEventListener('submit', (ev) => {
        clearFieldErrors(step1Form);
        let ok = true;

        const username = usernameInput.value.trim();
        const email = emailInput.value.trim();
        const pw = passwordInput.value;

        if (!usernameRegex.test(username)) {
            usernameInput.after(mkError('Username invalid. Start with a letter, 3–20 chars, no trailing underscore, no double underscore.'));
            ok = false;
        }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            emailInput.after(mkError('Enter a valid email address.'));
            ok = false;
        }
        if (!passwordRegex.test(pw)) {
            passwordInput.after(mkError('Password must be 8–16 chars, include upper & lower case, number and special char.'));
            ok = false;
        }
        if (!['vol','org'].includes(roleSelect.value)) {
            roleSelect.after(mkError('Choose a role.'));
            ok = false;
        }

        if (!ok) {
            ev.preventDefault();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        });
    }

  /* ---------------------------
     Volunteer Step2 validation
     --------------------------- */
    const volForm = el('form[action="signup_vol.php"]');
    if (volForm) {
        const firstName = volForm.querySelector('input[name="first_name"]');
        const lastName = volForm.querySelector('input[name="last_name"]');
        
        const phoneInput = volForm.querySelector('input[name="phone_no"]');
        const phone = normalizePhone(phoneInput.value);

        const birthdate = volForm.querySelector('input[name="birthdate"]');
        const stateSelect = volForm.querySelector('select[name="state_vol"]');
        const citySelect = volForm.querySelector('select[name="city_vol"]');

        // Bind password improvements (Volunteer)
        const volPW  = document.getElementById("vol-password");
        const volPW2 = document.getElementById("vol-password-confirm");

        if (volPW && volPW2) {
            volPW.addEventListener("input", () => {
                updateStrengthMeter(volPW, "vol-meter", "vol-hint");
                updatePasswordMatch(volPW, volPW2, "vol-match");
            });

            volPW2.addEventListener("input", () => {
                updatePasswordMatch(volPW, volPW2, "vol-match");
            });

            // Update meter if password already filled via PHP $old
            if (volPW.value.length > 0) {
                updateStrengthMeter(volPW, "vol-meter", "vol-hint");
                updatePasswordMatch(volPW, volPW2, "vol-match");
            }
        }


        volForm.addEventListener('submit', (ev) => {
        // remove previous per-field errors
        els('.field-error').forEach(n => n.remove());

        let ok = true;
        if (!firstName.value.trim()) {
            firstName.after(mkError('First name is required.'));
            ok = false;
        }
        if (!lastName.value.trim()) {
            lastName.after(mkError('Last name is required.'));
            ok = false;
        }
        if (!stateSelect.value) {
            stateSelect.after(mkError('State is required.'));
            ok = false;
        }
        if (!citySelect.value) {
            citySelect.after(mkError('Town / Area is required.'));
            ok = false;
        }
        if (!phoneRegex.test(phone.value.trim())) {
            phone.after(mkError('Enter a valid Malaysian phone number (e.g. +6012-12345678 or 012-1234567).'));
            ok = false;
        }
        if (!birthdate.value) {
            birthdate.after(mkError('Birthdate is required.'));
            ok = false;
        }

        if (!ok) {
            ev.preventDefault();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        });
    }

  /* ---------------------------
     Org Step2 validation
     --------------------------- */
    const orgForm = el('form[action="signup_org.php"]');
    if (orgForm) {
        const orgName = orgForm.querySelector('input[name="org_name"]');
        const mission = orgForm.querySelector('textarea[name="mission"]');
        const description = orgForm.querySelector('textarea[name="description"]');
        
        const contactInfoInput = orgForm.querySelector('input[name="contact_info"]');
        const contactInfo = normalizePhone(contactInfoInput.value);

        const postcode = orgForm.querySelector('input[name="postcode"]');
        const stateSelectO = orgForm.querySelector('select[name="state_org"]') || orgForm.querySelector('select[name="state"]');
        const citySelectO = orgForm.querySelector('select[name="city_org"]') || orgForm.querySelector('select[name="city"]');

        const MIN_MISSION = 20;    // adjust as you like
        const MIN_DESCRIPTION = 50;

        // Bind password improvements (Organization)
        const orgPW  = document.getElementById("org-password");
        const orgPW2 = document.getElementById("org-password-confirm");

        if (orgPW && orgPW2) {
            orgPW.addEventListener("input", () => {
                updateStrengthMeter(orgPW, "org-meter", "org-hint");
                updatePasswordMatch(orgPW, orgPW2, "org-match");
            });

            orgPW2.addEventListener("input", () => {
                updatePasswordMatch(orgPW, orgPW2, "org-match");
            });

            // Update meter if password already filled via PHP $old
            if (orgPW.value.length > 0) {
                updateStrengthMeter(orgPW, "org-meter", "org-hint");
                updatePasswordMatch(orgPW, orgPW2, "org-match");
            }
        }


        orgForm.addEventListener('submit', (ev) => {
        els('.field-error').forEach(n => n.remove());
        let ok = true;

        if (!orgName.value.trim()) {
            orgName.after(mkError('Organization name is required.'));
            ok = false;
        }
        if (!mission.value || mission.value.trim().length < MIN_MISSION) {
            mission.after(mkError(`Mission must be at least ${MIN_MISSION} characters.`));
            ok = false;
        }
        if (!description.value || description.value.trim().length < MIN_DESCRIPTION) {
            description.after(mkError(`Description must be at least ${MIN_DESCRIPTION} characters.`));
            ok = false;
        }
        if (!phoneRegex.test(contactInfo.value.trim())) {
            contactInfo.after(mkError('Contact info must be a valid Malaysian phone number.'));
            ok = false;
        }
        if (!postcodeRegex.test(postcode.value.trim())) {
            postcode.after(mkError('Postcode must be 5 digits.'));
            ok = false;
        }
        if (!stateSelectO.value) {
            stateSelectO.after(mkError('State is required.'));
            ok = false;
        }
        if (!citySelectO.value) {
            citySelectO.after(mkError('Town / Area is required.'));
            ok = false;
        }

        if (!ok) {
            ev.preventDefault();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        });
    }

  /* ---------------------------
     Move beforeunload into wizard (applies to Vol & Org step forms)
     --------------------------- */
    function installBeforeUnload(formSel) {
        const f = el(formSel);
        if (!f) return;
        let submitted = false;
        f.addEventListener('submit', () => { submitted = true; });
        window.addEventListener('beforeunload', (e) => {
        if (!submitted) {
            e.preventDefault();
            e.returnValue = "Your signup is not complete. Are you sure you want to leave?";
        }
        });
    }

    installBeforeUnload('form[action="signup_vol.php"]');
    installBeforeUnload('form[action="signup_org.php"]');

  /* ---------------------------
     Small utility used above
     --------------------------- */
    function mkError(msg) {
        const d = document.createElement('div');
        d.className = 'field-error';
        d.style.color = 'red';
        d.textContent = msg;
        return d;
    }

});
