// Applicants Manager JavaScript

document.addEventListener('DOMContentLoaded', function() {
    initializeAccordions();
    initializeBulkActions();
    restoreAccordionStates();
    updateSelectAllCheckboxes();
});

/**
 * Initialize accordion functionality
 */
function initializeAccordions() {
    const accordionHeaders = document.querySelectorAll('.vc-accordion-header');
    
    accordionHeaders.forEach(header => {
        header.addEventListener('click', function(e) {
            // Don't toggle if clicking on a button inside header
            if (e.target.closest('.btn')) {
                return;
            }
            
            const accordion = this.closest('.vc-opportunity-accordion');
            const body = accordion.querySelector('.vc-accordion-body');
            const opId = accordion.querySelector('[data-opid]')?.dataset.opid;
            
            // Toggle active state
            const isActive = this.classList.contains('active');
            
            if (isActive) {
                this.classList.remove('active');
                body.classList.remove('active');
                if (opId) {
                    sessionStorage.removeItem(`accordion_${opId}`);
                }
            } else {
                this.classList.add('active');
                body.classList.add('active');
                if (opId) {
                    sessionStorage.setItem(`accordion_${opId}`, 'open');
                }
                
                // Smooth scroll to accordion
                setTimeout(() => {
                    accordion.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }, 100);
            }
        });
    });
}

/**
 * Restore accordion states from session storage
 */
function restoreAccordionStates() {
    document.querySelectorAll('.vc-opportunity-accordion').forEach(accordion => {
        const opId = accordion.querySelector('[data-opid]')?.dataset.opid;
        if (opId && sessionStorage.getItem(`accordion_${opId}`) === 'open') {
            const header = accordion.querySelector('.vc-accordion-header');
            const body = accordion.querySelector('.vc-accordion-body');
            header.classList.add('active');
            body.classList.add('active');
        }
    });
}

/**
 * Toggle accordion programmatically (kept for backward compatibility)
 */
function toggleAccordion(header) {
    header.click();
}

/**
 * Initialize bulk actions functionality
 */
function initializeBulkActions() {
    // Add change listeners to all checkboxes
    const checkboxes = document.querySelectorAll('.vc-applicant-checkbox');
    checkboxes.forEach(cb => {
        cb.addEventListener('change', updateBulkActions);
    });
    
    // Setup bulk action form submission
    const bulkForm = document.getElementById('bulkActionForm');
    if (bulkForm) {
        bulkForm.addEventListener('submit', function(e) {
            const action = this.querySelector('[name="bulk_action"]').value;
            const selectedCount = document.querySelectorAll('.vc-applicant-checkbox:checked').length;
            
            if (!action) {
                e.preventDefault();
                alert('Please select an action first.');
                return false;
            }
            
            const actionNames = {
                'accept': 'accept',
                'shortlist': 'shortlist',
                'reject': 'reject'
            };
            
            if (!confirm(`Are you sure you want to ${actionNames[action]} ${selectedCount} applicant(s)?`)) {
                e.preventDefault();
                return false;
            }
            
            // Add checked IDs to form
            const checkedBoxes = document.querySelectorAll('.vc-applicant-checkbox:checked');
            checkedBoxes.forEach(cb => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'application_ids[]';
                input.value = cb.value;
                this.appendChild(input);
            });
        });
    }
}

function getEnabledApplicantCheckboxes(container = document) {
    return Array.from(container.querySelectorAll('.vc-applicant-checkbox:not(:disabled)'));
}

function getCheckedEnabledApplicantCheckboxes(container = document) {
    return Array.from(container.querySelectorAll('.vc-applicant-checkbox:not(:disabled):checked'));
}

/**
 * Update bulk actions bar visibility and count
 */
function updateBulkActions() {
    const checkedBoxes = getCheckedEnabledApplicantCheckboxes(document);
    const bulkBar = document.getElementById('bulkActionsBar');
    const countSpan = document.getElementById('selectedCount');
    
    if (checkedBoxes.length > 0) {
        bulkBar.style.display = 'flex';
        countSpan.textContent = checkedBoxes.length;
    } else {
        bulkBar.style.display = 'none';
    }
    
    // Update "select all" checkboxes state
    updateSelectAllCheckboxes();
}

/**
 * Toggle all applicants in an opportunity
 */
function toggleOpportunitySelection(checkbox) {
    const opId = checkbox.dataset.opid;
    const applicantCheckboxes = Array.from(document.querySelectorAll(`.vc-applicant-checkbox[data-opid="${opId}"]:not(:disabled)`));
    applicantCheckboxes.forEach(cb => cb.checked = checkbox.checked);
    
    applicantCheckboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });
    
    updateBulkActions();
}

/**
 * Update select-all checkboxes based on individual selections
 */
function updateSelectAllCheckboxes() {
    // For each select-all control
    const selects = document.querySelectorAll('.vc-select-all-opp');
    selects.forEach(sel => {
        const opId = sel.dataset.opid;
        // All enabled checkboxes for this opp
        const enabled = Array.from(document.querySelectorAll(`.vc-applicant-checkbox[data-opid="${opId}"]:not(:disabled)`));
        const checked = enabled.filter(cb => cb.checked);

        if (enabled.length === 0) {
            // nothing selectable -> disable select-all
            sel.checked = false;
            sel.indeterminate = false;
            sel.disabled = true;
        } else {
            sel.disabled = false;
            if (checked.length === 0) {
                sel.checked = false;
                sel.indeterminate = false;
            } else if (checked.length === enabled.length) {
                sel.checked = true;
                sel.indeterminate = false;
            } else {
                sel.checked = false;
                sel.indeterminate = true;
            }
        }
    });
}

/**
 * Clear all selections
 */
function clearSelection() {
    const checked = getCheckedEnabledApplicantCheckboxes(document);
    checked.forEach(cb => cb.checked = false);
    updateBulkActions();
}

/**
 * Auto-submit filters on certain changes (optional enhancement)
 */
function setupAutoFilter() {
    const filterForm = document.getElementById('filterForm');
    const autoSubmitFields = filterForm.querySelectorAll('select[name="status"], select[name="id"], select[name="sort"]');
    
    autoSubmitFields.forEach(field => {
        field.addEventListener('change', function() {
            // Optional: add a small delay for better UX
            setTimeout(() => filterForm.submit(), 100);
        });
    });
}

// Uncomment to enable auto-submit on filter changes
// setupAutoFilter();

/**
 * Keyboard shortcuts (optional enhancement)
 */
document.addEventListener('keydown', function(e) {
    // Ctrl/Cmd + A to select all visible enabled applicants (in expanded accordion)
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'a') {
        // find visible / active accordion body
        const activeBody = document.querySelector('.vc-accordion-body.active') || document;
        const visibleCheckboxes = Array.from(activeBody.querySelectorAll('.vc-applicant-checkbox:not(:disabled)'));
        if (visibleCheckboxes.length > 0) {
            e.preventDefault();
            visibleCheckboxes.forEach(cb => cb.checked = true);
            updateBulkActions();
        }
    }

    // Escape to clear selection
    if (e.key === 'Escape') {
        const selectedCount = getCheckedEnabledApplicantCheckboxes(document).length;
        if (selectedCount > 0) {
            clearSelection();
        }
    }
});

/**
 * Add loading state to buttons on form submit
 */
document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', function() {
        const submitBtn = this.querySelector('button[type="submit"]');
        if (submitBtn && !submitBtn.disabled) {
            const originalText = submitBtn.textContent;
            submitBtn.disabled = true;
            submitBtn.textContent = 'Processing...';
            
            // Re-enable after timeout (fallback)
            setTimeout(() => {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            }, 5000);
        }
    });
});
