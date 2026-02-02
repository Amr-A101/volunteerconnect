// vc-reports.js
let charts = {};

document.addEventListener('DOMContentLoaded', function() {
    console.log('Reports page loaded - DOM ready');
    
    // Initialize date range first
    initDateRange();
    
    // Setup event listeners
    setupEventListeners();
    
    // Initialize charts with empty data
    initializeCharts();
    
    // Load initial data
    loadReportData();
});

function initDateRange() {
    const startDate = document.getElementById('startDate');
    const endDate = document.getElementById('endDate');
    
    if (startDate && endDate) {
        // Set default dates (last 30 days)
        const end = new Date();
        const start = new Date();
        start.setDate(start.getDate() - 30);
        
        startDate.value = formatDateForInput(start);
        endDate.value = formatDateForInput(end);
        
        console.log('Date range initialized:', startDate.value, 'to', endDate.value);
    }
}

function formatDateForInput(date) {
    return date.toISOString().split('T')[0];
}

function setupEventListeners() {
    console.log('Setting up event listeners...');
    
    // Filter button
    const filterBtn = document.getElementById('applyFilters');
    if (filterBtn) {
        filterBtn.addEventListener('click', function(e) {
            e.preventDefault();
            loadReportData();
        });
    }
    
    // Refresh button
    const refreshBtn = document.getElementById('refreshData');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function(e) {
            e.preventDefault();
            loadReportData();
        });
    }
    
    // Report type change
    const reportTypeSelect = document.getElementById('reportType');
    if (reportTypeSelect) {
        reportTypeSelect.addEventListener('change', function() {
            loadReportData();
        });
    }
    
    // Download button
    const downloadBtn = document.getElementById('downloadReport');
    if (downloadBtn) {
        downloadBtn.addEventListener('click', function(e) {
            e.preventDefault();
            downloadReport();
        });
    }
}

function initializeCharts() {
    console.log('Initializing charts...');
    
    try {
        // User Registration Chart
        const userRegCanvas = document.getElementById('userRegistrationsChart');
        if (userRegCanvas) {
            charts.userRegChart = new Chart(userRegCanvas.getContext('2d'), {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Volunteers',
                        data: [],
                        borderColor: '#3498db',
                        backgroundColor: 'rgba(52, 152, 219, 0.1)',
                        tension: 0.4,
                        fill: true,
                        borderWidth: 2
                    }, {
                        label: 'Organizations',
                        data: [],
                        borderColor: '#2ecc71',
                        backgroundColor: 'rgba(46, 204, 113, 0.1)',
                        tension: 0.4,
                        fill: true,
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top' },
                        tooltip: { mode: 'index', intersect: false }
                    },
                    scales: {
                        y: { 
                            beginAtZero: true,
                            ticks: { stepSize: 1 }
                        }
                    }
                }
            });
        }
        
        // Opportunity Status Chart
        const oppStatusCanvas = document.getElementById('opportunityStatusChart');
        if (oppStatusCanvas) {
            charts.oppStatusChart = new Chart(oppStatusCanvas.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: [],
                    datasets: [{
                        data: [],
                        backgroundColor: [
                            '#2ecc71', // Open
                            '#e74c3c', // Closed
                            '#f39c12', // Suspended
                            '#3498db', // Draft
                            '#95a5a6'  // Other
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { 
                        legend: { 
                            position: 'right',
                            labels: { padding: 20 }
                        } 
                    }
                }
            });
        }
        
        // Application Status Chart
        const appStatusCanvas = document.getElementById('applicationStatusChart');
        if (appStatusCanvas) {
            charts.appStatusChart = new Chart(appStatusCanvas.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Applications',
                        data: [],
                        backgroundColor: [
                            '#3498db', // Pending
                            '#2ecc71', // Accepted
                            '#e74c3c', // Rejected
                            '#f39c12'  // Shortlisted
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { 
                        y: { 
                            beginAtZero: true,
                            ticks: { stepSize: 1 }
                        } 
                    }
                }
            });
        }
        
        // Monthly Activity Chart
        const monthlyActivityCanvas = document.getElementById('monthlyActivityChart');
        if (monthlyActivityCanvas) {
            charts.monthlyActivityChart = new Chart(monthlyActivityCanvas.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'New Users',
                        data: [],
                        backgroundColor: '#3498db'
                    }, {
                        label: 'Opportunities',
                        data: [],
                        backgroundColor: '#2ecc71'
                    }, {
                        label: 'Applications',
                        data: [],
                        backgroundColor: '#f39c12'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'top' } },
                    scales: { 
                        y: { 
                            beginAtZero: true,
                            stacked: false
                        }
                    }
                }
            });
        }
        
        // Top Interests Chart
        const topInterestsCanvas = document.getElementById('topInterestsChart');
        if (topInterestsCanvas) {
            charts.topInterestsChart = new Chart(topInterestsCanvas.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: [],
                    datasets: [{
                        data: [],
                        backgroundColor: '#9b59b6'
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { 
                        x: { 
                            beginAtZero: true,
                            ticks: { stepSize: 1 }
                        } 
                    }
                }
            });
        }
        
        // Top Skills Chart
        const topSkillsCanvas = document.getElementById('topSkillsChart');
        if (topSkillsCanvas) {
            charts.topSkillsChart = new Chart(topSkillsCanvas.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: [],
                    datasets: [{
                        data: [],
                        backgroundColor: '#e74c3c'
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { 
                        x: { 
                            beginAtZero: true,
                            ticks: { stepSize: 1 }
                        } 
                    }
                }
            });
        }
        
        console.log('All charts initialized successfully');
        
    } catch (error) {
        console.error('Error initializing charts:', error);
        showError('Error initializing charts: ' + error.message);
    }
}

function loadReportData() {
    console.log('Loading report data...');
    
    // Show loading state
    showLoadingStates();
    
    // Get filter values
    const startDate = document.getElementById('startDate')?.value;
    const endDate = document.getElementById('endDate')?.value;
    const reportType = document.getElementById('reportType')?.value || 'overview';
    
    // Build API URL with filters
    let apiUrl = `api/get_reports.php?type=${reportType}`;
    if (startDate) apiUrl += `&start_date=${startDate}`;
    if (endDate) apiUrl += `&end_date=${endDate}`;
    
    console.log('Fetching from:', apiUrl);
    
    // Fetch data
    fetch(apiUrl)
        .then(response => {
            if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
            return response.json();
        })
        .then(data => {
            console.log('API Response received:', data);
            if (data.success && data.data) {
                updateAllCharts(data.data);
                updateStats(data.data.stats || {});
            } else {
                throw new Error(data.message || 'Failed to load data from API');
            }
        })
        .catch(error => {
            console.error('Error loading report data:', error);
            showError('Error loading report data: ' + error.message);
            
            // For debugging, show what we tried to fetch
            console.log('Failed URL:', apiUrl);
        })
        .finally(() => {
            hideLoadingStates();
        });
}

function updateStats(stats) {
    console.log('Updating stats:', stats);
    
    // Update each stat element
    Object.keys(stats).forEach(key => {
        const element = document.getElementById(key);
        if (element) {
            element.textContent = stats[key];
        }
    });
}

function updateAllCharts(data) {
    console.log('Updating charts with data:', data);
    
    // Update User Registrations Chart
    if (data.user_registrations && charts.userRegChart) {
        charts.userRegChart.data.labels = data.user_registrations.labels || [];
        if (data.user_registrations.volunteers) {
            charts.userRegChart.data.datasets[0].data = data.user_registrations.volunteers;
        }
        if (data.user_registrations.organizations) {
            charts.userRegChart.data.datasets[1].data = data.user_registrations.organizations;
        }
        charts.userRegChart.update();
    }
    
    // Update Opportunity Status Chart
    if (data.opportunity_status && charts.oppStatusChart) {
        charts.oppStatusChart.data.labels = data.opportunity_status.labels || [];
        charts.oppStatusChart.data.datasets[0].data = data.opportunity_status.values || [];
        charts.oppStatusChart.update();
    }
    
    // Update Application Status Chart
    if (data.application_status && charts.appStatusChart) {
        charts.appStatusChart.data.labels = data.application_status.labels || [];
        charts.appStatusChart.data.datasets[0].data = data.application_status.values || [];
        charts.appStatusChart.update();
    }
    
    // Update Monthly Activity Chart
    if (data.monthly_activity && charts.monthlyActivityChart) {
        charts.monthlyActivityChart.data.labels = data.monthly_activity.labels || [];
        if (data.monthly_activity.users) {
            charts.monthlyActivityChart.data.datasets[0].data = data.monthly_activity.users;
        }
        if (data.monthly_activity.opportunities) {
            charts.monthlyActivityChart.data.datasets[1].data = data.monthly_activity.opportunities;
        }
        if (data.monthly_activity.applications) {
            charts.monthlyActivityChart.data.datasets[2].data = data.monthly_activity.applications;
        }
        charts.monthlyActivityChart.update();
    }
    
    // Update Top Interests Chart
    if (data.top_interests && charts.topInterestsChart) {
        charts.topInterestsChart.data.labels = data.top_interests.labels || [];
        charts.topInterestsChart.data.datasets[0].data = data.top_interests.values || [];
        charts.topInterestsChart.update();
    }
    
    // Update Top Skills Chart
    if (data.top_skills && charts.topSkillsChart) {
        charts.topSkillsChart.data.labels = data.top_skills.labels || [];
        charts.topSkillsChart.data.datasets[0].data = data.top_skills.values || [];
        charts.topSkillsChart.update();
    }
    
    console.log('All charts updated successfully');
}

function showLoadingStates() {
    const buttons = document.querySelectorAll('#applyFilters, #refreshData, #downloadReport');
    buttons.forEach(btn => {
        const original = btn.innerHTML;
        btn.dataset.original = original;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
        btn.disabled = true;
    });
}

function hideLoadingStates() {
    const buttons = document.querySelectorAll('#applyFilters, #refreshData, #downloadReport');
    buttons.forEach(btn => {
        if (btn.dataset.original) {
            btn.innerHTML = btn.dataset.original;
        }
        btn.disabled = false;
    });
}

function showError(message) {
    console.error('Showing error:', message);
    
    // Create or update error message
    let errorDiv = document.getElementById('reportError');
    if (!errorDiv) {
        errorDiv = document.createElement('div');
        errorDiv.id = 'reportError';
        errorDiv.style.cssText = `
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            margin: 15px 0;
            border-radius: 8px;
            border: 1px solid #f5c6cb;
            font-family: 'Inter', sans-serif;
        `;
        
        const container = document.querySelector('.vc-reports-container');
        if (container) {
            container.insertBefore(errorDiv, container.firstChild);
        }
    }
    
    errorDiv.innerHTML = `
        <div style="display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-exclamation-triangle"></i>
            <div>
                <strong>Error:</strong> ${message}
                <div style="font-size: 12px; margin-top: 5px;">
                    <button onclick="retryLoad()" style="background: #721c24; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer;">
                        <i class="fas fa-redo"></i> Retry
                    </button>
                </div>
            </div>
        </div>
    `;
    
    // Auto-hide after 10 seconds
    setTimeout(() => {
        if (errorDiv.parentNode) {
            errorDiv.style.opacity = '0';
            setTimeout(() => errorDiv.remove(), 300);
        }
    }, 10000);
}

window.retryLoad = function() {
    const errorDiv = document.getElementById('reportError');
    if (errorDiv) {
        errorDiv.remove();
    }
    loadReportData();
};

function downloadReport() {
    // Collect data for CSV
    let csvContent = "data:text/csv;charset=utf-8,";
    csvContent += "Volunteer Connect Report," + new Date().toLocaleString() + "\n\n";
    csvContent += "Metric,Value\n";
    
    // Add stats to CSV
    document.querySelectorAll('.vc-stat-card').forEach(card => {
        const label = card.querySelector('.vc-stat-label')?.textContent || '';
        const value = card.querySelector('.vc-stat-value')?.textContent || '';
        if (label && value) {
            csvContent += `${label},${value}\n`;
        }
    });
    
    try {
        const encodedUri = encodeURI(csvContent);
        const link = document.createElement("a");
        link.setAttribute("href", encodedUri);
        link.setAttribute("download", `volunteer_report_${new Date().toISOString().split('T')[0]}.csv`);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    } catch (error) {
        console.error('Error downloading report:', error);
        alert('Could not download report. Please try again.');
    }
}