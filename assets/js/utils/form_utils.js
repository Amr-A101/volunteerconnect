// /volcon/assets/js/utils/form_utils.js

export function el(q) { return document.querySelector(q); }
export function els(q) { return Array.from(document.querySelectorAll(q)); }

export function mkError(msg) {
    const d = document.createElement("div");
    d.className = "field-error";
    d.style.color = "red";
    d.textContent = msg;
    return d;
}

export function clearFieldErrors() {
    document.querySelectorAll(".field-error").forEach(e => e.remove());
}

export function installBeforeUnload(formSelector, message = "You have unsaved changes.") {
    const form = el(formSelector);
    if (!form) return;

    let submitted = false;
    form.addEventListener("submit", () => submitted = true);

    window.addEventListener("beforeunload", (e) => {
        if (!submitted) {
            e.preventDefault();
            e.returnValue = message;
        }
    });
}
