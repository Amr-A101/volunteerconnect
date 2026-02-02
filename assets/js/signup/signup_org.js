import { regex, normalizePhone } from "../utils/validators.js";
import { mkError, clearFieldErrors, installBeforeUnload } from "../utils/form_utils.js";
import { bindStrengthMeter, bindPasswordMatch, initPasswordToggle } from "../utils/password_tools.js";

document.addEventListener("DOMContentLoaded", () => {
    const form = document.querySelector('form[action="signup_org.php"]');
    if (!form) return;

    installBeforeUnload('form[action="signup_org.php"]');
    initPasswordToggle();

    bindStrengthMeter(
        document.getElementById("org-password"),
        "org-meter",
        "org-hint"
    );

    bindPasswordMatch(
        document.getElementById("org-password"),
        document.getElementById("org-password-confirm"),
        "org-match"
    );

    form.addEventListener("submit", (ev) => {
        clearFieldErrors();
        let ok = true;

        if (!regex.phoneMY.test(normalizePhone(form.contact_info.value))) {
            form.contact_info.after(mkError("Invalid Malaysian phone number."));
            ok = false;
        }

        if (!regex.postcodeMY.test(form.postcode.value.trim())) {
            form.postcode.after(mkError("Postcode must be 5 digits."));
            ok = false;
        }

        if (!ok) ev.preventDefault();
    });
});
