//search-bar.js

document.addEventListener("DOMContentLoaded", function () {
    const filterButton = document.getElementById("filter-btn");
    const filterOverlay = document.getElementById("filter-overlay");
    const clearFiltersButton = document.querySelector(".vc-clear-filters");
    const applyFiltersButton = document.querySelector(".vc-apply-filters");

    // Toggle the filter overlay visibility
    filterButton.addEventListener("click", function () {
        filterOverlay.classList.toggle("visible");
    });

    // Clear filters when the "Clear Filters" button is clicked
    clearFiltersButton.addEventListener("click", function () {
        document.getElementById("location-filter").value = "";
        document.getElementById("start-date-filter").value = "";
        document.getElementById("end-date-filter").value = "";
    });

    // Apply filters (you can expand this with actual functionality later)
    applyFiltersButton.addEventListener("click", function () {
        const location = document.getElementById("location-filter").value;
        const startDate = document.getElementById("start-date-filter").value;
        const endDate = document.getElementById("end-date-filter").value;

        alert(`Applying filters:\nLocation: ${location}\nStart Date: ${startDate}\nEnd Date: ${endDate}`);
        // Example: You'd typically perform an AJAX call here to apply the filters.
    });
});
