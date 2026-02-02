// /volcon/assets/js/utils/location_selector.js

document.addEventListener("DOMContentLoaded", () => {
    // Generate a cache-busting query string (e.g., a timestamp)
    const cacheBuster = `?t=${new Date().getTime()}`;

    fetch(`/volcon/assets/data/dat_locations.json${cacheBuster}`)
        .then(res => res.json())
        .then(data => {

            // VOL
            const stateVol = document.getElementById("state_vol");
            const cityVol  = document.getElementById("city_vol");

            // ORG
            const stateOrg = document.getElementById("state_org");
            const cityOrg  = document.getElementById("city_org");

            function populateStates(select) {
                if (!select) return;
                select.innerHTML = `<option value="">-- Select State --</option>`;
                Object.keys(data.states).forEach(state => {
                    select.innerHTML += `<option value="${state}">${state}</option>`;
                });
            }

            function populateCities(stateSelect, citySelect, selectedState, selectedCity) {
                if (!stateSelect || !citySelect) return;

                citySelect.innerHTML = `<option value="">-- Select Town or Area --</option>`;

                if (selectedState && data.states[selectedState]) {
                    data.states[selectedState].forEach(city => {
                        const isSelected = (city === selectedCity) ? "selected" : "";
                        citySelect.innerHTML += `<option value="${city}" ${isSelected}>${city}</option>`;
                    });
                }
            }

            function bindStateToCity(stateSelect, citySelect) {
                if (!stateSelect || !citySelect) return;

                stateSelect.addEventListener("change", () => {
                    populateCities(stateSelect, citySelect, stateSelect.value, null);
                });
            }

            // Populate state lists
            populateStates(stateVol);
            populateStates(stateOrg);

            // Restore VOL selection if available
            if (stateVol && typeof OLD_STATE !== 'undefined' && OLD_STATE !== "") {
                stateVol.value = OLD_STATE;
                populateCities(stateVol, cityVol, OLD_STATE, OLD_CITY);
            }

            // Restore ORG selection if available
            if (stateOrg && typeof OLD_STATE !== 'undefined' && OLD_STATE !== "") {
                stateOrg.value = OLD_STATE;
                populateCities(stateOrg, cityOrg, OLD_STATE, OLD_CITY);
            }

            // Bind events
            bindStateToCity(stateVol, cityVol);
            bindStateToCity(stateOrg, cityOrg);

        })
        .catch(err => console.error("Failed to load location JSON:", err));
});
