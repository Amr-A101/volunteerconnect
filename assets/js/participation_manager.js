// Participation Manager JavaScript - Simplified

document.addEventListener('DOMContentLoaded', function() {
    initializePage();
    initializeFeedbackCells();
    initializeFeedbackShortcuts();
    
    // Initialize charts if on summary tab
    if (document.querySelector('#participationChart')) {
        initializeParticipationChart();
    }
});

/**
 * Initialize page functionality
 */
function initializePage() {
    console.log('Participation Manager loaded');
    
    // Initialize charts if on summary tab
    if (document.querySelector('#participationChart')) {
        initializeParticipationChart();
    }
}

/**
 * Initialize participation chart
 */
function initializeParticipationChart() {
    const ctx = document.getElementById('participationChart').getContext('2d');
    
    // Get data from stats in the page
    const stats = {
        attended: parseInt(document.querySelector('.vc-stat-attended .vc-stat-value').textContent) || 0,
        pending: parseInt(document.querySelector('.vc-stat-pending .vc-stat-value').textContent) || 0,
        absent: parseInt(document.querySelector('.vc-stat-absent .vc-stat-value').textContent) || 0,
        incomplete: 0
    };
    
    // Calculate incomplete
    const total = parseInt(document.querySelector('.vc-stat-card .vc-stat-value').textContent) || 0;
    stats.incomplete = total - stats.attended - stats.pending - stats.absent;
    
    if (stats.incomplete < 0) stats.incomplete = 0;
    
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Attended', 'Pending', 'Absent', 'Incomplete'],
            datasets: [{
                data: [stats.attended, stats.pending, stats.absent, stats.incomplete],
                backgroundColor: [
                    '#10b981',
                    '#f59e0b',
                    '#ef4444',
                    '#8b5cf6'
                ],
                borderWidth: 2,
                borderColor: 'white'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.raw || 0;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = Math.round((value / total) * 100);
                            return `${label}: ${value} (${percentage}%)`;
                        }
                    }
                }
            },
            cutout: '70%'
        }
    });
}

function openModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.classList.add('active');
}

function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.classList.remove('active');
    currentEditStatus = null;
}

function closeAllModals() {
    document.querySelectorAll('.vc-modal.active')
        .forEach(m => m.classList.remove('active'));
}

/**
 * Show modal for marking as attended
 */
function showAttendModal(volunteerId, totalHours, name) {
    document.getElementById('attendVolunteerId').value = volunteerId;
    document.getElementById('attendMaxHours').value = totalHours;
    document.getElementById('attendVolunteerName').textContent = name;

    openModal('markAttendModal');
}

function confirmAttend() {
    const volunteerId = document.getElementById('attendVolunteerId').value;
    const fullHours = parseInt(document.getElementById('attendMaxHours').value);

    closeModal('markAttendModal');
    submitAttendance(volunteerId, currentEditStatus || 'attended', fullHours, null);
}


/**
 * Show modal for marking as absent
 */

const absentReason = document.getElementById('absentReason');
const absentBtn = document.querySelector('#markAbsentModal .vc-btn-danger');

absentReason.addEventListener('change', () => {
    absentBtn.disabled = !absentReason.value;
});

function showAbsentModal(volunteerId, name) {
    document.getElementById('absentVolunteerId').value = volunteerId;
    document.getElementById('absentVolunteerName').textContent = name;
    absentReason.value = '';
    absentBtn.disabled = true;
    openModal('markAbsentModal');
}


function confirmAbsent() {
    const volunteerId = document.getElementById('absentVolunteerId').value;
    const reason = document.getElementById('absentReason').value;

    if (!reason) {
        alert('Please select a reason.');
        return;
    }

    submitAttendance(volunteerId, currentEditStatus || 'absent', null, reason);
    closeModal('markAbsentModal');
}

/**
 * Show modal for marking as incomplete
 */
function showIncompleteModal(volunteerId, totalHours, name) {
    document.getElementById('incompleteVolunteerId').value = volunteerId;
    document.getElementById('incompleteMaxHours').value = totalHours;
    document.getElementById('incompleteVolunteerName').textContent = name;

    const hoursInput = document.getElementById('incompleteHours');
    hoursInput.value = Math.min(2, totalHours);
    hoursInput.max = totalHours;

    document.getElementById('incompleteHoursHelp').textContent =
        `Enter hours between 0 and ${totalHours}.`;

    document.getElementById('incompleteReason').value = '';

    openModal('markIncompleteModal');
}


function confirmIncomplete() {
    const volunteerId = document.getElementById('incompleteVolunteerId').value;
    const maxHours = parseInt(
        document.getElementById('incompleteMaxHours').value
    );
    const hours = parseInt(
        document.getElementById('incompleteHours').value
    );
    const reason = document.getElementById('incompleteReason').value;

    if (isNaN(hours) || hours < 0 || hours > maxHours) {
        alert(`Please enter a valid number between 0 and ${maxHours}.`);
        return;
    }

    if (!reason) {
        alert('Please select a reason for incomplete participation.');
        return;
    }

    closeModal('markIncompleteModal');
    submitAttendance(volunteerId, currentEditStatus || 'incomplete', hours, reason);
}



/**
 * Submit attendance form
 */
function submitAttendance(volunteerId, status, hours, reason) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';
    
    form.innerHTML = `
        <input type="hidden" name="action" value="update_participation">
        <input type="hidden" name="volunteer_id" value="${volunteerId}">
        <input type="hidden" name="status" value="${status}">
        ${hours !== null ? `<input type="hidden" name="hours_worked" value="${hours}">` : ''}
        ${reason !== null ? `<input type="hidden" name="reason" value="${reason}">` : ''}
    `;
    
    document.body.appendChild(form);
    form.submit();
}




/**
 * Show emergency contacts modal
 */
function showEmergencyContacts(element) {
    const contacts = JSON.parse(element.getAttribute('data-contacts'));
    const modalBody = document.getElementById('emergencyContactsBody');
    
    let html = '<div class="vc-emergency-list">';
    html += '<h4>Emergency Contacts</h4>';
    for (const [name, phone] of Object.entries(contacts)) {
        html += `
            <div class="vc-contact-item">
                <div class="vc-contact-name">
                    <i class="fas fa-user"></i> ${name}
                </div>
                <div class="vc-contact-phone">
                    <i class="fas fa-phone"></i> <a href="tel:${phone}">${phone}</a>
                </div>
            </div>
        `;
    }
    html += '</div>';
    
    modalBody.innerHTML = html;
    openModal('emergencyModal');
}

/**
 * Update bulk actions bar
 */
function updateBulkActions() {
    const checkboxes = document.querySelectorAll('.volunteer-checkbox:checked');
    const bulkBar = document.getElementById('bulkActionsBar');
    const countSpan = document.getElementById('selectedCount');
    const selectedIdsContainer = document.getElementById('selectedIdsContainer');
    
    if (checkboxes.length > 0) {
        bulkBar.style.display = 'flex';
        countSpan.textContent = checkboxes.length;
        
        // Update hidden inputs with selected IDs
        selectedIdsContainer.innerHTML = '';
        checkboxes.forEach(cb => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_ids[]';
            input.value = cb.value;
            selectedIdsContainer.appendChild(input);
        });
        
        // Update select all checkbox
        const totalCheckboxes = document.querySelectorAll('.volunteer-checkbox').length;
        const selectAll = document.querySelector('.select-all');
        if (selectAll) {
            selectAll.checked = checkboxes.length === totalCheckboxes;
            selectAll.indeterminate = checkboxes.length > 0 && checkboxes.length < totalCheckboxes;
        }
    } else {
        bulkBar.style.display = 'none';
        selectedIdsContainer.innerHTML = '';
    }
}

/**
 * Toggle all selection
 */
function toggleAllSelection(checkbox) {
    document.querySelectorAll('.volunteer-checkbox').forEach(cb => {
        cb.checked = checkbox.checked;
    });
    updateBulkActions();
}

/**
 * Toggle bulk fields based on status
 */
function toggleBulkFields(select) {
    const hoursContainer = document.getElementById('bulkHoursContainer');
    const reasonContainer = document.getElementById('bulkReasonContainer');
    
    // Hide all first
    hoursContainer.style.display = 'none';
    reasonContainer.style.display = 'none';
    
    // Show based on selection
    if (select.value === 'attended') {
        hoursContainer.style.display = 'block';
    } else if (select.value === 'absent') {
        reasonContainer.style.display = 'block';
    } else if (select.value === 'incomplete') {
        hoursContainer.style.display = 'block';
        reasonContainer.style.display = 'block';
    }
}

/**
 * Confirm bulk action
 */
function confirmBulkAction() {
    const select = document.querySelector('select[name="bulk_status"]');
    const status = select.value;
    const count = document.querySelectorAll('.volunteer-checkbox:checked').length;
    
    let message = `Apply ${status} status to ${count} selected volunteer(s)?`;
    
    if (status === 'attended') {
        const hours = document.querySelector('input[name="bulk_hours"]').value;
        if (!hours || hours < 0 || hours > 24) {
            alert('Please enter valid hours (0-24) for attended status');
            return false;
        }
        message += `\n\nHours: ${hours}`;
    } else if (status === 'absent' || status === 'incomplete') {
        const reason = document.querySelector('select[name="bulk_reason"]').value;
        message += `\n\nReason: ${reason}`;
    }
    
    return confirm(message);
}

/**
 * Clear selection
 */
function clearSelection() {
    document.querySelectorAll('.volunteer-checkbox').forEach(cb => {
        cb.checked = false;
    });
    const selectAll = document.querySelector('.select-all');
    if (selectAll) {
        selectAll.checked = false;
        selectAll.indeterminate = false;
    }
    updateBulkActions();
}

/**
 * Quick mark all attended
 */
function quickMarkAllAttended() {
    if (confirm('Mark all pending volunteers as attended?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="mark_all_attended">
            <input type="hidden" name="default_hours" value="4">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}


/**
 * Enable feedback edit mode
 */
function enableFeedbackEdit(volunteerId) {
    // Hide display
    const displayEl = document.querySelector(`#feedback-cell-${volunteerId} .vc-feedback-display`);
    displayEl.style.display = 'none';
    
    // Show edit form
    const editEl = document.querySelector(`#feedback-edit-${volunteerId}`);
    editEl.style.display = 'block';
    
    // Focus textarea
    const textarea = editEl.querySelector('textarea');
    textarea.focus();
    
    // Auto-resize textarea
    textarea.addEventListener('input', autoResizeTextarea);
    autoResizeTextarea.call(textarea);
}

/**
 * Cancel feedback edit
 */
function cancelFeedbackEdit(volunteerId) {
    // Show display
    const displayEl = document.querySelector(`#feedback-cell-${volunteerId} .vc-feedback-display`);
    displayEl.style.display = 'block';
    
    // Hide edit form
    const editEl = document.querySelector(`#feedback-edit-${volunteerId}`);
    editEl.style.display = 'none';
    
    // Reset textarea to original value
    const originalText = displayEl.textContent.trim().replace('Click to add feedback...', '').trim();
    editEl.querySelector('textarea').value = originalText;
}

/**
 * Validate feedback form
 */
function validateFeedbackForm(form, volunteerId) {
    const textarea = form.querySelector('textarea');
    const feedback = textarea.value.trim();
    
    if (feedback.length > 1000) {
        alert('Feedback cannot exceed 1000 characters');
        textarea.focus();
        return false;
    }
    
    // Show saving indicator
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    
    return true; // Allow form submission
}

/**
 * Auto-resize textarea
 */
function autoResizeTextarea() {
    this.style.height = 'auto';
    this.style.height = (this.scrollHeight) + 'px';
}

/**
 * Handle feedback form submission success
 * (Called after successful form submission via PHP redirect)
 */
function handleFeedbackSuccess(volunteerId, feedback) {
    const displayEl = document.querySelector(`#feedback-cell-${volunteerId} .vc-feedback-display`);
    
    // Update display text
    if (feedback && feedback.trim()) {
        displayEl.textContent = feedback.trim();
        displayEl.classList.add('vc-feedback-has-content');
        displayEl.classList.remove('empty');
        
        // Add highlight animation
        displayEl.classList.add('vc-feedback-updated');
        setTimeout(() => {
            displayEl.classList.remove('vc-feedback-updated');
        }, 1500);
    } else {
        displayEl.textContent = 'Click to add feedback...';
        displayEl.classList.remove('vc-feedback-has-content');
        displayEl.classList.add('empty');
    }
    
    // Add edit icon back
    displayEl.innerHTML += '<span class="vc-edit-icon"><i class="fas fa-edit"></i></span>';
    
    // Return to display mode
    cancelFeedbackEdit(volunteerId);
}

/**
 * Initialize feedback cells
 */
function initializeFeedbackCells() {
    // Add click handlers to all feedback displays
    document.querySelectorAll('.vc-feedback-display').forEach(display => {
        // Get volunteer ID from parent cell
        const cell = display.closest('.td-feedback');
        if (cell) {
            const idMatch = cell.id.match(/feedback-cell-(\d+)/);
            if (idMatch) {
                const volunteerId = idMatch[1];
                display.addEventListener('click', function(e) {
                    // Don't trigger if clicking the edit icon
                    if (!e.target.closest('.vc-edit-icon')) {
                        enableFeedbackEdit(volunteerId);
                    }
                });
            }
        }
    });
    
    // Handle click on edit icons separately
    document.querySelectorAll('.vc-edit-icon').forEach(icon => {
        icon.addEventListener('click', function(e) {
            e.stopPropagation();
            const display = this.closest('.vc-feedback-display');
            const cell = display.closest('.td-feedback');
            const idMatch = cell.id.match(/feedback-cell-(\d+)/);
            if (idMatch) {
                enableFeedbackEdit(idMatch[1]);
            }
        });
    });
}

/**
 * Initialize keyboard shortcuts for feedback
 */
function initializeFeedbackShortcuts() {
    document.addEventListener('keydown', function(e) {
        // Check if we're in a feedback textarea
        const activeElement = document.activeElement;
        if (activeElement && activeElement.classList.contains('vc-feedback-textarea')) {
            const textarea = activeElement;
            const form = textarea.closest('form');
            const cell = form.closest('.td-feedback');
            const idMatch = cell.id.match(/feedback-cell-(\d+)/);
            
            if (idMatch) {
                const volunteerId = idMatch[1];
                
                // Ctrl/Cmd + Enter to save
                if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                    e.preventDefault();
                    form.submit();
                }
                
                // Escape to cancel
                if (e.key === 'Escape') {
                    e.preventDefault();
                    cancelFeedbackEdit(volunteerId);
                }
            }
        }
    });
}

/**
 * Export functions (stubs)
 */
function exportAttendanceList() { alert('Export feature coming soon'); }
function exportToCSV() { alert('CSV export feature coming soon'); }
function exportToPDF() { alert('PDF export feature coming soon'); }
function generateAnalytics() { alert('Analytics feature coming soon'); }
function viewFinalReport() { alert('Report feature coming soon'); }
function generateCertificates() { alert('Certificate feature coming soon'); }
function showAttendanceModal() { openModal('quickMarkModal') }
