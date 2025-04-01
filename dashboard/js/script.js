// Initialize variables
const rangeSelector = document.getElementById('range');
const timezoneSelector = document.getElementById('timezone');
const themeToggle = document.getElementById('theme-toggle');
let isDarkMode = localStorage.getItem('darkMode') === 'true';
let currentPage = 1;
let totalPages = 1;
let perPage = 50;
let activeFilters = {}; // New object to store all active filters
let dashboardData = null;
let latencyChart, responseCodeChart;
let filterDebounceTimer = null;
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
        let date;
        
        // Check if timestamp is in ISO format or contains 'Z' (indicating UTC)
        if (utcTimestamp.includes('T') && (utcTimestamp.includes('Z') || utcTimestamp.includes('+'))) {
            // Already in ISO format with timezone info, just parse it
            date = new Date(utcTimestamp);
        } else {
            // Assume format: YYYY-MM-DD HH:MM:SS and explicitly treat as UTC
            // MySQL timestamp format from database
            const parts = utcTimestamp.split(/[- :]/);
            if (parts.length >= 6) {
                date = new Date(Date.UTC(
                    parseInt(parts[0]),
                    parseInt(parts[1])-1,
                    parseInt(parts[2]),
                    parseInt(parts[3]),
                    parseInt(parts[4]),
                    parseInt(parts[5])
                ));
            } else {
                // If no specific format is detected, append 'Z' to signal UTC
                date = new Date(utcTimestamp + 'Z');
            }
        }
        
        // Check if date parsing succeeded
        if (isNaN(date.getTime())) {
            throw new Error("Invalid timestamp format");
        }

		// get the locale from the browser
		const locale = navigator.language || 'en-US';
        
        // Format the date in the selected timezone
        return date.toLocaleString(locale, { 
            timeZone: selectedTimezone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
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
            drawLatencyChart(data); // Changed from drawCharts to only draw the latency chart
        })
        .catch(error => console.error('Error loading dashboard data:', error));
}

// Function to load only request log data
function loadRequestLogData() {
    const range = rangeSelector.value;
    
    // Build query params from all active filters
    const queryParams = new URLSearchParams();
    queryParams.append('range', range);
    queryParams.append('page', currentPage);
    
    // Add each active filter to the query params
    Object.entries(activeFilters).forEach(([key, value]) => {
        if (value !== null && value !== '') {
            // Special handling for event_keys which needs to be JSON
            if (key === 'event_keys') {
                queryParams.append(key, JSON.stringify(value));
            } else {
                queryParams.append(key, value);
            }
        }
    });
    
    fetch(`/apm/data/requests?${queryParams.toString()}`)
        .then(response => {
            if (!response.ok) throw new Error('Network response was not ok');
            return response.json();
        })
        .then(data => {
            console.log('Request log data received:', data);
            populateRequestLog(data.requests);
            updatePagination(data.pagination);
            
            // Update the response code distribution chart with filtered data
            if (data.responseCodeDistribution && data.responseCodeDistribution.length > 0) {
                updateResponseCodeChart(data.responseCodeDistribution);
            } else if (Object.keys(activeFilters).length > 0) {
                // If we have active filters but no matching data, show empty chart
                updateResponseCodeChart([]);
            }
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

	const allRequestsCount = document.getElementById('all-requests-count');
	allRequestsCount.textContent = data.allRequestsCount;

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
        
        // Format bot status with badge
        const botStatus = r.is_bot == 1 ? 
            '<span class="badge bg-warning">Yes</span>' : 
            '<span class="badge bg-secondary">No</span>';
        
        // Create a complete copy of the request data for JSON display
        const requestJson = { ...r };
        
        // Create a unique ID for the details row
        const detailsRowId = `request-details-row-${index}`;
        
        // Return two table rows - one for the request data and one for details (initially hidden)
        return `
            <tr>
                <td>${formattedTimestamp}</td>
                <td class="${urlCellClass}" data-full-url="${r.request_url}">${r.request_url}</td>
                <td>${time.toFixed(3)} ms</td>
                <td>${r.response_code}</td>
				<td>${r.ip}</td>
                <td>${botStatus}</td>
                <td>
                    <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="collapse" 
                            data-bs-target="#${detailsRowId}" aria-expanded="false" aria-controls="${detailsRowId}">
                        Details
                    </button>
                </td>
            </tr>
            <tr class="details-row collapse" id="${detailsRowId}">
                <td colspan="6">
                    <div class="p-3">
                        <div class="card card-body">
                            <div class="row">
                                <!-- JSON representation on the left -->
                                <div class="col-md-7">
                                    <h6 class="border-bottom pb-1">Raw Request Data</h6>
                                    <div class="json-container" style="max-height: 500px; overflow-y: auto;">
                                        <pre class="json-formatter">${formatJson(requestJson)}</pre>
                                    </div>
                                </div>
                                
                                <!-- Structured sections on the right -->
                                <div class="col-md-5">
                                    ${detailSections.join('')}
                                </div>
                            </div>
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
    
    // Create the pagination info message
    let paginationInfoText = `Showing ${start} to ${end} of ${pagination.totalRequests} requests`;
    
    // Add a note about the 500 limit if we're hitting it
    if (pagination.totalRequests >= 500) {
        paginationInfoText += ` <span class="text-warning" title="Only the most recent 500 matching requests are displayed. Use more specific filters to narrow your results."><i class="bi bi-info-circle"></i> (maximum limit - refine filters for more specific results)</span>`;
    }
    
    document.getElementById('pagination-info').innerHTML = paginationInfoText;

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

// Draw charts with data - renamed to drawLatencyChart and removed response code chart creation
function drawLatencyChart(data) {
    const MAX_X_AXIS_POINTS = 40; // Increased from 20 to show more granularity for day view
    const currentRange = rangeSelector.value;
    
    // Sample data points to limit x-axis labels based on range
    let chartData = data.chartData;
    
    // Apply sampling for longer time ranges
    if ((currentRange === 'last_day' || currentRange === 'last_week') && chartData.length > MAX_X_AXIS_POINTS) {
        // Calculate sampling interval
        const samplingInterval = Math.ceil(chartData.length / MAX_X_AXIS_POINTS);
        
        // Sample the latency chart data
        chartData = chartData.filter((_, index) => index % samplingInterval === 0);
    }
    
    // Latency Chart
    const ctxLatency = document.getElementById('latencyChart').getContext('2d');
    if (latencyChart) latencyChart.destroy();
    latencyChart = new Chart(ctxLatency, {
        type: 'line',
        data: {
            labels: chartData.map(d => d.timestamp),
            datasets: [{
                label: 'Average Latency (ms)',
                data: chartData.map(d => d.average_time * 1000),
                borderColor: '#0d6efd',
                backgroundColor: 'rgba(13, 110, 253, 0.1)',
                fill: true,
                tension: 0.4,
            }]
        },
        options: {
            scales: {
                x: { 
                    title: { display: true, text: 'Time' },
                    ticks: {
                        maxRotation: 45,
                        minRotation: 45
                    }
                },
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
                },
                tooltip: {
                    callbacks: {
                        title: function(tooltipItems) {
                            // Format the timestamp in the tooltip
                            return formatTimestamp(tooltipItems[0].label);
                        }
                    }
                }
            }
        }
    });

    // Remove the response code chart update
    // updateResponseCodeChart(data.responseCodeOverTime); <- This line is removed
}

// New function to update just the response code chart
function updateResponseCodeChart(responseData) {
    const ctxResponse = document.getElementById('responseCodeChart').getContext('2d');
    if (responseCodeChart) responseCodeChart.destroy();
    
    // If no data, show empty chart with message
    if (!responseData || responseData.length === 0) {
        responseCodeChart = new Chart(ctxResponse, {
            type: 'bar',
            data: {
                datasets: []
            },
            options: {
                maintainAspectRatio: false,
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: 'No matching data for current filters',
                        padding: {
                            top: 30
                        }
                    }
                }
            }
        });
        return;
    }
    
    // Sample data points if too many
    const MAX_X_AXIS_POINTS = 40; // Increased from 20 to show more granularity for day view
    const currentRange = rangeSelector.value;
    
    if ((currentRange === 'last_day' || currentRange === 'last_week') && responseData.length > MAX_X_AXIS_POINTS) {
        const samplingInterval = Math.ceil(responseData.length / MAX_X_AXIS_POINTS);
        responseData = responseData.filter((_, index) => index % samplingInterval === 0);
        console.log(`Sampling response data with interval ${samplingInterval}, showing ${responseData.length} points`);
    }

    // Extract all unique response codes
    const responseCodes = [...new Set(responseData.flatMap(d => Object.keys(d).filter(k => k !== 'timestamp')))];
    
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
            data: responseData.map(d => d[code] || 0),
            backgroundColor: color,
            borderWidth: 1,
        };
    });

    responseCodeChart = new Chart(ctxResponse, {
        type: 'bar',
        data: {
            labels: responseData.map(d => d.timestamp),
            datasets: datasets
        },
        options: {
            maintainAspectRatio: false,
            responsive: true,
            scales: {
                x: { 
                    title: { display: false },
                    stacked: true,
                    ticks: {
                        display: false
                    },
                    grid: {
                        display: false
                    }
                },
                y: { 
                    title: { display: true, text: 'Request Count' },
                    beginAtZero: true,
                    stacked: true,
                }
            },
            plugins: {
                legend: {
                    display: false,
                },
                tooltip: {
                    callbacks: {
                        title: function(tooltipItems) {
                            return formatTimestamp(tooltipItems[0].label);
                        }
                    }
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

// Setup filter functionality
function setupFilterHandlers() {
    const filterResponseCode = document.getElementById('filter-response-code');
    const exactCodeContainer = document.getElementById('exact-code-container');
    const filterExactCode = document.getElementById('filter-exact-code');
    const applyFiltersBtn = document.getElementById('apply-filters');
    const clearFiltersBtn = document.getElementById('clear-filters');
    const activeFiltersContainer = document.getElementById('active-filters');
    const activeFiltersList = document.getElementById('active-filters-list');
    const addEventFilterBtn = document.getElementById('add-event-filter');
    const eventFiltersContainer = document.getElementById('event-filters-container');
    const noEventFiltersMsg = document.getElementById('no-event-filters-msg');
    const eventFilterTemplate = document.getElementById('event-filter-template');
    
    // Store available keys for reuse
    let availableEventKeys = [];
    
    // Define operators directly instead of loading them
    const availableOperators = [
        { id: 'contains', name: 'Contains', desc: 'Value contains the text (case-insensitive)' },
        { id: 'exact', name: 'Equals', desc: 'Value exactly matches the text' },
        { id: 'starts_with', name: 'Starts with', desc: 'Value starts with the text' },
        { id: 'ends_with', name: 'Ends with', desc: 'Value ends with the text' },
        { id: 'greater_than', name: '>', desc: 'Value is greater than (numeric comparison)' },
        { id: 'less_than', name: '<', desc: 'Value is less than (numeric comparison)' },
        { id: 'greater_than_equal', name: '>=', desc: 'Value is greater than or equal to (numeric comparison)' },
        { id: 'less_than_equal', name: '<=', desc: 'Value is less than or equal to (numeric comparison)' }
    ];
    
    // Load event keys for dropdowns
    function loadEventKeys() {
        const range = rangeSelector.value;
        fetch(`/apm/data/event-keys?range=${range}`)
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.json();
            })
            .then(data => {
                availableEventKeys = data.event_keys || [];
                updateEventKeyDropdowns();
            })
            .catch(error => console.error('Error loading event keys:', error));
    }
    
    // Update all event key dropdowns with available options
    function updateEventKeyDropdowns() {
        document.querySelectorAll('.event-key-select').forEach(select => {
            const currentValue = select.value;
            
            // Clear existing options except the first one
            while (select.options.length > 1) {
                select.remove(1);
            }
            
            // Add options for each key
            if (availableEventKeys.length) {
                availableEventKeys.forEach(key => {
                    const option = document.createElement('option');
                    option.value = key;
                    option.textContent = key;
                    select.appendChild(option);
                });
            }
            
            // Restore previous value if it exists
            if (currentValue && availableEventKeys.includes(currentValue)) {
                select.value = currentValue;
            }
        });
    }
    
    // Update all operator dropdowns (now uses hardcoded values)
    function updateOperatorDropdowns() {
        document.querySelectorAll('.event-operator-select').forEach(select => {
            const currentValue = select.value || 'contains';
            
            // Clear existing options
            select.innerHTML = '';
            
            // Add options for each operator
            availableOperators.forEach(op => {
                const option = document.createElement('option');
                option.value = op.id;
                option.textContent = op.name;
                option.title = op.desc;
                select.appendChild(option);
            });
            
            // Restore previous value
            select.value = currentValue;
        });
    }
    
    // Create a new event filter row
    function addEventFilterRow(keyValue = '', operator = 'contains', valueValue = '') {
        // Hide the "no filters" message
        noEventFiltersMsg.style.display = 'none';
        
        // Clone the template
        const clone = document.importNode(eventFilterTemplate.content, true);
        const row = clone.querySelector('.event-filter-row');
        
        // Set initial values if provided
        const keySelect = row.querySelector('.event-key-select');
        const operatorSelect = row.querySelector('.event-operator-select');
        const valueInput = row.querySelector('.event-value-input');
        
        // Populate dropdowns
        updateEventKeyDropdowns();
        updateOperatorDropdowns();
        
        // Set values
        if (keyValue) keySelect.value = keyValue;
        if (operator) operatorSelect.value = operator;
        if (valueValue) valueInput.value = valueValue;
        
        // Add remove handler
        row.querySelector('.remove-event-filter').addEventListener('click', function() {
            row.remove();
            
            // Show message if no filters remain
            if (eventFiltersContainer.querySelectorAll('.event-filter-row').length === 0) {
                noEventFiltersMsg.style.display = 'block';
            }
        });
        
        // Append to container
        eventFiltersContainer.appendChild(row);
        
        return row;
    }
    
    // Add event filter button click
    addEventFilterBtn.addEventListener('click', () => {
        addEventFilterRow();
    });
    
    // Initial load of event keys and populate operator dropdowns
    loadEventKeys();
    updateOperatorDropdowns();
    
    // Reload event keys when range changes
    rangeSelector.addEventListener('change', loadEventKeys);
    
    // Show/hide exact code input based on selection
    filterResponseCode.addEventListener('change', () => {
        exactCodeContainer.style.display = 
            filterResponseCode.value === 'exact' ? 'block' : 'none';
    });
    
    // Apply filters button click
    applyFiltersBtn.addEventListener('click', () => {
        // Collect all filter values
        const url = document.getElementById('filter-url').value.trim();
        const responseCode = filterResponseCode.value;
        const exactCode = filterExactCode.value.trim();
        const requestId = document.getElementById('filter-request-id').value.trim();
        const bot = document.getElementById('filter-bot').value;
        const customEvent = document.getElementById('filter-custom-event').value.trim();
        const minTime = document.getElementById('filter-min-time').value.trim();
        
        // New metadata filters
        const ip = document.getElementById('filter-ip').value.trim();
        const host = document.getElementById('filter-host').value.trim();
        const sessionId = document.getElementById('filter-session-id').value.trim();
        const userAgent = document.getElementById('filter-user-agent').value.trim();
        
        // Collect event filter data
        const eventFilters = [];
        document.querySelectorAll('.event-filter-row').forEach(row => {
            const key = row.querySelector('.event-key-select').value;
            const operator = row.querySelector('.event-operator-select').value;
            const value = row.querySelector('.event-value-input').value.trim();
            
            if (key || value) {
                eventFilters.push({ key, operator, value });
            }
        });
        
        // Clear previous filters
        activeFilters = {};
        
        // Add non-empty filters to the activeFilters object
        if (url) activeFilters.url = url;
        if (requestId) activeFilters.request_id = requestId;
        
        if (responseCode === 'exact' && exactCode) {
            activeFilters.response_code = exactCode;
        } else if (responseCode && responseCode !== 'exact') {
            activeFilters.response_code_prefix = responseCode;
        }
        
        if (bot) activeFilters.is_bot = bot;
        if (customEvent) activeFilters.custom_event_type = customEvent;
        if (minTime) activeFilters.min_time = minTime;
        
        // Add new metadata filters
        if (ip) activeFilters.ip = ip;
        if (host) activeFilters.host = host;
        if (sessionId) activeFilters.session_id = sessionId;
        if (userAgent) activeFilters.user_agent = userAgent;
        
        // Add event filters if any exist
        if (eventFilters.length > 0) {
            activeFilters.event_keys = eventFilters;
        }
        
        // Update UI to show active filters
        updateActiveFiltersDisplay();
        
        // Reset to page 1 when applying new filters
        currentPage = 1;
        
        // Load data with new filters
        loadRequestLogData();
    });
    
    // Clear filters button click
    clearFiltersBtn.addEventListener('click', () => {
        // Reset all filter inputs
        document.getElementById('filter-url').value = '';
        filterResponseCode.value = '';
        filterExactCode.value = '';
        document.getElementById('filter-request-id').value = '';
        document.getElementById('filter-bot').value = '';
        document.getElementById('filter-custom-event').value = '';
        document.getElementById('filter-min-time').value = '';
        
        // Reset new metadata filters
        document.getElementById('filter-ip').value = '';
        document.getElementById('filter-host').value = '';
        document.getElementById('filter-session-id').value = '';
        document.getElementById('filter-user-agent').value = '';
        
        // Clear event filters
        eventFiltersContainer.innerHTML = '';
        noEventFiltersMsg.style.display = 'block';
        
        // Hide the exact code input
        exactCodeContainer.style.display = 'none';
        
        // Clear active filters
        activeFilters = {};
        
        // Update UI
        updateActiveFiltersDisplay();
        
        // Reset to page 1
        currentPage = 1;
        
        // Reload data
        loadRequestLogData();
    });
    
    // Function to update the active filters display
    function updateActiveFiltersDisplay() {
        if (Object.keys(activeFilters).length === 0) {
            activeFiltersContainer.classList.add('d-none');
            return;
        }
        
        activeFiltersContainer.classList.remove('d-none');
        activeFiltersList.innerHTML = '';
        
        // Create filter badges
        Object.entries(activeFilters).forEach(([key, value]) => {
            let filterLabel;
            
            switch (key) {
                case 'url':
                case 'request_id':
                case 'response_code':
                case 'custom_event_type':
                case 'min_time':
                case 'ip':
                case 'host':
                case 'session_id':
                case 'user_agent':
                    // ...existing code for these cases...
                    break;
                    
                case 'event_keys':
                    // For each event key filter, create a separate badge
                    value.forEach((filter, index) => {
                        const opDisplay = availableOperators.find(op => op.id === filter.operator)?.name || filter.operator;
                        const filterKey = `event_keys_${index}`;
                        const display = filter.key ? 
                            `${filter.key} ${opDisplay} ${filter.value}` : 
                            `Any Key ${opDisplay} ${filter.value}`;
                            
                        const badge = document.createElement('span');
                        badge.className = 'badge bg-info me-2 mb-1';
                        badge.innerHTML = `Event: ${display} <i class="bi bi-x-circle" data-filter-type="event_key" data-filter-index="${index}"></i>`;
                        
                        // Add click event to remove this specific event filter
                        badge.querySelector('i').addEventListener('click', function() {
                            const index = parseInt(this.dataset.filterIndex);
                            activeFilters.event_keys.splice(index, 1);
                            
                            if (activeFilters.event_keys.length === 0) {
                                delete activeFilters.event_keys;
                            }
                            
                            updateActiveFiltersDisplay();
                            currentPage = 1;
                            loadRequestLogData();
                        });
                        
                        activeFiltersList.appendChild(badge);
                    });
                    return; // Skip the default badge creation for this key
                    
                default:
                    filterLabel = `${key}: ${value}`;
            }
            
            if (filterLabel) { // Only create badge if we have a label
                const badge = document.createElement('span');
                badge.className = 'badge bg-info me-2 mb-1';
                badge.innerHTML = `${filterLabel} <i class="bi bi-x-circle" data-filter="${key}"></i>`;
                
                // Add click event to remove individual filter
                badge.querySelector('i').addEventListener('click', function() {
                    delete activeFilters[this.dataset.filter];
                    updateActiveFiltersDisplay();
                    currentPage = 1;
                    loadRequestLogData();
                });
                
                activeFiltersList.appendChild(badge);
            }
        });
    }
    
    // If we have event filters in active filters, recreate the UI for them
    if (activeFilters.event_keys && Array.isArray(activeFilters.event_keys) && activeFilters.event_keys.length > 0) {
        noEventFiltersMsg.style.display = 'none';
        eventFiltersContainer.innerHTML = '';
        
        activeFilters.event_keys.forEach(filter => {
            addEventFilterRow(filter.key, filter.operator, filter.value);
        });
    }
}

// Initialize dashboard on page load
document.addEventListener('DOMContentLoaded', () => {
    loadData();
    setupFilterHandlers();
    
    // Add event listener for range selector to handle sequential chart updates
    rangeSelector.addEventListener('change', () => {
        // Clear the response code chart immediately to avoid confusion
        if (responseCodeChart) {
            responseCodeChart.destroy();
            responseCodeChart = null;
            
            // Show loading state in chart
            const ctxResponse = document.getElementById('responseCodeChart').getContext('2d');
            responseCodeChart = new Chart(ctxResponse, {
                type: 'bar',
                data: { datasets: [] },
                options: {
                    maintainAspectRatio: false,
                    responsive: true,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Loading data...',
                            padding: { top: 30 }
                        }
                    }
                }
            });
        }
        
        // Load new data
        loadData();
    });
    
    // Remove the event listener we just added to avoid duplicates
    rangeSelector.removeEventListener('change', loadData);
});
