// Auto-dismiss alerts
const alerts = document.querySelectorAll('.vc-alert');
alerts.forEach(alert => {
    const timeout = alert.dataset.timeout || 10; // seconds
    setTimeout(() => {
        alert.style.transition = "opacity 0.5s ease";
        alert.style.opacity = 0;
        setTimeout(() => alert.remove(), 500);
    }, timeout * 1000);
});


class OrganizationDashboard {
    constructor() {
        this.initCharts();
        this.initFilters();
        this.initNotifications();
    }
    
    initCharts() {
        // Initialize progress charts if needed
        const progressBars = document.querySelectorAll('.vc-progress-fill');
        progressBars.forEach(bar => {
            const width = bar.style.width;
            bar.style.width = '0';
            setTimeout(() => {
                bar.style.width = width;
            }, 300);
        });
    }
    
    initFilters() {
        // Add search functionality to opportunities
        const searchInput = document.createElement('input');
        searchInput.type = 'text';
        searchInput.placeholder = 'Search opportunities...';
        searchInput.className = 'vc-search-input';
        searchInput.style.cssText = `
            padding: 10px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            width: 300px;
            margin-bottom: 20px;
        `;
        
        const sectionHeader = document.querySelector('.vc-section-header');
        if (sectionHeader) {
            sectionHeader.parentNode.insertBefore(searchInput, sectionHeader.nextSibling);
            
            searchInput.addEventListener('input', (e) => {
                const searchTerm = e.target.value.toLowerCase();
                const cards = document.querySelectorAll('.vc-opportunity-card');
                
                cards.forEach(card => {
                    const title = card.querySelector('.vc-opportunity-title').textContent.toLowerCase();
                    const desc = card.querySelector('.vc-opportunity-desc').textContent.toLowerCase();
                    
                    if (title.includes(searchTerm) || desc.includes(searchTerm)) {
                        card.style.display = 'block';
                        setTimeout(() => {
                            card.style.opacity = '1';
                            card.style.transform = 'translateY(0)';
                        }, 50);
                    } else {
                        card.style.opacity = '0';
                        card.style.transform = 'translateY(20px)';
                        setTimeout(() => {
                            card.style.display = 'none';
                        }, 300);
                    }
                });
            });
        }
    }
    
    initNotifications() {
        // Check for new applications periodically
        if (window.Notification && Notification.permission === 'granted') {
            setInterval(() => {
                this.checkNewApplications();
            }, 300000); // Every 5 minutes
        }
    }
    
    checkNewApplications() {
        // AJAX call to check for new applications
        fetch('/volcon/api/check-new-applications.php')
            .then(response => response.json())
            .then(data => {
                if (data.new_applications > 0) {
                    this.showNotification(`You have ${data.new_applications} new application(s)!`);
                }
            });
    }
    
    showNotification(message) {
        if (window.Notification && Notification.permission === 'granted') {
            new Notification('VolCon Dashboard', {
                body: message,
                icon: '/volcon/assets/images/logo.png'
            });
        } else {
            // Fallback to browser notification
            const notification = document.createElement('div');
            notification.className = 'vc-browser-notification';
            notification.textContent = message;
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: #10b981;
                color: white;
                padding: 15px 20px;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                z-index: 1000;
                animation: slideIn 0.3s ease;
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => notification.remove(), 300);
            }, 5000);
        }
    }
}

// Initialize dashboard when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    new OrganizationDashboard();
    
    // Add CSS animations
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
        
        .vc-opportunity-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .vc-progress-fill {
            transition: width 0.8s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
    `;
    document.head.appendChild(style);
});


