// /volcon/assets/js/utils/password_tools.js

export function initPasswordToggle() {
    document.addEventListener("click", (e) => {

        const btn = e.target.closest(".vc-toggle-pw");
        if (!btn) return;

        const inputId = btn.dataset.target;
        const input = document.getElementById(inputId);
        if (!input) return;

        // Ensure icon exists
        let icon = btn.querySelector("i");
        if (!icon) {
            icon = document.createElement("i");
            icon.className = "fa-solid fa-eye";
            btn.textContent = "";
            btn.appendChild(icon);
        }

        const isPassword = input.type === "password";
        input.type = isPassword ? "text" : "password";

        // Toggle icon
        icon.classList.toggle("fa-eye", !isPassword);
        icon.classList.toggle("fa-eye-slash", isPassword);
    });
}


export function bindStrengthMeter(input, meterId, hintId) {
    if (input.value) {
        updateStrengthMeter(input.value, meterId, hintId);
    }

    input.addEventListener("input", () => updateStrengthMeter(input.value, meterId, hintId));
}

export function updateStrengthMeter(value, meterId, hintId) {
    const meter = document.getElementById(meterId);
    const hint = document.getElementById(hintId);

    let score = 0;
    if (/[a-z]/.test(value)) score++;
    if (/[A-Z]/.test(value)) score++;
    if (/\d/.test(value)) score++;
    if (/[^A-Za-z0-9]/.test(value)) score++;
    if (value.length >= 12) score++;

    const pct = Math.min(100, (score / 5) * 100);
    meter.innerHTML = `<div style="height:100%;width:${pct}%;background:${
        pct < 40 ? "#e74c3c" :
        pct < 60 ? "#f39c12" :
        pct < 80 ? "#2ecc71" : "#27ae60"
    }"></div>`;

    hint.textContent =
        pct >= 80 ? "Password strength: Strong" :
        pct >= 60 ? "Password strength: Good" :
        pct >= 40 ? "Password strength: Fair" :
                    "Password strength: Weak";
}

export function bindPasswordMatch(pw1, pw2, matchId) {
    pw2.addEventListener("input", () => {
        const match = document.getElementById(matchId);
        if (pw1.value === pw2.value) {
            match.style.color = "green";
            match.textContent = "✓ Passwords match";
        } else {
            match.style.color = "red";
            match.textContent = "✗ Passwords do not match";
        }
    });
}
