import { regex, normalizePhone } from "../utils/validators.js";
import { mkError, clearFieldErrors, installBeforeUnload } from "../utils/form_utils.js";
import { bindStrengthMeter, bindPasswordMatch, initPasswordToggle } from "../utils/password_tools.js";

document.addEventListener("DOMContentLoaded", () => {
    const form = document.querySelector('form[action="signup_vol.php"]');
    if (!form) return;

    installBeforeUnload('form[action="signup_vol.php"]');
    initPasswordToggle();

    bindStrengthMeter(
        document.getElementById("vol-password"),
        "vol-meter",
        "vol-hint"
    );

    bindPasswordMatch(
        document.getElementById("vol-password"),
        document.getElementById("vol-password-confirm"),
        "vol-match"
    );

    form.addEventListener("submit", (ev) => {
        clearFieldErrors();
        let ok = true;

        const phone = normalizePhone(form.phone_no.value.trim());

        if (!regex.phoneMY.test(phone)) {
            form.phone_no.after(mkError("Invalid Malaysian phone number."));
            ok = false;
        }

        if (!ok) ev.preventDefault();
    });
});
