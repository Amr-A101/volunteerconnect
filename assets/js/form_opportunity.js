/**
 * Opportunity Form Validation & UX Enhancement
 */

class OpportunityForm {
    constructor() {
        this.form = document.getElementById('opportunityForm');
        this.saveDraftBtn = document.getElementById('saveDraftBtn');
        this.saveAsDraftInput = document.getElementById('saveAsDraft');
        this.progressBar = document.getElementById('formProgress');
        this.progressText = document.getElementById('progressText');
        this.coverImageInput = document.getElementById('coverImage');
        this.additionalImagesInput = document.getElementById('additionalImages');
        
        // Track initialization
        this.initialized = false;

        this.init();
    }
    
    init() {
        if (!this.form || this.initialized) return;
        
        // Mark as initialized
        this.initialized = true;
        
        // Initialize form
        this.initDateValidation();
        this.initFilePreviews();
        this.initProgressTracking();
        this.initFormValidation();
        this.initLocationSelector();
        this.initTomSelect();
        
        // Event listeners
        if (this.saveDraftBtn) {
            this.saveDraftBtn.addEventListener('click', () => this.saveAsDraft());
        }
        
        this.form.addEventListener('submit', (e) => this.validateForm(e));
    }
    
    initDateValidation() {
        const startDateInput = this.form.querySelector('input[name="start_date"]');
        const endDateInput = this.form.querySelector('input[name="end_date"]');
        const startTimeInput = this.form.querySelector('input[name="start_time"]');
        const endTimeInput = this.form.querySelector('input[name="end_time"]');
        const deadlineInput = this.form.querySelector('input[name="application_deadline"]');
        
        // Set minimum dates to today
        const today = new Date().toISOString().split('T')[0];
        const now = new Date().toISOString().slice(0, 16);
        
        if (startDateInput) startDateInput.min = today;
        if (endDateInput) endDateInput.min = today;
        if (deadlineInput) deadlineInput.min = now;
        
        // Date range validation
        if (startDateInput && endDateInput) {
            startDateInput.addEventListener('change', () => {
                if (startDateInput.value) {
                    endDateInput.min = startDateInput.value;
                    if (endDateInput.value && endDateInput.value < startDateInput.value) {
                        endDateInput.value = startDateInput.value;
                    }
                }
            });
        }
        
        // Time validation for same day
        if (startDateInput && endDateInput && startTimeInput && endTimeInput) {
            const validateTimes = () => {
                if (startDateInput.value === endDateInput.value && 
                    startTimeInput.value && endTimeInput.value &&
                    endTimeInput.value <= startTimeInput.value) {
                    endTimeInput.setCustomValidity('End time must be later than start time');
                } else {
                    endTimeInput.setCustomValidity('');
                }
            };
            
            [startDateInput, endDateInput, startTimeInput, endTimeInput].forEach(input => {
                input.addEventListener('change', validateTimes);
            });
        }
    }
    
    initFilePreviews() {
        // Cover image preview
        if (this.coverImageInput) {
            this.coverImageInput.addEventListener('change', (e) => {
                this.previewImage(e.target, 'coverPreview', 1);
            });
        }
        
        // Additional images preview
        if (this.additionalImagesInput) {
            this.additionalImagesInput.addEventListener('change', (e) => {
                this.previewImage(e.target, 'additionalPreview', 5);
            });
        }
    }
    
    previewImage(input, previewId, maxFiles) {
        const previewContainer = document.getElementById(previewId);
        if (!previewContainer) return;
        
        // Clear existing previews
        previewContainer.innerHTML = '';
        const files = input.files;
        
        if (!files || files.length === 0) return;
        
        let filesProcessed = 0;
        
        for (let i = 0; i < Math.min(files.length, maxFiles); i++) {
            const file = files[i];
            
            // Validate file type
            if (!file.type.match('image.*')) {
                alert(`File "${file.name}" is not an image.`);
                continue;
            }
            
            const reader = new FileReader();
            
            reader.onload = (e) => {
                const previewItem = document.createElement('div');
                previewItem.className = 'vc-preview-item';
                
                const img = document.createElement('img');
                img.src = e.target.result;
                img.alt = `Preview ${i + 1}`;
                
                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'vc-preview-remove';
                removeBtn.innerHTML = '×';
                removeBtn.title = 'Remove image';
                removeBtn.addEventListener('click', () => {
                    previewItem.remove();
                    this.updateFileInput(input, i);
                });
                
                previewItem.appendChild(img);
                previewItem.appendChild(removeBtn);
                previewContainer.appendChild(previewItem);
                
                filesProcessed++;
                
                // If all files processed, ensure only one preview per file
                if (filesProcessed === Math.min(files.length, maxFiles)) {
                    this.ensureUniquePreviews(previewContainer);
                }
            };
            
            reader.onerror = () => {
                console.error(`Failed to read file: ${file.name}`);
            };
            
            reader.readAsDataURL(file);
        }
    }

    ensureUniquePreviews(container) {
        // Remove duplicate previews
        const images = container.querySelectorAll('img');
        const seenSrc = new Set();
        
        images.forEach((img, index) => {
            if (seenSrc.has(img.src)) {
                img.closest('.vc-preview-item')?.remove();
            } else {
                seenSrc.add(img.src);
            }
        });
    }
    
    updateFileInput(input, indexToRemove) {
        // Create new FileList without the removed file
        const dt = new DataTransfer();
        const files = input.files;
        
        for (let i = 0; i < files.length; i++) {
            if (i !== indexToRemove) {
                dt.items.add(files[i]);
            }
        }
        
        input.files = dt.files;
    }
    
    initProgressTracking() {
        if (!this.progressBar || !this.progressText) return;
        
        const updateProgress = () => {
            const requiredFields = this.form.querySelectorAll('[required]');
            const filledFields = Array.from(requiredFields).filter(field => {
                if (field.type === 'checkbox' || field.type === 'radio') {
                    return field.checked;
                }
                return field.value.trim() !== '';
            });
            
            const progress = (filledFields.length / requiredFields.length) * 100;
            this.progressBar.style.width = `${progress}%`;
            this.progressText.textContent = `${Math.round(progress)}%`;
        };
        
        // Update on any input
        this.form.addEventListener('input', updateProgress);
        this.form.addEventListener('change', updateProgress);
        
        // Initial update
        updateProgress();
    }
    
    initFormValidation() {
        // Real-time validation
        const fields = this.form.querySelectorAll('input, select, textarea');
        
        fields.forEach(field => {
            field.addEventListener('blur', () => this.validateField(field));
            field.addEventListener('input', () => this.clearFieldError(field));
        });
    }
    
    validateField(field) {
        this.clearFieldError(field);
        
        // Required validation
        if (field.hasAttribute('required') && !field.value.trim()) {
            this.showFieldError(field, 'This field is required');
            return false;
        }
        
        // Email validation
        if (field.type === 'email' && field.value) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(field.value)) {
                this.showFieldError(field, 'Please enter a valid email address');
                return false;
            }
        }
        
        // Number range validation
        if (field.type === 'number' && field.value) {
            const min = field.getAttribute('min');
            const max = field.getAttribute('max');
            
            if (min && parseInt(field.value) < parseInt(min)) {
                this.showFieldError(field, `Minimum value is ${min}`);
                return false;
            }
            
            if (max && parseInt(field.value) > parseInt(max)) {
                this.showFieldError(field, `Maximum value is ${max}`);
                return false;
            }
        }
        
        // Date validation
        if (field.type === 'date' && field.value) {
            const selectedDate = new Date(field.value);
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            
            if (selectedDate < today) {
                this.showFieldError(field, 'Date cannot be in the past');
                return false;
            }
        }
        
        return true;
    }
    
    showFieldError(field, message) {
        const formGroup = field.closest('.vc-form-group');
        if (!formGroup) return;
        
        formGroup.classList.add('error');
        
        let errorMsg = formGroup.querySelector('.vc-error-message');
        if (!errorMsg) {
            errorMsg = document.createElement('div');
            errorMsg.className = 'vc-error-message';
            formGroup.appendChild(errorMsg);
        }
        errorMsg.textContent = message;
    }
    
    clearFieldError(field) {
        const formGroup = field.closest('.vc-form-group');
        if (formGroup) {
            formGroup.classList.remove('error');
            const errorMsg = formGroup.querySelector('.vc-error-message');
            if (errorMsg) errorMsg.remove();
        }
    }
    
    initLocationSelector() {
        // Initialize location selector if it exists
        if (typeof initLocationSelect === 'function') {
            initLocationSelect('state_org', 'city_org');
        }
    }
    
    // Update the main validateForm method
    validateForm(e) {
        const action = document.getElementById('formAction')?.value;

        // Bypass validation for non-edit actions
        const bypassActions = ['close', 'reopen', 'publish', 'duplicate', 'complete'];

        if (bypassActions.includes(action)) {
            return true; // allow submit immediately
        }

        let isValid = true;
        const fields = this.form.querySelectorAll('input, select, textarea');
        
        fields.forEach(field => {
            if (!this.validateField(field)) {
                isValid = false;
            }
        });
        
        // Add TomSelect validation
        if (!this.validateTomSelectFields()) {
            isValid = false;
        }
        
        // Add edit-specific validations
        if (!this.validateVolunteerCount()) {
            isValid = false;
        }
        
        if (!this.validateContactPersons()) {
            isValid = false;
        }
        
        if (!isValid) {
            e.preventDefault();
            
            // Scroll to first error
            const firstError = this.form.querySelector('.error');
            if (firstError) {
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            
            return false;
        }
        
        // Show loading state
        const submitBtn = this.form.querySelector('button[type="submit"]');
        if (submitBtn) {
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            submitBtn.disabled = true;
            
            // Reset after 5 seconds if form doesn't submit
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }, 5000);
        }
        
        return true;
    }
    
    saveAsDraft() {
        if (confirm('Save this opportunity as a draft? You can publish it later.')) {
            this.saveAsDraftInput.value = '1';
            
            // Set all required fields to optional for draft
            const requiredFields = this.form.querySelectorAll('[required]');
            requiredFields.forEach(field => {
                field.removeAttribute('required');
            });
            
            this.form.submit();
        }
    }
    
    // Additional validation for multi-select limits
    validateMultiSelect(select, max) {
        if (select.selectedOptions.length > max) {
            this.showFieldError(select, `Maximum ${max} selections allowed`);
            return false;
        }
        return true;
    }

    initTomSelect() {
        // Initialize Required Skills TomSelect
        const skillsSelect = document.getElementById('requiredSkills');
        if (skillsSelect) {
            this.skillsTomSelect = new TomSelect(skillsSelect, {
                plugins: ['remove_button', 'clear_button'],
                maxItems: 10,
                create: true,
                createOnBlur: true,
                create: function(input, callback) {
                    fetch('/volcon/app/api/create_skill.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'skill_name=' + encodeURIComponent(input)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.error) {
                            alert(data.error);
                            callback(null); // Don't create option
                        } else {
                            callback({value: data.skill_id, text: data.skill_name});
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        callback(null);
                    });
                },
                createFilter: function(input) {
                    return input.length >= 2; // Minimum 2 characters for new skill
                },
                render: {
                    option_create: function(data, escape) {
                        return '<div class="create"><i class="fas fa-plus-circle"></i> Add new skill: "' + escape(data.input) + '"</div>';
                    },
                    item: function(data, escape) {
                        return '<div><i class="fas fa-check-circle"></i> ' + escape(data.text) + '</div>';
                    },
                    option: function(data, escape) {
                        return '<div><i class="fas fa-tag"></i> ' + escape(data.text) + '</div>';
                    }
                },
                onItemAdd: function() {
                    this.refreshOptions();
                },
                onDelete: function() {
                    if (this.items.length === 0) {
                        this.clear(true);
                    }
                }
            });
        }

        // Initialize Preferred Interests TomSelect
        const interestsSelect = document.getElementById('preferredInterests');
        if (interestsSelect) {
            this.interestsTomSelect = new TomSelect(interestsSelect, {
                plugins: ['remove_button', 'clear_button'],
                maxItems: 10,
                create: true,
                createOnBlur: true,
                create: function(input, callback) {
                    fetch('/volcon/app/api/create_interest.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'interest_name=' + encodeURIComponent(input)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.error) {
                            alert(data.error);
                            callback(null); // Don't create option
                        } else {
                            callback({value: data.interest_id, text: data.interest_name});
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        callback(null);
                    });
                },
                createFilter: function(input) {
                    return input.length >= 2; // Minimum 2 characters for new interest
                },
                render: {
                    option_create: function(data, escape) {
                        return '<div class="create"><i class="fas fa-plus-circle"></i> Add new interest: "' + escape(data.input) + '"</div>';
                    },
                    item: function(data, escape) {
                        return '<div><i class="fas fa-heart"></i> ' + escape(data.text) + '</div>';
                    },
                    option: function(data, escape) {
                        return '<div><i class="fas fa-star"></i> ' + escape(data.text) + '</div>';
                    }
                },
                onItemAdd: function() {
                    this.refreshOptions();
                },
                onDelete: function() {
                    if (this.items.length === 0) {
                        this.clear(true);
                    }
                }
            });
        }
    }

    // Add validation for TomSelect fields
    validateTomSelectFields() {
        let isValid = true;
        
        // Validate skills (max 10)
        if (this.skillsTomSelect && this.skillsTomSelect.items.length > 10) {
            const skillsField = document.getElementById('requiredSkills');
            this.showFieldError(skillsField, 'Maximum 10 skills allowed');
            isValid = false;
        }
        
        // Validate interests (max 10)
        if (this.interestsTomSelect && this.interestsTomSelect.items.length > 10) {
            const interestsField = document.getElementById('preferredInterests');
            this.showFieldError(interestsField, 'Maximum 10 interests allowed');
            isValid = false;
        }
        
        return isValid;
    }

    validateVolunteerCount() {
        const volunteersInput = this.form.querySelector('input[name="number_of_volunteers"]');
        if (!volunteersInput) return true;
        
        const minValue = parseInt(volunteersInput.getAttribute('min'));
        const currentValue = parseInt(volunteersInput.value);
        
        if (currentValue < minValue) {
            this.showFieldError(volunteersInput, 
                `Must be at least ${minValue} (based on accepted volunteers)`);
            return false;
        }
        
        return true;
    }

    validateContactPersons() {
        const contactNames = this.form.querySelectorAll('input[name="contact_name[]"]');
        let hasPrimary = false;
        let hasValidContact = false;
        
        contactNames.forEach((input, index) => {
            const name = input.value.trim();
            const email = this.form.querySelectorAll('input[name="contact_email[]"]')[index]?.value.trim();
            const phone = this.form.querySelectorAll('input[name="contact_phone[]"]')[index]?.value.trim();
            const isPrimary = this.form.querySelector(`input[name="is_primary"][value="${index}"]`)?.checked;
            
            if (name) {
                hasValidContact = true;
                
                // Validate email if provided
                if (email && !this.validateEmail(email)) {
                    this.showFieldError(input.closest('.vc-form-group'), 'Invalid email format');
                    return false;
                }
                
                if (isPrimary) {
                    hasPrimary = true;
                }
            }
        });
        
        if (!hasValidContact) {
            const firstContact = document.querySelector('input[name="contact_name[]"]');
            if (firstContact) {
                this.showFieldError(firstContact, 'At least one contact person is required');
            }
            return false;
        }
        
        if (!hasPrimary) {
            const radioGroup = document.querySelector('.vc-primary-contact');
            if (radioGroup) {
                this.showFieldError(radioGroup.closest('.vc-form-group'), 'Please select a primary contact');
            }
            return false;
        }
        
        return true;
    }

    validateEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }

    
}

// Initialize form when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    new OpportunityForm();
    
    // Add character counter for textareas
    const textareas = document.querySelectorAll('textarea[maxlength]');
    textareas.forEach(textarea => {
        const maxLength = parseInt(textarea.getAttribute('maxlength'));
        const counter = document.createElement('div');
        counter.className = 'vc-char-counter';
        counter.style.cssText = `
            font-size: 12px;
            color: var(--text-secondary);
            text-align: right;
            margin-top: 4px;
        `;
        
        textarea.parentNode.appendChild(counter);
        
        const updateCounter = () => {
            const length = textarea.value.length;
            counter.textContent = `${length}/${maxLength}`;
            counter.style.color = length > maxLength * 0.9 ? 'var(--danger-color)' : 'var(--text-secondary)';
        };
        
        textarea.addEventListener('input', updateCounter);
        updateCounter();
    });
});