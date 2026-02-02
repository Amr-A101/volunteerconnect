
class ApplicationsDashboard {
    constructor() {
        this.initFilters();
        this.initSorting();
        this.initTooltips();
        this.initTimelineInteractions();
    }
    
    initFilters() {
        // Real-time search filtering
        const searchInput = document.querySelector('.vc-search-input');
        if (searchInput) {
            let debounceTimer;
            searchInput.addEventListener('input', (e) => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    this.applyFilters();
                }, 300);
            });
        }
        
        // Date filter clicks
        document.querySelectorAll('.vc-date-option').forEach(option => {
            option.addEventListener('click', (e) => {
                e.preventDefault();
                document.querySelectorAll('.vc-date-option').forEach(opt => {
                    opt.classList.remove('active');
                });
                option.classList.add('active');
                this.applyFilters();
            });
        });
    }
    
    initSorting() {
        // Add sort dropdown if needed
        const sortContainer = document.createElement('div');
        sortContainer.className = 'vc-sort-controls';
        sortContainer.innerHTML = `
            <select class="vc-sort-select">
                <option value="newest">Newest First</option>
                <option value="oldest">Oldest First</option>
                <option value="status">By Status</option>
            </select>
        `;
        
        const header = document.querySelector('.vc-apps-header');
        if (header) {
            header.appendChild(sortContainer);
            
            const sortSelect = sortContainer.querySelector('.vc-sort-select');
            sortSelect.addEventListener('change', () => {
                this.sortApplications(sortSelect.value);
            });
        }
    }
    
    initTooltips() {
        // Initialize tooltips for status badges
        const tooltips = document.querySelectorAll('.vc-tooltip');
        tooltips.forEach(tooltip => {
            tooltip.addEventListener('mouseenter', (e) => {
                const rect = tooltip.getBoundingClientRect();
                const tooltipText = tooltip.querySelector('.vc-tooltip-text');
                
                // Position tooltip
                tooltipText.style.left = '50%';
                tooltipText.style.transform = 'translateX(-50%)';
                
                // Show tooltip
                tooltipText.style.visibility = 'visible';
                tooltipText.style.opacity = '1';
            });
            
            tooltip.addEventListener('mouseleave', () => {
                const tooltipText = tooltip.querySelector('.vc-tooltip-text');
                tooltipText.style.visibility = 'hidden';
                tooltipText.style.opacity = '0';
            });
        });
    }
    
    initTimelineInteractions() {
        // Add click to expand timeline details
        const timelines = document.querySelectorAll('.vc-app-timeline');
        timelines.forEach(timeline => {
            timeline.addEventListener('click', () => {
                timeline.classList.toggle('expanded');
            });
        });
    }
    
    applyFilters() {
        const url = new URL(window.location);
        const search = document.querySelector('.vc-search-input')?.value || '';
        const activeDate = document.querySelector('.vc-date-option.active')?.dataset.value || '';
        
        url.searchParams.set('search', search);
        url.searchParams.set('date', activeDate);
        url.searchParams.set('page', 1); // Reset to first page
        
        // Show loading state
        this.showLoading();
        
        // Navigate to filtered page
        window.location.href = url.toString();
    }
    
    showLoading() {
        const container = document.querySelector('.vc-applications-grid');
        if (container) {
            const loadingDiv = document.createElement('div');
            loadingDiv.className = 'vc-loading';
            loadingDiv.innerHTML = `
                <div class="vc-loading-spinner"></div>
                <span>Loading applications...</span>
            `;
            container.innerHTML = '';
            container.appendChild(loadingDiv);
        }
    }
    
    sortApplications(sortBy) {
        const cards = Array.from(document.querySelectorAll('.vc-app-card'));
        const container = document.querySelector('.vc-applications-grid');
        
        cards.sort((a, b) => {
            switch (sortBy) {
                case 'oldest':
                    return new Date(a.dataset.applied) - new Date(b.dataset.applied);
                case 'status':
                    const statusOrder = { pending: 1, shortlisted: 2, accepted: 3, rejected: 4, withdrawn: 5 };
                    return statusOrder[a.dataset.status] - statusOrder[b.dataset.status];
                default: // newest
                    return new Date(b.dataset.applied) - new Date(a.dataset.applied);
            }
        });
        
        // Re-append sorted cards
        cards.forEach(card => container.appendChild(card));
    }
    
    exportApplications(format = 'csv') {
        // Implementation for exporting applications
        console.log(`Exporting applications in ${format} format`);
        // You can implement CSV/PDF export here
    }
}

// Initialize when page loads
document.addEventListener('DOMContentLoaded', () => {
    window.applicationsDashboard = new ApplicationsDashboard();
    
    // Add CSS for animations
    const style = document.createElement('style');
    style.textContent = `
        .vc-app-card {
            animation: slideIn 0.3s ease-out;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .vc-timeline-step {
            transition: all 0.3s ease;
        }
        
        .vc-timeline-step:hover {
            transform: translateX(5px);
        }
        
        .vc-app-timeline.expanded {
            max-height: 500px;
        }
    `;
    document.head.appendChild(style);
});