// VC Admin Dashboard Scripts
document.addEventListener('DOMContentLoaded', function() {
    console.log('Admin dashboard loaded');
    
    // Initialize sidebar toggle
    initSidebarToggle();
    
    // Initialize tabs
    initTabs();

    // Initialize user filters
    initUserFilters();
    
    // Initialize filters for current tab
    const currentTab = localStorage.getItem('vc-active-tab') || 'dashboard';
    initTabFilters(currentTab);
    
    // Initialize sidebar navigation
    initSidebarNav();
    
    // Initialize modals
    initModals();
    
    // Initialize user actions
    initUserActions();
    
    // Initialize skills/interests
    initSkillsInterests();

    // Load categories if on skills/interests tab
    if (currentTab === 'skills' || currentTab === 'interests') {
        loadCategories();
    }
});

// Sidebar toggle functionality
function initSidebarToggle() {
    const toggleBtn = document.querySelector('.vc-sidebar-toggle');
    const sidebar = document.querySelector('.vc-sidebar');
    
    if (!toggleBtn || !sidebar) return;
    
    toggleBtn.addEventListener('click', function() {
        sidebar.classList.toggle('collapsed');
        localStorage.setItem('vc-sidebar-collapsed', sidebar.classList.contains('collapsed'));
    });
    
    // Restore sidebar state
    const savedState = localStorage.getItem('vc-sidebar-collapsed');
    if (savedState === 'true') {
        sidebar.classList.add('collapsed');
    }
}

// Tab functionality
function initTabs() {
    console.log('Initializing tabs...');
    
    const tabBtns = document.querySelectorAll('.vc-tab-btn');
    
    tabBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const tabId = this.getAttribute('data-tab');
            console.log('Tab clicked:', tabId);
            activateTab(tabId);
        });
    });
    
    // Check for hash in URL
    const hash = window.location.hash.substring(1);
    if (hash && document.getElementById(hash)) {
        console.log('URL hash found:', hash);
        activateTab(hash);
    } else {
        // Check localStorage
        const savedTab = localStorage.getItem('vc-active-tab');
        if (savedTab && document.getElementById(savedTab)) {
            console.log('Saved tab found:', savedTab);
            activateTab(savedTab);
        } else {
            // Default to dashboard
            activateTab('dashboard');
        }
    }
}

// Filter functionality for users
function initUserFilters() {
    console.log('Initializing user filters...');
    
    // Get all filter selects and buttons
    const filterSelects = document.querySelectorAll('.vc-filter-select');
    const filterBtns = document.querySelectorAll('.vc-filter-btn');
    const resetBtns = document.querySelectorAll('.vc-filter-btn-reset');
    
    // Initialize filter badges
    initFilterBadges();
    
    // Handle filter select changes
    filterSelects.forEach(select => {
        select.addEventListener('change', function() {
            const tabId = this.closest('.vc-tab-content').id;
            applyFilters(tabId);
        });
    });
    
    // Handle filter button clicks
    filterBtns.forEach(btn => {
        if (!btn.classList.contains('vc-filter-btn-reset')) {
            btn.addEventListener('click', function() {
                const tabId = this.closest('.vc-tab-content').id;
                applyFilters(tabId);
            });
        }
    });
    
    // Handle reset button clicks
    resetBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const tabId = this.closest('.vc-tab-content').id;
            resetFilters(tabId);
        });
    });
    
    // Handle filter badge clicks
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('vc-filter-badge') || e.target.closest('.vc-filter-badge')) {
            const badge = e.target.classList.contains('vc-filter-badge') ? 
                e.target : e.target.closest('.vc-filter-badge');
            
            if (!e.target.classList.contains('vc-filter-badge-close')) {
                const filterType = badge.getAttribute('data-filter');
                const filterValue = badge.getAttribute('data-value');
                const tabId = badge.closest('.vc-tab-content').id;
                
                if (filterType && filterValue) {
                    toggleFilter(tabId, filterType, filterValue);
                }
            }
        }
        
        // Handle close button on badges
        if (e.target.classList.contains('vc-filter-badge-close')) {
            const badge = e.target.closest('.vc-filter-badge');
            const filterType = badge.getAttribute('data-filter');
            const filterValue = badge.getAttribute('data-value');
            const tabId = badge.closest('.vc-tab-content').id;
            
            removeFilter(tabId, filterType, filterValue);
        }
    });
}

// Initialize filter badges
function initFilterBadges() {
    const filterBadgesContainers = document.querySelectorAll('.vc-filter-badges');
    
    filterBadgesContainers.forEach(container => {
        const tabId = container.closest('.vc-tab-content').id;
        const savedFilters = getSavedFilters(tabId);
        
        updateFilterBadges(tabId, savedFilters);
    });
}

// Get saved filters for a tab
function getSavedFilters(tabId) {
    const saved = localStorage.getItem(`vc-filters-${tabId}`);
    return saved ? JSON.parse(saved) : {};
}

// Save filters for a tab
function saveFilters(tabId, filters) {
    localStorage.setItem(`vc-filters-${tabId}`, JSON.stringify(filters));
}

// Apply filters for a tab
function applyFilters(tabId) {
    console.log('Applying filters for:', tabId);
    
    const container = document.getElementById(tabId);
    if (!container) return;
    
    const userTypeSelect = container.querySelector('.vc-filter-select');
    const filters = {};
    
    if (userTypeSelect && userTypeSelect.value) {
        filters.userType = userTypeSelect.value;
    }
    
    // Save filters
    saveFilters(tabId, filters);
    
    // Update UI
    updateFilterBadges(tabId, filters);
    filterTableRows(tabId, filters);
    
    // Update results count
    updateResultsCount(tabId);
}

// Reset filters for a tab
function resetFilters(tabId) {
    console.log('Resetting filters for:', tabId);
    
    const container = document.getElementById(tabId);
    if (!container) return;
    
    // Reset select
    const userTypeSelect = container.querySelector('.vc-filter-select');
    if (userTypeSelect) {
        userTypeSelect.value = '';
    }
    
    // Clear saved filters
    saveFilters(tabId, {});
    
    // Update UI
    updateFilterBadges(tabId, {});
    filterTableRows(tabId, {});
    
    // Update results count
    updateResultsCount(tabId);
}

// Toggle filter
function toggleFilter(tabId, filterType, filterValue) {
    const filters = getSavedFilters(tabId);
    
    if (filters[filterType] === filterValue) {
        delete filters[filterType];
    } else {
        filters[filterType] = filterValue;
    }
    
    saveFilters(tabId, filters);
    updateFilterBadges(tabId, filters);
    filterTableRows(tabId, filters);
    updateResultsCount(tabId);
}

// Remove specific filter
function removeFilter(tabId, filterType, filterValue) {
    const filters = getSavedFilters(tabId);
    
    if (filters[filterType] === filterValue) {
        delete filters[filterType];
    }
    
    saveFilters(tabId, filters);
    updateFilterBadges(tabId, filters);
    filterTableRows(tabId, filters);
    updateResultsCount(tabId);
    
    // Also update select if applicable
    const container = document.getElementById(tabId);
    if (container && filterType === 'userType') {
        const userTypeSelect = container.querySelector('.vc-filter-select');
        if (userTypeSelect) {
            userTypeSelect.value = '';
        }
    }
}

// Update filter badges
function updateFilterBadges(tabId, filters) {
    const container = document.getElementById(tabId);
    if (!container) return;
    
    const badgesContainer = container.querySelector('.vc-filter-badges');
    if (!badgesContainer) return;
    
    // Clear existing badges
    badgesContainer.innerHTML = '';
    
    // Add badges for each active filter
    Object.keys(filters).forEach(filterType => {
        const filterValue = filters[filterType];
        let badgeText = '';
        
        switch(filterType) {
            case 'userType':
                badgeText = filterValue === 'vol' ? 'Volunteers' : 'Organizations';
                break;
            default:
                badgeText = filterValue;
        }
        
        if (badgeText) {
            const badge = document.createElement('div');
            badge.className = 'vc-filter-badge active';
            badge.setAttribute('data-filter', filterType);
            badge.setAttribute('data-value', filterValue);
            badge.innerHTML = `
                ${badgeText}
                <button class="vc-filter-badge-close">&times;</button>
            `;
            badgesContainer.appendChild(badge);
        }
    });
}

// Filter table rows
function filterTableRows(tabId, filters) {
    const container = document.getElementById(tabId);
    if (!container) return;
    
    const table = container.querySelector('table.vc-table');
    if (!table) return;
    
    const rows = table.querySelectorAll('tbody tr');
    let visibleCount = 0;
    
    rows.forEach(row => {
        let showRow = true;
        
        // Apply user type filter
        if (filters.userType) {
            const userType = row.getAttribute('data-user-type');
            if (userType !== filters.userType) {
                showRow = false;
            }
        }
        
        // Show/hide row
        row.style.display = showRow ? '' : 'none';
        if (showRow) visibleCount++;
    });
    
    // Show/hide empty state
    const emptyState = container.querySelector('.vc-empty-filter');
    
    if (visibleCount === 0) {
        if (emptyState) {
            emptyState.style.display = 'block';
        }
        if (table) {
            table.style.display = 'none';
        }
    } else {
        if (emptyState) {
            emptyState.style.display = 'none';
        }
        if (table) {
            table.style.display = 'table';
        }
    }
    
    return visibleCount;
}

// Update results count
function updateResultsCount(tabId) {
    const container = document.getElementById(tabId);
    if (!container) return;
    
    const resultsElement = container.querySelector('.vc-filter-results');
    if (!resultsElement) return;
    
    const table = container.querySelector('table.vc-table');
    if (!table) return;
    
    const allRows = table.querySelectorAll('tbody tr');
    const visibleRows = table.querySelectorAll('tbody tr[style=""]');
    
    resultsElement.textContent = `Showing ${visibleRows.length} of ${allRows.length} users`;
}

// Initialize filters when tab is activated
function initTabFilters(tabId) {
    if (['pending', 'active', 'suspended'].includes(tabId)) {
        // Load saved filters
        const filters = getSavedFilters(tabId);
        
        // Update UI elements
        const container = document.getElementById(tabId);
        if (container) {
            // Update select
            const userTypeSelect = container.querySelector('.vc-filter-select');
            if (userTypeSelect && filters.userType) {
                userTypeSelect.value = filters.userType;
            }
            
            // Update badges
            updateFilterBadges(tabId, filters);
            
            // Apply filters
            filterTableRows(tabId, filters);
            
            // Update results count
            updateResultsCount(tabId);
        }
    }
}

// Activate a specific tab
function activateTab(tabId) {
    console.log('Activating tab:', tabId);
    
    // Update tab buttons
    document.querySelectorAll('.vc-tab-btn').forEach(btn => {
        btn.classList.remove('active');
        if (btn.getAttribute('data-tab') === tabId) {
            btn.classList.add('active');
        }
    });
    
    // Update tab contents
    document.querySelectorAll('.vc-tab-content').forEach(content => {
        content.classList.remove('active');
        if (content.id === tabId) {
            content.classList.add('active');
        }
    });

    // Initialize filters for this tab
    setTimeout(() => {
        initTabFilters(tabId);
    }, 100);
    
    // Update URL
    window.location.hash = tabId;
    
    // Save to localStorage
    localStorage.setItem('vc-active-tab', tabId);
    
    // Update page title
    updatePageTitle(tabId);
    
    // Update sidebar active state
    updateSidebarActive(tabId);

    // Load categories if on skills/interests tab
    if (tabId === 'skills' || tabId === 'interests') {
        setTimeout(() => {
            loadCategories();
        }, 150);
    }
}

// Sidebar navigation
function initSidebarNav() {
    console.log('Initializing sidebar navigation...');
    
    const sidebarLinks = document.querySelectorAll('.vc-nav-item[data-tab]');
    
    sidebarLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            
            const tabId = this.getAttribute('data-tab');
            console.log('Sidebar link clicked:', tabId);
            
            if (tabId) {
                activateTab(tabId);
            }
        });
    });
    
    // Initialize active state based on current tab
    const currentTab = localStorage.getItem('vc-active-tab') || 'dashboard';
    updateSidebarActive(currentTab);
}

// Update sidebar active state
function updateSidebarActive(tabId) {
    console.log('Updating sidebar active state for:', tabId);
    
    const sidebarLinks = document.querySelectorAll('.vc-nav-item[data-tab]');
    
    sidebarLinks.forEach(link => {
        link.classList.remove('active');
        if (link.getAttribute('data-tab') === tabId) {
            link.classList.add('active');
        }
    });
}

// Update page title
function updatePageTitle(tabId) {
    const pageTitles = {
        'dashboard': 'Admin Dashboard',
        'pending': 'Pending Approvals',
        'active': 'Active Users',
        'suspended': 'Suspended Users',
        'skills': 'Skills Management',
        'interests': 'Interests Management',
        'opportunities': 'Opportunities Management'
    };

    const pageSubtitles = {
        'dashboard': 'Manage volunteers, organizations, skills, interests, and opportunities',
        'pending': 'Review and approve pending user registrations',
        'active': 'View and manage all active users in the system',
        'suspended': 'Manage suspended user accounts',
        'skills': 'Add, edit, and manage volunteer skills',
        'interests': 'Add, edit, and manage volunteer interests',
        'opportunities': 'View and manage volunteer opportunities'
    };

    const titleElement = document.getElementById('vcPageTitle');
    const subtitleElement = document.getElementById('vcPageSubtitle');
    
    if (titleElement && subtitleElement) {
        titleElement.textContent = pageTitles[tabId] || 'Admin Dashboard';
        subtitleElement.textContent = pageSubtitles[tabId] || 'Manage the volunteer connection system';
        document.title = `${pageTitles[tabId]} - Volunteer Connect Admin`;
    }
}

// Modal functions
function initModals() {
    // Close modal when clicking outside
    const modals = document.querySelectorAll('.vc-modal');
    modals.forEach(modal => {
        modal.addEventListener('click', function(event) {
            if (event.target === modal) {
                modal.style.display = 'none';
                document.body.style.overflow = '';
            }
        });
    });
    
    // Close modal with Escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            document.querySelectorAll('.vc-modal').forEach(modal => {
                if (modal.style.display === 'flex') {
                    modal.style.display = 'none';
                    document.body.style.overflow = '';
                }
            });
        }
    });
}

function openEditModal(type, id, name, email, extraLabel, extraValue) {
    const modal = document.getElementById('vcEditModal');
    if (!modal) return;
    
    // Set form values
    document.getElementById('vcEditType').value = type;
    document.getElementById('vcEditId').value = id;
    document.getElementById('vcEditName').value = name;
    document.getElementById('vcEditEmail').value = email;
    
    // Set extra field
    const extraFieldDiv = document.getElementById('vcExtraField');
    if (type === 'volunteer' || type === 'vol') {
        extraFieldDiv.innerHTML = `
            <div class="vc-form-group">
                <label class="vc-form-label">Location:</label>
                <input type="text" class="vc-form-control" name="location" value="${extraValue || ''}">
            </div>
        `;
    } else if (type === 'organization' || type === 'org') {
        extraFieldDiv.innerHTML = `
            <div class="vc-form-group">
                <label class="vc-form-label">Contact Info:</label>
                <input type="text" class="vc-form-control" name="contact_info" value="${extraValue || ''}">
            </div>
        `;
    }
    
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeEditModal() {
    const modal = document.getElementById('vcEditModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
}

// Skill functions (for PHP onclick calls)
function editSkill(skillId, skillName, categoryId) {
    document.getElementById('vcEditSkillId').value = skillId;
    document.getElementById('vcEditSkillName').value = skillName;
    document.getElementById('vcEditSkillCategory').value = categoryId || '';
    document.getElementById('vcEditSkillModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeEditSkillModal() {
    const modal = document.getElementById('vcEditSkillModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
}

// Interest functions (for PHP onclick calls)
function editInterest(interestId, interestName, categoryId) {
    document.getElementById('vcEditInterestId').value = interestId;
    document.getElementById('vcEditInterestName').value = interestName;
    document.getElementById('vcEditInterestCategory').value = categoryId || '';
    document.getElementById('vcEditInterestModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeEditInterestModal() {
    const modal = document.getElementById('vcEditInterestModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
}

// User action confirmations
function initUserActions() {
    document.addEventListener('click', function(event) {
        if (event.target.matches('[data-confirm]') || event.target.closest('[data-confirm]')) {
            const element = event.target.matches('[data-confirm]') ? 
                event.target : event.target.closest('[data-confirm]');
            const message = element.getAttribute('data-confirm-message') || 
                'Are you sure you want to perform this action?';
            
            if (!confirm(message)) {
                event.preventDefault();
                event.stopPropagation();
            }
        }
    });
}

// Skills/Interests management
function initSkillsInterests() {
    // Handle add skill form
    const addSkillForm = document.getElementById('vcAddSkillForm');
    if (addSkillForm) {
        addSkillForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';
            submitBtn.disabled = true;
            
            // Submit via traditional form submission (not AJAX)
            this.submit();
        });
    }
    
    // Handle add interest form
    const addInterestForm = document.getElementById('vcAddInterestForm');
    if (addInterestForm) {
        addInterestForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';
            submitBtn.disabled = true;
            
            // Submit via traditional form submission
            this.submit();
        });
    }
    
    // Handle delete skill/interest
    document.addEventListener('click', function(e) {
        // Delete skill
        if (e.target.closest('.vc-delete-skill')) {
            const element = e.target.closest('.vc-delete-skill');
            const skillId = element.dataset.skillId;
            
            if (confirm('Are you sure you want to delete this skill?')) {
                fetch(`admin_skills_action.php?action=delete&id=${skillId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            element.closest('.vc-skill-item').remove();
                        } else {
                            alert(data.message || 'Error deleting skill');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('An error occurred');
                    });
            }
            e.preventDefault();
        }
        
        // Delete interest
        if (e.target.closest('.vc-delete-interest')) {
            const element = e.target.closest('.vc-delete-interest');
            const interestId = element.dataset.interestId;
            
            if (confirm('Are you sure you want to delete this interest?')) {
                fetch(`admin_interests_action.php?action=delete&id=${interestId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            element.closest('.vc-interest-item').remove();
                        } else {
                            alert(data.message || 'Error deleting interest');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('An error occurred');
                    });
            }
            e.preventDefault();
        }
    });
}

// Load categories for select inputs
async function loadCategories() {
    try {
        // Check if we need skill categories
        const skillCategorySelect = document.getElementById('vcSkillCategory');
        const editSkillCategorySelect = document.getElementById('vcEditSkillCategory');
        
        if (skillCategorySelect || editSkillCategorySelect) {
            const skillsRes = await fetch('get_categories.php?type=skills');
            const skillsData = await skillsRes.json();
            
            if (skillsData.success) {
                // Populate skill categories select
                if (skillCategorySelect) {
                    skillCategorySelect.innerHTML = '<option value="">Select Category</option>' +
                        skillsData.categories.map(cat => 
                            `<option value="${cat.category_id}">${cat.category_name}</option>`
                        ).join('');
                }
                
                // Populate edit skill categories select
                if (editSkillCategorySelect) {
                    editSkillCategorySelect.innerHTML = '<option value="">No Category</option>' +
                        skillsData.categories.map(cat => 
                            `<option value="${cat.category_id}">${cat.category_name}</option>`
                        ).join('');
                }
            }
        }
        
        // Check if we need interest categories
        const interestCategorySelect = document.getElementById('vcInterestCategory');
        const editInterestCategorySelect = document.getElementById('vcEditInterestCategory');
        
        if (interestCategorySelect || editInterestCategorySelect) {
            const interestsRes = await fetch('get_categories.php?type=interests');
            const interestsData = await interestsRes.json();
            
            if (interestsData.success) {
                // Populate interest categories select
                if (interestCategorySelect) {
                    interestCategorySelect.innerHTML = '<option value="">Select Category</option>' +
                        interestsData.categories.map(cat => 
                            `<option value="${cat.category_id}">${cat.category_name}</option>`
                        ).join('');
                }
                
                // Populate edit interest categories select
                if (editInterestCategorySelect) {
                    editInterestCategorySelect.innerHTML = '<option value="">No Category</option>' +
                        interestsData.categories.map(cat => 
                            `<option value="${cat.category_id}">${cat.category_name}</option>`
                        ).join('');
                }
            }
        }
    } catch (error) {
        console.error('Error loading categories:', error);
    }
}

// Add this function to test if JavaScript is working
function testSidebar() {
    console.log('Testing sidebar...');
    
    // Check if elements exist
    const sidebarLinks = document.querySelectorAll('.vc-nav-item[data-tab]');
    console.log('Found sidebar links:', sidebarLinks.length);
    
    sidebarLinks.forEach(link => {
        console.log('Link:', link.getAttribute('data-tab'), link);
    });
    
    // Manually add click events as a test
    sidebarLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            console.log('Manual test: Link clicked -', this.getAttribute('data-tab'));
            alert('Sidebar clicked: ' + this.getAttribute('data-tab'));
        });
    });
}

// Utility functions
function showLoading(button) {
    const originalText = button.innerHTML;
    button.innerHTML = '<span class="vc-loading"></span>';
    button.disabled = true;
    return originalText;
}

function hideLoading(button, originalText) {
    button.innerHTML = originalText;
    button.disabled = false;
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

function filterTable(inputId, tableId) {
    const input = document.getElementById(inputId);
    const table = document.getElementById(tableId);
    
    if (!input || !table) return;
    
    input.addEventListener('input', function() {
        const filter = this.value.toLowerCase();
        const rows = table.querySelectorAll('tbody tr');
        
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(filter) ? '' : 'none';
        });
    });
}

// Opportunity View Functions
function viewOpportunity(opportunityId) {
    console.log('Viewing opportunity:', opportunityId);
    
    // Show loading state
    const modal = document.getElementById('vcOpportunityModal');
    const loading = document.getElementById('opportunityLoading');
    const content = document.getElementById('opportunityContent');
    
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    loading.style.display = 'flex';
    content.style.display = 'none';
    
    // Fetch opportunity details
    fetch(`get_opportunity_details.php?id=${opportunityId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayOpportunityDetails(data.opportunity);
                loading.style.display = 'none';
                content.style.display = 'block';
            } else {
                alert('Failed to load opportunity details');
                closeOpportunityModal();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading opportunity details');
            closeOpportunityModal();
        });
}

function displayOpportunityDetails(opp) {
    const content = document.getElementById('opportunityContent');
    
    // Format dates
    const createdDate = new Date(opp.created_at).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
    
    const startDate = opp.start_date ? new Date(opp.start_date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric'
    }) : 'TBD';
    
    const endDate = opp.end_date && opp.end_date !== opp.start_date ? 
        new Date(opp.end_date).toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric'
        }) : 'Same day';
    
    // Format times
    const startTime = opp.start_time ? new Date(`2000-01-01T${opp.start_time}`).toLocaleTimeString('en-US', {
        hour: '2-digit',
        minute: '2-digit'
    }) : 'Not specified';
    
    const endTime = opp.end_time ? new Date(`2000-01-01T${opp.end_time}`).toLocaleTimeString('en-US', {
        hour: '2-digit',
        minute: '2-digit'
    }) : 'Not specified';
    
    // Build HTML
    let html = `
        <div class="vc-opportunity-header">
            <h2 class="vc-opportunity-title">${escapeHtml(opp.title)}</h2>
            <div class="vc-opportunity-org">
                <i class="fas fa-building"></i>
                ${escapeHtml(opp.org_name || opp.org_username || 'Unknown Organization')}
            </div>
            <span class="vc-opportunity-status-badge">${escapeHtml(opp.status)}</span>
            
            <div class="vc-opportunity-meta">
                <div class="vc-opportunity-meta-item">
                    <div class="vc-opportunity-meta-icon"><i class="fas fa-calendar"></i></div>
                    <span>${startDate}${opp.end_date && opp.end_date !== opp.start_date ? ' - ' + endDate : ''}</span>
                </div>
                <div class="vc-opportunity-meta-item">
                    <div class="vc-opportunity-meta-icon"><i class="fas fa-clock"></i></div>
                    <span>${startTime} - ${endTime}</span>
                </div>
                <div class="vc-opportunity-meta-item">
                    <div class="vc-opportunity-meta-icon"><i class="fas fa-map-marker-alt"></i></div>
                    <span>${escapeHtml(opp.location_name || opp.city || 'Location not specified')}</span>
                </div>
            </div>
        </div>
        
        <div class="vc-opportunity-body">
    `;
    
    // Image if available
    if (opp.image_url) {
        html += `
            <div class="vc-opportunity-image">
                <img src="${opp.image_url}" alt="${escapeHtml(opp.title)}" onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\"vc-opportunity-image-placeholder\"><i class=\"fas fa-image\"></i></div>';">
            </div>
        `;
    } else {
        html += `
            <div class="vc-no-image">
                <i class="fas fa-image"></i> No image available
            </div>
        `;
    }
    
    // Brief Summary
    if (opp.brief_summary) {
        html += `
            <div class="vc-opportunity-section">
                <div class="vc-opportunity-section-title">
                    <i class="fas fa-info-circle"></i>
                    Brief Summary
                </div>
                <div class="vc-opportunity-section-content">
                    ${escapeHtml(opp.brief_summary)}
                </div>
            </div>
        `;
    }
    
    // Description
    html += `
        <div class="vc-opportunity-section">
            <div class="vc-opportunity-section-title">
                <i class="fas fa-align-left"></i>
                Description
            </div>
            <div class="vc-opportunity-section-content">
                ${escapeHtml(opp.description || 'No description provided.')}
            </div>
        </div>
    `;
    
    // Details Grid
    html += `
        <div class="vc-opportunity-details-grid">
            <div class="vc-opportunity-detail-item">
                <div class="vc-opportunity-detail-label">Volunteers Needed</div>
                <div class="vc-opportunity-detail-value">${opp.number_of_volunteers || 'Not specified'}</div>
            </div>
            <div class="vc-opportunity-detail-item">
                <div class="vc-opportunity-detail-label">Minimum Age</div>
                <div class="vc-opportunity-detail-value">${opp.min_age ? opp.min_age + ' years' : 'Not specified'}</div>
            </div>
            <div class="vc-opportunity-detail-item">
                <div class="vc-opportunity-detail-label">Application Deadline</div>
                <div class="vc-opportunity-detail-value">${opp.application_deadline ? new Date(opp.application_deadline).toLocaleDateString() : 'Not specified'}</div>
            </div>
            <div class="vc-opportunity-detail-item">
                <div class="vc-opportunity-detail-label">Created On</div>
                <div class="vc-opportunity-detail-value">${createdDate}</div>
            </div>
        </div>
    `;
    
    // Location Details
    if (opp.city || opp.state || opp.country) {
        let locationDetails = [];
        if (opp.city) locationDetails.push(opp.city);
        if (opp.state) locationDetails.push(opp.state);
        if (opp.country) locationDetails.push(opp.country);
        
        html += `
            <div class="vc-opportunity-section">
                <div class="vc-opportunity-section-title">
                    <i class="fas fa-map-pin"></i>
                    Location Details
                </div>
                <div class="vc-opportunity-section-content">
                    ${escapeHtml(locationDetails.join(', ') || 'Location not specified')}
                </div>
            </div>
        `;
    }
    
    // Requirements
    if (opp.requirements) {
        html += `
            <div class="vc-opportunity-section">
                <div class="vc-opportunity-section-title">
                    <i class="fas fa-list-check"></i>
                    Requirements
                </div>
                <div class="vc-opportunity-section-content">
                    ${escapeHtml(opp.requirements)}
                </div>
            </div>
        `;
    }
    
    // Benefits
    if (opp.benefits) {
        html += `
            <div class="vc-opportunity-section">
                <div class="vc-opportunity-section-title">
                    <i class="fas fa-gift"></i>
                    Benefits
                </div>
                <div class="vc-opportunity-section-content">
                    ${escapeHtml(opp.benefits)}
                </div>
            </div>
        `;
    }
    
    // Safety Notes
    if (opp.safety_notes) {
        html += `
            <div class="vc-opportunity-section">
                <div class="vc-opportunity-section-title">
                    <i class="fas fa-shield-alt"></i>
                    Safety Notes
                </div>
                <div class="vc-opportunity-section-content">
                    ${escapeHtml(opp.safety_notes)}
                </div>
            </div>
        `;
    }
    
    // Transportation Info
    if (opp.transportation_info) {
        html += `
            <div class="vc-opportunity-section">
                <div class="vc-opportunity-section-title">
                    <i class="fas fa-bus"></i>
                    Transportation Information
                </div>
                <div class="vc-opportunity-section-content">
                    ${escapeHtml(opp.transportation_info)}
                </div>
            </div>
        `;
    }
    
    // Contact Information
    if (opp.contact_person_name || opp.contact_person_email || opp.contact_person_phone) {
        html += `
            <div class="vc-opportunity-section">
                <div class="vc-opportunity-section-title">
                    <i class="fas fa-address-book"></i>
                    Contact Information
                </div>
                <div class="vc-opportunity-section-content">
        `;
        
        if (opp.contact_person_name) {
            html += `<p><strong>Name:</strong> ${escapeHtml(opp.contact_person_name)}</p>`;
        }
        if (opp.contact_person_email) {
            html += `<p><strong>Email:</strong> <a href="mailto:${escapeHtml(opp.contact_person_email)}">${escapeHtml(opp.contact_person_email)}</a></p>`;
        }
        if (opp.contact_person_phone) {
            html += `<p><strong>Phone:</strong> ${escapeHtml(opp.contact_person_phone)}</p>`;
        }
        
        html += `
                </div>
            </div>
        `;
    }
    
    // Danger Zone (Admin Actions)
    html += `
        <div class="vc-danger-zone">
            <div class="vc-danger-zone-title">
                <i class="fas fa-exclamation-triangle"></i>
                Admin Actions
            </div>
            <div class="vc-danger-zone-actions">
    `;
    
    if (opp.status === 'open') {
        html += `
            <button onclick="suspendOpportunity(${opp.opportunity_id}, true)" 
                    class="vc-btn vc-btn-suspend"
                    data-confirm="true" 
                    data-confirm-message="Suspend this opportunity?">
                <i class="fas fa-pause-circle"></i> Suspend Opportunity
            </button>
        `;
    } else if (opp.status === 'suspended') {
        html += `
            <button onclick="reactivateOpportunity(${opp.opportunity_id}, true)" 
                    class="vc-btn vc-btn-approve"
                    data-confirm="true" 
                    data-confirm-message="Reactivate this opportunity?">
                <i class="fas fa-play-circle"></i> Reactivate Opportunity
            </button>
        `;
    }

    html += `
                <button onclick="deleteOpportunity(${opp.opportunity_id}, true)" 
                        class="vc-btn vc-btn-delete"
                        data-confirm="true" 
                        data-confirm-message="Delete this opportunity permanently? This action cannot be undone.">
                    <i class="fas fa-trash"></i> Delete Permanently
                </button>
            </div>
        </div>
    `;
    
    html += `</div>`;
    
    content.innerHTML = html;
}

function closeOpportunityModal() {
    const modal = document.getElementById('vcOpportunityModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
}

// Opportunity Actions
function suspendOpportunity(opportunityId, fromModal = false) {
    if (confirm('Are you sure you want to suspend this opportunity?')) {
        fetch(`admin_opportunity_action.php?action=suspend&id=${opportunityId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Opportunity suspended successfully');
                    if (fromModal) {
                        closeOpportunityModal();
                    }
                    // Refresh the table row
                    updateOpportunityStatus(opportunityId, 'suspended');
                } else {
                    alert(data.message || 'Failed to suspend opportunity');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error suspending opportunity');
            });
    }
}

function reactivateOpportunity(opportunityId, fromModal = false) {
    if (confirm('Are you sure you want to reactivate this opportunity?')) {
        fetch(`admin_opportunity_action.php?action=reactivate&id=${opportunityId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Opportunity reactivated successfully');
                    if (fromModal) {
                        closeOpportunityModal();
                    }
                    // Refresh the table row
                    updateOpportunityStatus(opportunityId, 'open');
                } else {
                    alert(data.message || 'Failed to reactivate opportunity');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error reactivating opportunity');
            });
    }
}

function deleteOpportunity(opportunityId, fromModal = false) {
    if (confirm('Are you sure you want to delete this opportunity permanently? This action cannot be undone.')) {
        fetch(`admin_opportunity_action.php?action=delete&id=${opportunityId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Opportunity deleted successfully');
                    if (fromModal) {
                        closeOpportunityModal();
                    }
                    // Remove the table row
                    removeOpportunityRow(opportunityId);
                } else {
                    alert(data.message || 'Failed to delete opportunity');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error deleting opportunity');
            });
    }
}

// Helper functions
function updateOpportunityStatus(opportunityId, newStatus) {
    const row = document.querySelector(`tr[data-opportunity-id="${opportunityId}"]`);
    if (row) {
        const statusCell = row.querySelector('.vc-opportunity-status');
        if (statusCell) {
            statusCell.textContent = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
            statusCell.className = `vc-opportunity-status vc-status-${newStatus}`;
            
            // Update action buttons
            const actionButtons = row.querySelector('.vc-action-buttons');
            if (actionButtons) {
                if (newStatus === 'suspended') {
                    actionButtons.innerHTML = `
                        <button onclick="viewOpportunity(${opportunityId})" 
                                class="vc-btn vc-btn-sm vc-btn-edit">
                            <i class="fas fa-eye"></i> View
                        </button>
                        <button onclick="reactivateOpportunity(${opportunityId})" 
                                class="vc-btn vc-btn-sm vc-btn-approve"
                                data-confirm="true" 
                                data-confirm-message="Reactivate this opportunity?">
                            <i class="fas fa-play-circle"></i> Reactivate
                        </button>
                        <button onclick="deleteOpportunity(${opportunityId})" 
                                class="vc-btn vc-btn-sm vc-btn-delete"
                                data-confirm="true" 
                                data-confirm-message="Delete this opportunity permanently?">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    `;
                } else if (newStatus === 'open') {
                    actionButtons.innerHTML = `
                        <button onclick="viewOpportunity(${opportunityId})" 
                                class="vc-btn vc-btn-sm vc-btn-edit">
                            <i class="fas fa-eye"></i> View
                        </button>
                        <button onclick="suspendOpportunity(${opportunityId})" 
                                class="vc-btn vc-btn-sm vc-btn-suspend"
                                data-confirm="true" 
                                data-confirm-message="Suspend this opportunity?">
                            <i class="fas fa-pause-circle"></i> Suspend
                        </button>
                        <button onclick="deleteOpportunity(${opportunityId})" 
                                class="vc-btn vc-btn-sm vc-btn-delete"
                                data-confirm="true" 
                                data-confirm-message="Delete this opportunity permanently?">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    `;
                }
            }
        }
    }
}

function removeOpportunityRow(opportunityId) {
    const row = document.querySelector(`tr[data-opportunity-id="${opportunityId}"]`);
    if (row) {
        row.remove();
    }
}

// Utility function to escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// User Action Functions with Confirmation and AJAX
function approveUser(userId, userType, userName) {
    if (confirm(`Are you sure you want to approve "${userName}"? This user will be able to access the system.`)) {
        performUserAction('approve', userId, userType, userName);
    }
}

function rejectUser(userId, userType, userName) {
    if (confirm(`Are you sure you want to reject "${userName}"? This action cannot be undone and the user will be removed from the system.`)) {
        performUserAction('reject', userId, userType, userName);
    }
}

function suspendUser(userId, userType, userName, fromStatus) {
    const message = fromStatus === 'pending' 
        ? `Are you sure you want to suspend "${userName}"? This will prevent the user from accessing the system.`
        : `Are you sure you want to suspend "${userName}"? This user will lose access to the system.`;
    
    if (confirm(message)) {
        performUserAction('suspend', userId, userType, userName);
    }
}

function restoreUser(userId, userType, userName) {
    if (confirm(`Are you sure you want to restore "${userName}"? This user will regain access to the system.`)) {
        performUserAction('restore', userId, userType, userName);
    }
}

function deleteUser(userId, userType, userName) {
    if (confirm(`PERMANENT DELETE: Are you sure you want to permanently delete "${userName}"? This action cannot be undone and all user data will be lost.`)) {
        performUserAction('delete', userId, userType, userName);
    }
}

function performUserAction(action, userId, userType, userName) {
    // Show loading state
    const originalTitle = document.title;
    document.title = "Processing... - " + originalTitle.split(" - ")[1];
    
    // Create a loading indicator
    const loadingDiv = document.createElement('div');
    loadingDiv.id = 'vcActionLoading';
    loadingDiv.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 18px;
    `;
    loadingDiv.innerHTML = `
        <div style="text-align: center;">
            <div style="font-size: 48px; margin-bottom: 20px;"><i class="fas fa-spinner fa-spin"></i></div>
            <div>Processing ${action} for ${userName}...</div>
        </div>
    `;
    document.body.appendChild(loadingDiv);
    
    // Send AJAX request
    fetch(`admin_user_action.php?action=${action}&type=${userType}&id=${userId}`)
        .then(response => {
            // Check if it's a redirect (non-AJAX response)
            if (response.redirected) {
                window.location.href = response.url;
                return;
            }
            return response.text();
        })
        .then(responseText => {
            // Remove loading indicator
            document.body.removeChild(loadingDiv);
            document.title = originalTitle;
            
            try {
                // Try to parse as JSON (for AJAX responses)
                const data = JSON.parse(responseText);
                
                if (data.success) {
                    showAlert('success', data.message || `${action.charAt(0).toUpperCase() + action.slice(1)} successful!`);
                    
                    // Remove the row from the table
                    removeUserRow(userId);
                    
                    // Update the stats
                    updateStatsAfterAction(action);
                    
                } else {
                    showAlert('error', data.message || `Failed to ${action} user`);
                }
            } catch (e) {
                // If not JSON, it's a regular page - reload to show flash messages
                window.location.reload();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.body.removeChild(loadingDiv);
            document.title = originalTitle;
            showAlert('error', 'An error occurred. Please try again.');
        });
}

function removeUserRow(userId) {
    // Find and remove the user row from all tables
    const rows = document.querySelectorAll(`tr[data-user-id="${userId}"]`);
    rows.forEach(row => {
        row.style.backgroundColor = '#f8d7da';
        setTimeout(() => {
            row.style.opacity = '0.5';
            setTimeout(() => row.remove(), 300);
        }, 100);
    });
    
    // If no rows left, show empty state
    setTimeout(() => {
        const tables = ['pending-table', 'active-table', 'suspended-table'];
        tables.forEach(tableId => {
            const table = document.getElementById(tableId);
            if (table && table.rows.length <= 1) { // Only header row left
                const tabContent = table.closest('.vc-tab-content');
                if (tabContent) {
                    const emptyState = tabContent.querySelector('.vc-empty-state');
                    if (emptyState) emptyState.style.display = 'block';
                    
                    const filterEmpty = tabContent.querySelector('.vc-empty-filter');
                    if (filterEmpty) filterEmpty.style.display = 'none';
                    
                    table.style.display = 'none';
                }
            }
        });
    }, 500);
}

function updateStatsAfterAction(action) {
    // Update the stats cards based on the action
    const statCards = {
        'pending': document.querySelector('.vc-stat-pending .vc-stat-value'),
        'suspended': document.querySelector('.vc-stat-suspended .vc-stat-value'),
        'volunteers': document.querySelector('.vc-stat-volunteers .vc-stat-value'),
        'organizations': document.querySelector('.vc-stat-organizations .vc-stat-value')
    };
    
    // Simulate update (in real app, you'd fetch new stats)
    Object.keys(statCards).forEach(key => {
        if (statCards[key]) {
            const current = parseInt(statCards[key].textContent) || 0;
            
            switch(action) {
                case 'approve':
                    if (key === 'pending') statCards[key].textContent = current - 1;
                    // Would need to know if volunteer or org to update those
                    break;
                case 'reject':
                case 'delete':
                    if (key === 'pending') statCards[key].textContent = current - 1;
                    break;
                case 'suspend':
                    if (key === 'pending' || key === 'active') {
                        // Would need context to know which
                    }
                    if (key === 'suspended') statCards[key].textContent = current + 1;
                    break;
                case 'restore':
                    if (key === 'suspended') statCards[key].textContent = current - 1;
                    break;
            }
        }
    });
}

// Add data-user-id attribute to table rows for easier removal
document.addEventListener('DOMContentLoaded', function() {
    // Add user-id to all user rows
    document.querySelectorAll('.vc-table tbody tr').forEach(row => {
        const actionLinks = row.querySelectorAll('a[href*="admin_user_action.php"]');
        actionLinks.forEach(link => {
            const url = new URL(link.href, window.location.origin);
            const userId = url.searchParams.get('id');
            if (userId) {
                row.setAttribute('data-user-id', userId);
            }
        });
    });
    
    // Also add onclick handlers to existing links
    document.querySelectorAll('a[href*="admin_user_action.php"]').forEach(link => {
        const url = new URL(link.href, window.location.origin);
        const action = url.searchParams.get('action');
        const userId = url.searchParams.get('id');
        const userType = url.searchParams.get('type');
        
        if (action && userId && userType) {
            const row = link.closest('tr');
            const userName = row.querySelector('td strong')?.textContent || 'User';
            
            link.onclick = function(e) {
                e.preventDefault();
                
                const messages = {
                    'approve': `Are you sure you want to approve "${userName}"?`,
                    'reject': `Are you sure you want to reject "${userName}"? This cannot be undone.`,
                    'suspend': `Are you sure you want to suspend "${userName}"?`,
                    'restore': `Are you sure you want to restore "${userName}"?`,
                    'delete': `PERMANENT DELETE: Are you sure you want to delete "${userName}"? This cannot be undone.`
                };
                
                if (confirm(messages[action] || `Are you sure you want to ${action} this user?`)) {
                    performUserAction(action, userId, userType, userName);
                }
            };
            
            // Remove the href to prevent navigation
            link.href = '#';
        }
    });
});