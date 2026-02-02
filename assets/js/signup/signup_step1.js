import { regex } from "../utils/validators.js";
import { mkError, clearFieldErrors } from "../utils/form_utils.js";
import { bindStrengthMeter, initPasswordToggle } from "../utils/password_tools.js";

document.addEventListener("DOMContentLoaded", () => {
    const form = document.querySelector('form[action="signup.php"]');
    if (!form) return;

    initPasswordToggle();
    bindStrengthMeter(
        document.getElementById("password"),
        "meter",
        "hint"
    );

    form.addEventListener("submit", (ev) => {
        clearFieldErrors();
        let ok = true;

        const username = form.username.value.trim();
        const email = form.email.value.trim();
        const pw = form.password.value;
        const pwErrors = document.getElementById("pwErrors");

        // Username validation
        if (!regex.username.test(username)) {
            form.username.after(mkError("Username must start with a letter, be 3-20 characters long, and cannot have trailing or double underscores."));
            ok = false;
        }

        // Email validation
        if (!regex.email.test(email)) {
            form.email.after(mkError("Please enter a valid email address (e.g., example@example.com)."));
            ok = false;
        }

        // Password validation
        if (!regex.password.test(pw)) {
            pwErrors.after(mkError("Password must be between 8-16 characters, including at least one uppercase letter, one lowercase letter, one number, and one special character."));
            ok = false;
        }

        if (!ok) ev.preventDefault();
    });
});
