import { regex } from "../utils/validators.js";
import { mkError, clearFieldErrors, installBeforeUnload } from "../utils/form_utils.js";
import { bindStrengthMeter, bindPasswordMatch, initPasswordToggle } from "../utils/password_tools.js";

document.addEventListener("DOMContentLoaded", () => {

    const form = document.querySelector(".vc-auth-form");
    if (!form) return;

    const pw  = document.getElementById("reset-password");
    const pw2 = document.getElementById("reset-password-confirm");

    installBeforeUnload('form[action="reset_password.php"]');
    initPasswordToggle();

    // Strength meter
    bindStrengthMeter(pw, "meter", "hint");

    // Match indicator
    bindPasswordMatch(pw, pw2, "match");

    form.addEventListener("submit", (ev) => {
        clearFieldErrors();
        let ok = true;

        // Regex check
        if (!regex.password.test(pw.value)) {
            mkError(
                pw,
                "Password must be 8–16 chars with uppercase, lowercase, number & symbol."
            );
            ok = false;
        }

        // Match check
        if (pw.value !== pw2.value) {
            mkError(pw2, "Passwords do not match.");
            ok = false;
        }

        if (!ok) ev.preventDefault();
    });
});
