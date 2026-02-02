// Tag Selector Component
class TagSelector {
    constructor(element) {
        this.element = element;
        this.inputWrapper = element.querySelector('.vc-tag-input-wrapper');
        this.input = element.querySelector('.vc-tag-input');
        this.selectedContainer = element.querySelector('.vc-tag-selected');
        this.dropdown = element.querySelector('.vc-tag-dropdown');
        this.options = Array.from(element.querySelectorAll('.vc-tag-option'));
        
        this.selectedValues = new Set();
        this.init();
    }
    
    init() {
        // Load existing selections
        this.element.querySelectorAll('.vc-tag').forEach(tag => {
            this.selectedValues.add(tag.dataset.value);
        });
        this.updateOptionsVisibility();
        
        // Toggle dropdown
        this.inputWrapper.addEventListener('click', (e) => {
            if (e.target === this.input) return;
            this.toggleDropdown();
        });
        
        // Search functionality
        this.input.addEventListener('input', (e) => {
            this.filterOptions(e.target.value);
        });
        
        this.input.addEventListener('focus', () => {
            this.showDropdown();
        });
        
        // Option selection
        this.options.forEach(option => {
            option.addEventListener('click', () => {
                const value = option.dataset.value;
                const label = option.dataset.label;
                
                if (this.selectedValues.has(value)) {
                    this.removeTag(value);
                } else {
                    this.addTag(value, label);
                }
            });
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', (e) => {
            if (!this.element.contains(e.target)) {
                this.hideDropdown();
            }
        });
        
        // Remove tag handler (delegated)
        this.selectedContainer.addEventListener('click', (e) => {
            if (e.target.classList.contains('fa-times') || e.target.closest('.fa-times')) {
                const tag = e.target.closest('.vc-tag');
                if (tag) {
                    this.removeTag(tag.dataset.value);
                }
            }
        });
    }
    
    toggleDropdown() {
        if (this.dropdown.classList.contains('show')) {
            this.hideDropdown();
        } else {
            this.showDropdown();
        }
    }
    
    showDropdown() {
        this.dropdown.classList.add('show');
        this.inputWrapper.classList.add('active');
    }
    
    hideDropdown() {
        this.dropdown.classList.remove('show');
        this.inputWrapper.classList.remove('active');
        this.input.value = '';
        this.filterOptions('');
    }
    
    filterOptions(query) {
        const lowerQuery = query.toLowerCase();
        this.options.forEach(option => {
            const label = option.dataset.label.toLowerCase();
            const matches = label.includes(lowerQuery);
            const isSelected = this.selectedValues.has(option.dataset.value);
            
            if (matches && !isSelected) {
                option.classList.remove('hidden');
            } else {
                option.classList.add('hidden');
            }
        });
    }
    
    addTag(value, label) {
        this.selectedValues.add(value);
        
        const tag = document.createElement('span');
        tag.className = 'vc-tag';
        tag.dataset.value = value;
        tag.innerHTML = `
            ${label}
            <i class="fas fa-times"></i>
            <input type="hidden" name="${this.getInputName()}" value="${value}" />
        `;
        
        this.selectedContainer.appendChild(tag);
        this.updateOptionsVisibility();
        this.input.value = '';
        this.filterOptions('');
    }
    
    removeTag(value) {
        this.selectedValues.delete(value);
        
        const tag = this.selectedContainer.querySelector(`.vc-tag[data-value="${value}"]`);
        if (tag) {
            tag.remove();
        }
        
        this.updateOptionsVisibility();
    }
    
    updateOptionsVisibility() {
        this.options.forEach(option => {
            const value = option.dataset.value;
            if (this.selectedValues.has(value)) {
                option.classList.add('selected');
            } else {
                option.classList.remove('selected');
            }
        });
    }
    
    getInputName() {
        // Determine input name based on element ID
        if (this.element.id === 'skillSelector') {
            return 'skills[]';
        } else if (this.element.id === 'interestSelector') {
            return 'interests[]';
        }
        return 'values[]';
    }
}

// Bookmark Toggle Functionality
class BookmarkManager {
    constructor() {
        this.init();
    }
    
    init() {
        document.querySelectorAll('.vc-bookmark-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.toggle(btn);
            });
        });
    }
    
    async toggle(btn) {
        const opportunityId = btn.dataset.id;
        const isBookmarked = btn.classList.contains('vc-bookmarked');
        
        try {
            const response = await fetch('/volcon/app/save_opportunity.php?id=' + opportunityId, {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            if (response.ok) {
                // Toggle visual state
                btn.classList.toggle('vc-bookmarked');
                const icon = btn.querySelector('i');
                
                if (isBookmarked) {
                    icon.classList.remove('fas');
                    icon.classList.add('far');
                    btn.title = 'Save opportunity';
                    this.showToast('Removed from saved');
                } else {
                    icon.classList.remove('far');
                    icon.classList.add('fas');
                    btn.title = 'Saved';
                    this.showToast('Added to saved');
                }
            } else {
                this.showToast('Failed to update. Please try again.', 'error');
            }
        } catch (error) {
            console.error('Bookmark error:', error);
            this.showToast('An error occurred. Please try again.', 'error');
        }
    }
    
    showToast(message, type = 'success') {
        // Create toast notification
        const toast = document.createElement('div');
        toast.className = `vc-toast vc-toast-${type}`;
        toast.textContent = message;
        toast.style.cssText = `
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: ${type === 'success' ? '#16a34a' : '#dc2626'};
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
            z-index: 1000;
            animation: slideIn 0.3s ease;
        `;
        
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
}

// Add animation styles
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from {
            transform: translateX(400px);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    
    @keyframes slideOut {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(400px);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);

// Filter Toggle Functionality
function toggleFilters() {
    const filterSection = document.getElementById('filterSection');
    const toggleText = document.getElementById('filterToggleText');
    
    filterSection.classList.toggle('collapsed');
    
    if (filterSection.classList.contains('collapsed')) {
        toggleText.textContent = 'Show Filters';
        localStorage.setItem('filtersCollapsed', 'true');
    } else {
        toggleText.textContent = 'Hide Filters';
        localStorage.setItem('filtersCollapsed', 'false');
    }
}

function setupFlexibleFilter() {
    const flexibleCheckbox = document.getElementById('flexibleCheckbox');
    const dateFromInput = document.querySelector('input[name="date_from"]');
    const dateToInput = document.querySelector('input[name="date_to"]');
    
    if (!flexibleCheckbox || !dateFromInput || !dateToInput) return;
    
    function toggleDateInputs() {
        const isDisabled = flexibleCheckbox.checked;
        dateFromInput.disabled = isDisabled;
        dateToInput.disabled = isDisabled;
        
        if (isDisabled) {
            dateFromInput.value = '';
            dateToInput.value = '';
        }
    }
    
    // Initial state
    toggleDateInputs();
    
    // Update on change
    flexibleCheckbox.addEventListener('change', toggleDateInputs);
}

// Initialize components when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    
    setupFlexibleFilter();
    
    // Initialize tag selectors
    const skillSelector = document.getElementById('skillSelector');
    const interestSelector = document.getElementById('interestSelector');
    
    if (skillSelector) {
        new TagSelector(skillSelector);
    }
    
    if (interestSelector) {
        new TagSelector(interestSelector);
    }
    
    // Initialize bookmark manager
    new BookmarkManager();
    
    // Restore filter collapse state from localStorage
    const filtersCollapsed = localStorage.getItem('filtersCollapsed');
    const filterSection = document.getElementById('filterSection');
    const toggleText = document.getElementById('filterToggleText');
    
    if (filtersCollapsed === 'true' && filterSection) {
        filterSection.classList.add('collapsed');
        if (toggleText) toggleText.textContent = 'Show Filters';
    }
});