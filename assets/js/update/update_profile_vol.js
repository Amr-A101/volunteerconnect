// -----------------------------
// TomSelect Initialization
// -----------------------------
document.addEventListener('DOMContentLoaded', function () {
    if (document.querySelector('#skills')) {
        new TomSelect('#skills', {
            create: true,
            maxItems: 10,
            persist: true,
            createOnBlur: true,

            plugins: {
                remove_button: {
                    title: 'Remove this item'
                }
            }
        });
    }

    if (document.querySelector('#interests')) {
        new TomSelect('#interests', {
            create: true,
            maxItems: 10,
            persist: true,
            createOnBlur: true,

            plugins: {
                remove_button: {
                    title: 'Remove this item'
                }
            }
        });
    }
});


// -----------------------------
// Emergency Contacts Add/Remove
// -----------------------------
document.addEventListener("DOMContentLoaded", function () {

    const addBtn = document.getElementById("vc-btn-add-ec");
    const ecList = document.getElementById("ec_list");

    if (addBtn && ecList) {

        // Add contact row
        addBtn.addEventListener("click", () => {

            const row = document.createElement("div");
            row.classList.add("vc-up-ec-row");

            row.innerHTML = `
                <input type="text" name="ec_name[]" class="vc-up-input" placeholder="Name" required>
                <input type="text" name="ec_phone[]" class="vc-up-input" placeholder="Phone" required>
                <button type="button" class="vc-btn-ec-remove">×</button>
            `;

            ecList.appendChild(row);
        });

        // Remove contact row
        document.addEventListener("click", (e) => {
            if (e.target.classList.contains("vc-btn-ec-remove")) {
                e.target.parentElement.remove();
            }
        });
    }
});



// -----------------------------
// Inject OLD state/city to window
// -----------------------------
// These will be set by PHP BEFORE this file loads:
//   window.OLD_STATE
//   window.OLD_CITY
// Your location_selector.js can read them safely.
console.log("Loaded old state:", window.OLD_STATE);
console.log("Loaded old city:", window.OLD_CITY);


// Smooth fade-out animation for removed chips
document.addEventListener("click", function (e) {
    if (e.target.classList.contains("remove")) {
        const chip = e.target.closest(".item");
        if (chip) {
            // Add animation class
            chip.classList.add("ts-chip-removing");
            // Delay actual removal
            setTimeout(() => chip.remove(), 180);
        }
    }
});
