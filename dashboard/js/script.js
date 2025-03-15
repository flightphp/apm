// Initialize variables
const rangeSelector = document.getElementById('range');
const timezoneSelector = document.getElementById('timezone');
const themeToggle = document.getElementById('theme-toggle');
let isDarkMode = localStorage.getItem('darkMode') === 'true';
let currentPage = 1;
let totalPages = 1;
let perPage = 50;
let searchTerm = '';
let dashboardData = null;
let latencyChart, responseCodeChart;
let searchDebounceTimer = null;
const DEBOUNCE_DELAY = 300; // milliseconds
let selectedTimezone = localStorage.getItem('selectedTimezone') || 'UTC';

// Apply stored theme or default to light
if (isDarkMode) {
    document.documentElement.setAttribute('data-theme', 'dark');
    themeToggle.innerHTML = '<i class="bi bi-sun"></i> Light Mode';
}

// Set the timezone dropdown to the stored value
if (selectedTimezone) {
    timezoneSelector.value = selectedTimezone;
}

// Toggle theme
themeToggle.addEventListener('click', () => {
    isDarkMode = !isDarkMode;
    document.documentElement.setAttribute('data-theme', isDarkMode ? 'dark' : '');
    themeToggle.innerHTML = `<i class="bi bi-${isDarkMode ? 'sun' : 'moon-stars'}"></i> ${isDarkMode ? 'Light' : 'Dark'} Mode`;
    localStorage.setItem('darkMode', isDarkMode);
});

// Function to format a UTC timestamp to the selected timezone
function formatTimestamp(utcTimestamp) {
    try {
        // Parse the timestamp string into a Date object
        // The database timestamp is already in UTC
        const date = new Date(utcTimestamp);
        
        // If date parsing failed, try alternate parsing
        if (isNaN(date.getTime())) {
            // Handle potential custom format
            const parts = utcTimestamp.split(/[- :]/);
            if (parts.length >= 6) {
                // Assume format: YYYY-MM-DD HH:MM:SS and create as UTC
                return new Date(Date.UTC(
                    parseInt(parts[0]),
                    parseInt(parts[1])-1,
                    parseInt(parts[2]),
                    parseInt(parts[3]),
                    parseInt(parts[4]),
                    parseInt(parts[5])
                )).toLocaleString('en-US', { 
                    timeZone: selectedTimezone,
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit',
                    hour12: false
                });
            } else {
                throw new Error("Invalid timestamp format");
            }
        }
        
        // Format the date in the selected timezone
        return date.toLocaleString('en-US', { 
            timeZone: selectedTimezone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false
        });
    } catch (error) {
        console.error("Error formatting timestamp:", error, utcTimestamp);
        return utcTimestamp; // Return original if there's an error
    }
}

// Main data loading functions
function loadData() {
    loadDashboardData();
    loadRequestLogData();
}

// Function to load dashboard widget data
function loadDashboardData() {
    const range = rangeSelector.value;
    fetch(`/apm/data/dashboard?range=${range}`)
        .then(response => {
            if (!response.ok) throw new Error('Network response was not ok');
            return response.json();
        })
        .then(data => {
            console.log('Dashboard data received:', data);
            dashboardData = data;
            populateWidgets(data);
            drawCharts(data);
        })
        .catch(error => console.error('Error loading dashboard data:', error));
}

// Function to load only request log data
function loadRequestLogData() {
    const range = rangeSelector.value;
    fetch(`/apm/data/requests?range=${range}&page=${currentPage}&search=${encodeURIComponent(searchTerm)}`)
        .then(response => {
            if (!response.ok) throw new Error('Network response was not ok');
            return response.json();
        })
        .then(data => {
            console.log('Request log data received:', data);
            populateRequestLog(data.requests);
            updatePagination(data.pagination);
        })
        .catch(error => console.error('Error loading request log data:', error));
}

// Populate widgets with data
function populateWidgets(data) {
    const slowRequests = document.getElementById('slow-requests');
    slowRequests.innerHTML = data.slowRequests.map(r => {
        const time = parseFloat(r.total_time) * 1000;
        return `<li class="list-group-item d-flex justify-content-between align-items-center">
            ${r.request_url}
            <span class="badge bg-primary rounded-pill">${time.toFixed(3)} ms</span>
        </li>`;
    }).join('');

    const slowRoutes = document.getElementById('slow-routes');
    slowRoutes.innerHTML = data.slowRoutes.map(r => {
        const time = parseFloat(r.avg_time) * 1000;
        return `<li class="list-group-item d-flex justify-content-between align-items-center">
            ${r.route_pattern}
            <span class="badge bg-info rounded-pill">${time.toFixed(3)} ms</span>
        </li>`;
    }).join('');

    document.getElementById('error-rate').textContent = `${(data.errorRate * 100).toFixed(2)}%`;

    const longQueries = document.getElementById('long-queries');
    longQueries.innerHTML = data.longQueries.map(q => {
        const time = parseFloat(q.execution_time) * 1000;
        const queryText = q.query.length > 50 ? q.query.substring(0, 50) + '...' : q.query;
        return `<li class="list-group-item d-flex justify-content-between align-items-center">
            ${queryText}
            <span class="badge bg-warning rounded-pill">${time.toFixed(3)} ms</span>
        </li>`;
    }).join('');

    const slowMiddleware = document.getElementById('slow-middleware');
    slowMiddleware.innerHTML = data.slowMiddleware.map(m => {
        const time = parseFloat(m.execution_time) * 1000;
        return `<li class="list-group-item d-flex justify-content-between align-items-center">
            ${m.middleware_name}
            <span class="badge bg-primary rounded-pill">${time.toFixed(3)} ms</span>
        </li>`;
    }).join('');

    document.getElementById('cache-hit-rate').textContent = `${(data.cacheHitRate * 100).toFixed(2)}% Hits`;

    document.getElementById('p95').textContent = (data.p95 * 1000).toFixed(3);
    document.getElementById('p99').textContent = (data.p99 * 1000).toFixed(3);
}

// Pretty format JSON
function formatJson(json) {
    if (!json) return 'No data';
    try {
        // If it's already a string, parse it to ensure valid JSON
        const obj = typeof json === 'string' ? JSON.parse(json) : json;
        return JSON.stringify(obj, null, 2)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, function (match) {
                let cls = 'json-number';
                if (/^"/.test(match)) {
                    if (/:$/.test(match)) {
                        cls = 'json-key';
                    } else {
                        cls = 'json-string';
                    }
                } else if (/true|false/.test(match)) {
                    cls = 'json-boolean';
                } else if (/null/.test(match)) {
                    cls = 'json-null';
                }
                return '<span class="' + cls + '">' + match + '</span>';
            });
    } catch (e) {
        return String(json);
    }
}

// Populate request log table with improved details
function populateRequestLog(requests) {
    const requestLog = document.getElementById('request-log');
    requestLog.innerHTML = requests.map((r, index) => {
        const time = parseFloat(r.total_time) * 1000;
        
        // Convert UTC timestamp to selected timezone
        const formattedTimestamp = formatTimestamp(r.timestamp);
        
        // Build details sections conditionally
        let detailSections = [];
        
        // Middleware section
        if (r.middleware && r.middleware.length > 0) {
            detailSections.push(`
                <div class="mb-3">
                    <h6 class="border-bottom pb-1">Middleware</h6>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Execution Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${r.middleware.map(m => `
                                    <tr>
                                        <td>${m.middleware_name}</td>
                                        <td>${(parseFloat(m.execution_time) * 1000).toFixed(3)} ms</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            `);
        }
        
        // Database Queries section
        if (r.queries && r.queries.length > 0) {
            detailSections.push(`
                <div class="mb-3">
                    <h6 class="border-bottom pb-1">Database Queries</h6>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Query</th>
                                    <th>Execution Time</th>
                                    <th>Rows</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${r.queries.map(q => `
                                    <tr>
                                        <td><code>${q.query}</code></td>
                                        <td>${(parseFloat(q.execution_time) * 1000).toFixed(3)} ms</td>
                                        <td>${q.row_count}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            `);
        }
        
        // Errors section
        if (r.errors && r.errors.length > 0) {
            detailSections.push(`
                <div class="mb-3">
                    <h6 class="border-bottom pb-1 text-danger">Errors</h6>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Error Message</th>
                                    <th>Error Code</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${r.errors.map(e => `
                                    <tr>
                                        <td class="text-danger">${e.error_message}</td>
                                        <td>${e.error_code}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            `);
        }
        
        // Cache Operations section
        if (r.cache && r.cache.length > 0) {
            detailSections.push(`
                <div class="mb-3">
                    <h6 class="border-bottom pb-1">Cache Operations</h6>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Key</th>
                                    <th>Result</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${r.cache.map(c => `
                                    <tr>
                                        <td>${c.cache_key}</td>
                                        <td>${c.hit ? '<span class="badge bg-success">Hit</span>' : '<span class="badge bg-warning">Miss</span>'}</td>
                                        <td>${(parseFloat(c.execution_time) * 1000).toFixed(3)} ms</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            `);
        }
        
        // Custom Events section
        if (r.custom_events && r.custom_events.length > 0) {
            detailSections.push(`
                <div class="mb-3">
                    <h6 class="border-bottom pb-1">Custom Events</h6>
                    <div class="accordion" id="customEventsAccordion-${index}">
                        ${r.custom_events.map((event, eventIndex) => `
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="event-heading-${index}-${eventIndex}">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#event-collapse-${index}-${eventIndex}" aria-expanded="false" aria-controls="event-collapse-${index}-${eventIndex}">
                                        <span class="badge bg-info me-2">${event.type}</span>
                                        <small class="text-muted">${new Date(parseFloat(event.timestamp) * 1000).toISOString().split('T')[1].replace('Z', '')}</small>
                                    </button>
                                </h2>
                                <div id="event-collapse-${index}-${eventIndex}" class="accordion-collapse collapse" aria-labelledby="event-heading-${index}-${eventIndex}" data-bs-parent="#customEventsAccordion-${index}">
                                    <div class="accordion-body">
                                        <pre class="json-formatter">${formatJson(event.data)}</pre>
                                    </div>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
            `);
        }

        // If no details available
        if (detailSections.length === 0) {
            detailSections.push(`<div class="alert alert-info">No detailed information available for this request.</div>`);
        }
        
        // Check if URL is long enough to need truncation
        const urlTooLong = r.request_url.length > 40;
        const urlCellClass = urlTooLong ? 'truncated-url' : '';
        
        return `
            <tr>
                <td>${formattedTimestamp}</td>
                <td class="${urlCellClass}" data-full-url="${r.request_url}" title="${r.request_url}">${r.request_url}</td>
                <td>${time.toFixed(3)} ms</td>
                <td>${r.response_code}</td>
                <td>
                <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#request-details-${index}" aria-expanded="false" aria-controls="request-details-${index}">
                    Details
                </button>
                <div class="collapse mt-2" id="request-details-${index}">
                    <div class="card card-body">
                    ${detailSections.join('')}
                    </div>
                </div>
                </td>
            </tr>
            `;
    }).join('');
}

// Update pagination controls
function updatePagination(pagination) {
    currentPage = pagination.currentPage;
    totalPages = pagination.totalPages;
    perPage = pagination.perPage;

    // Update pagination info
    const start = (currentPage - 1) * perPage + 1;
    const end = Math.min(currentPage * perPage, pagination.totalRequests);
    document.getElementById('pagination-info').textContent = `Showing ${start} to ${end} of ${pagination.totalRequests} requests`;

    // Update pagination buttons
    const prevPage = document.getElementById('prev-page');
    const nextPage = document.getElementById('next-page');
    prevPage.classList.toggle('disabled', currentPage === 1);
    nextPage.classList.toggle('disabled', currentPage === totalPages);

    prevPage.onclick = () => {
        if (currentPage > 1) {
            currentPage--;
            loadRequestLogData(); // Only reload request log data
        }
        return false;
    };

    nextPage.onclick = () => {
        if (currentPage < totalPages) {
            currentPage++;
            loadRequestLogData(); // Only reload request log data
        }
        return false;
    };
}

// Draw charts with data
function drawCharts(data) {
    // Latency Chart
    const ctxLatency = document.getElementById('latencyChart').getContext('2d');
    if (latencyChart) latencyChart.destroy();
    latencyChart = new Chart(ctxLatency, {
        type: 'line',
        data: {
            labels: data.chartData.map(d => d.timestamp),
            datasets: [{
                label: 'Average Latency (ms)',
                data: data.chartData.map(d => d.average_time * 1000),
                borderColor: '#0d6efd',
                backgroundColor: 'rgba(13, 110, 253, 0.1)',
                fill: true,
                tension: 0.4,
            }]
        },
        options: {
            scales: {
                x: { title: { display: true, text: 'Time' } },
                y: { 
                    title: { display: true, text: 'Latency (ms)' }, 
                    beginAtZero: true,
                    suggestedMax: 1
                }
            },
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                }
            }
        }
    });

    // Response Code Distribution Over Time (Stacked Bar Chart)
    const ctxResponse = document.getElementById('responseCodeChart').getContext('2d');
    if (responseCodeChart) responseCodeChart.destroy();

    // Extract all unique response codes
    const responseCodes = [...new Set(data.responseCodeOverTime.flatMap(d => Object.keys(d).filter(k => k !== 'timestamp')))];
    
    // Create datasets for each response code
    const datasets = responseCodes.map(code => {
        // Determine color based on response code
        let color;
        if (code >= 500 && code <= 599) {
            color = '#dc3545'; // Red for 5xx errors
        } else if (code >= 400 && code <= 499) {
            color = '#ffc107'; // Yellow for 4xx errors
        } else if (code >= 300 && code <= 399) {
            color = '#0dcaf0'; // Cyan for 3xx redirects
        } else {
            color = '#198754'; // Green for 2xx success
        }

        return {
            label: `Code ${code}`,
            data: data.responseCodeOverTime.map(d => d[code] || 0),
            backgroundColor: color,
            borderWidth: 1,
        };
    });

    responseCodeChart = new Chart(ctxResponse, {
        type: 'bar',
        data: {
            labels: data.responseCodeOverTime.map(d => d.timestamp),
            datasets: datasets
        },
        options: {
            scales: {
                x: { 
                    title: { display: true, text: 'Time' },
                    stacked: true,
                },
                y: { 
                    title: { display: true, text: 'Request Count' },
                    beginAtZero: true,
                    stacked: true,
                }
            },
            plugins: {
                legend: {
                    position: 'bottom',
                }
            }
        }
    });
}

// Debounce function
function debounce(func, delay) {
    return function() {
        const context = this;
        const args = arguments;
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = setTimeout(() => func.apply(context, args), delay);
    };
}

// Event listeners
rangeSelector.addEventListener('change', loadData);

// Add event listener for timezone selector
timezoneSelector.addEventListener('change', () => {
    selectedTimezone = timezoneSelector.value;
    localStorage.setItem('selectedTimezone', selectedTimezone);
    // Refresh only the request log data since it contains timestamps
    loadRequestLogData();
});

// Search functionality with debounce
const searchInput = document.getElementById('request-search');
searchInput.addEventListener('input', debounce(() => {
    searchTerm = searchInput.value;
    currentPage = 1; // Reset to first page on search
    loadRequestLogData(); // Only reload request log data
}, DEBOUNCE_DELAY));

// Initialize dashboard on page load
document.addEventListener('DOMContentLoaded', () => {
    loadData();
});
