<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>APM Dashboard</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Custom Styles -->
    <style>
        :root {
            --bg-color: #f8f9fa;
            --text-color: #333;
            --card-bg: #fff;
            --card-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --header-bg: #fff;
            --badge-primary: #0d6efd;
            --badge-success: #198754;
            --badge-danger: #dc3545;
            --badge-info: #0dcaf0;
            --badge-warning: #ffc107;
        }

        [data-theme="dark"] {
            --bg-color: #212529;
            --text-color: #f8f9fa;
            --card-bg: #343a40;
            --card-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
            --header-bg: #2c3034;
            --badge-primary: #6ea8fe;
            --badge-success: #20c997;
            --badge-danger: #f27474;
            --badge-info: #6edff6;
            --badge-warning: #ffca2c;
        }

		[data-theme="dark"] .card-header {
			color: var(--text-color);
		}

		[data-theme="dark"] .list-group-item {
			color: var(--text-color);
		}

		[data-theme="dark"] .card-body {
			color: var(--text-color);
		}

		[data-theme="dark"] .badge {
			color: #fff; /* Ensure badge text is always white for readability */
		}

		[data-theme="dark"] .table {
			background-color: var(--card-bg); /* Match the card background */
			color: var(--text-color);
		}

		[data-theme="dark"] .table thead th, .table>:not(caption)>*>* {
			background-color: var(--header-bg); /* Match the header background */
			color: var(--text-color);
			border-bottom: 2px solid #495057; /* Slightly lighter border for contrast */
		}

		[data-theme="dark"] .table tbody tr, .table>:not(caption)>*>* {
			background-color: var(--card-bg);
			border-bottom: 1px solid #495057; /* Subtle border between rows */
		}

		[data-theme="dark"] .table-hover tbody tr:hover {
			background-color: rgba(255, 255, 255, 0.1); /* Keep the hover effect */
		}

		[data-theme="dark"] .form-control {
			background-color: #495057;
			color: var(--text-color);
			border-color: #6c757d;
		}

		[data-theme="dark"] .form-control::placeholder {
			color: #ced4da; /* Lighter gray for better visibility */
			opacity: 1; /* Ensure full opacity for readability */
		}

        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            font-family: 'Inter', sans-serif;
            transition: background-color 0.3s, color 0.3s;
        }
        .card {
            border: none;
            border-radius: 10px;
            background-color: var(--card-bg);
            box-shadow: var(--card-shadow);
            transition: transform 0.2s, background-color 0.3s;
        }
        .card:hover {
            transform: translateY(-5px);
        }
        .card-header {
            background-color: var(--header-bg);
            border-bottom: none;
            font-weight: 600;
            color: var(--text-color);
        }
        .list-group-item {
            border: none;
            padding: 0.5rem 1rem;
            font-size: 0.95rem;
            background-color: var(--card-bg);
            color: var(--text-color);
        }
        .badge {
            font-size: 0.9rem;
            font-weight: 500;
        }
        .badge-primary { background-color: var(--badge-primary); }
        .badge-success { background-color: var(--badge-success); }
        .badge-danger { background-color: var(--badge-danger); }
        .badge-info { background-color: var(--badge-info); }
        .badge-warning { background-color: var(--badge-warning); }
        .chart-container {
            background-color: var(--card-bg);
            border-radius: 10px;
            padding: 1rem;
            box-shadow: var(--card-shadow);
        }
        .btn-outline-primary {
            transition: all 0.3s;
            color: var(--text-color);
            border-color: var(--badge-primary);
        }
        .btn-outline-primary:hover {
            background-color: var(--badge-primary);
            color: #fff;
        }
    </style>
</head>
<body>
    <div class="container py-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="display-6 fw-bold" style="color: var(--text-color)">APM Dashboard</h1>
            <div class="d-flex align-items-center">
                <label for="range" class="form-label me-2" style="color: var(--text-color)">Time Range:</label>
                <select id="range" class="form-select d-inline-block w-auto">
                    <option value="last_hour">Last Hour</option>
                    <option value="last_day">Last Day</option>
                    <option value="last_week">Last Week</option>
                </select>
                <button id="theme-toggle" class="btn btn-outline-secondary ms-3">
                    <i class="bi bi-moon-stars"></i> Dark Mode
                </button>
            </div>
        </div>

        <div class="row g-4">

			<!-- Request Log Section -->
			<div class="col-12 mt-4">
				<div class="card">
					<div class="card-header d-flex align-items-center">
						<i class="bi bi-list me-2" style="color: var(--badge-primary)"></i> Request Log
					</div>
					<div class="card-body">
						<div class="mb-3">
							<input type="text" id="request-search" class="form-control" placeholder="Search by URL, Response Code, or Custom Event Type...">
						</div>
						<div class="table-responsive">
							<table class="table table-hover">
								<thead>
									<tr>
										<th>Timestamp</th>
										<th>Request URL</th>
										<th>Total Time (ms)</th>
										<th>Response Code</th>
										<th>Details</th>
									</tr>
								</thead>
								<tbody id="request-log">
								</tbody>
							</table>
						</div>
						<div class="d-flex justify-content-between align-items-center mt-3">
							<div>
								<span id="pagination-info"></span>
							</div>
							<nav>
								<ul class="pagination mb-0">
									<li class="page-item" id="prev-page"><a class="page-link" href="#">Previous</a></li>
									<li class="page-item" id="next-page"><a class="page-link" href="#">Next</a></li>
								</ul>
							</nav>
						</div>
					</div>
				</div>
			</div>

            <!-- Slowest Requests -->
            <div class="col-md-6 col-lg-4">
                <div class="card">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-clock me-2" style="color: var(--badge-primary)"></i> Top 5 Slowest Requests
                    </div>
                    <ul class="list-group list-group-flush" id="slow-requests"></ul>
                </div>
            </div>

            <!-- Slowest Routes -->
            <div class="col-md-6 col-lg-4">
                <div class="card">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-signpost-split me-2" style="color: var(--badge-primary)"></i> Top 5 Slowest Routes
                    </div>
                    <ul class="list-group list-group-flush" id="slow-routes"></ul>
                </div>
            </div>

            <!-- Error Rate -->
            <div class="col-md-6 col-lg-4">
                <div class="card">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-exclamation-triangle me-2" style="color: var(--badge-warning)"></i> Error Rate
                    </div>
                    <div class="card-body">
                        <h5 class="text-center" id="error-rate"></h5>
                    </div>
                </div>
            </div>

            <!-- Response Code Distribution -->
            <div class="col-md-12 col-lg-12">
                <div class="card">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-pie-chart me-2" style="color: var(--badge-primary)"></i> Response Code Distribution
                    </div>
                    <div class="card-body">
                        <canvas id="responseCodeChart" height="150"></canvas>
                    </div>
                </div>
            </div>

            <!-- Slowest Middleware -->
            <div class="col-md-6 col-lg-6">
                <div class="card">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-shield me-2" style="color: var(--badge-primary)"></i> Top 5 Slowest Middleware
                    </div>
                    <ul class="list-group list-group-flush" id="slow-middleware"></ul>
                </div>
            </div>

            <!-- Cache Hit/Miss Rate -->
            <div class="col-md-6 col-lg-6">
                <div class="card">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-hdd me-2" style="color: var(--badge-primary)"></i> Cache Hit/Miss Rate
                    </div>
                    <div class="card-body">
                        <h5 class="text-center" id="cache-hit-rate"></h5>
                    </div>
                </div>
            </div>

            <!-- Long Query Times -->
            <div class="col-md-6 col-lg-6">
                <div class="card">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-database me-2" style="color: var(--badge-primary)"></i> Top 5 Long Query Times
                    </div>
                    <ul class="list-group list-group-flush" id="long-queries"></ul>
                </div>
            </div>

            <!-- Latency Percentiles -->
            <div class="col-md-6 col-lg-6">
                <div class="card">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-speedometer me-2" style="color: var(--badge-primary)"></i> Latency Percentiles
                    </div>
                    <div class="card-body">
                        <p class="mb-0">95th: <span class="badge bg-success" id="p95"></span> ms | 99th: <span class="badge bg-danger" id="p99"></span> ms</p>
                    </div>
                </div>
            </div>

            <!-- Latency Chart -->
            <div class="col-12">
                <div class="chart-container">
                    <canvas id="latencyChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Dashboard Logic -->
    <script>
        // Initialize variables
        const rangeSelector = document.getElementById('range');
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

        // Apply stored theme or default to light
        if (isDarkMode) {
            document.documentElement.setAttribute('data-theme', 'dark');
            themeToggle.innerHTML = '<i class="bi bi-sun"></i> Light Mode';
        }

        // Toggle theme
        themeToggle.addEventListener('click', () => {
            isDarkMode = !isDarkMode;
            document.documentElement.setAttribute('data-theme', isDarkMode ? 'dark' : '');
            themeToggle.innerHTML = `<i class="bi bi-${isDarkMode ? 'sun' : 'moon-stars'}"></i> ${isDarkMode ? 'Light' : 'Dark'} Mode`;
            localStorage.setItem('darkMode', isDarkMode);
        });

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
                
                return `
                    <tr>
                        <td>${r.timestamp}</td>
                        <td>${r.request_url}</td>
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
        
        // Search functionality with debounce
        const searchInput = document.getElementById('request-search');
        searchInput.addEventListener('input', debounce(() => {
            searchTerm = searchInput.value;
            currentPage = 1; // Reset to first page on search
            loadRequestLogData(); // Only reload request log data
        }, DEBOUNCE_DELAY));

        // Initialize dashboard on page load
        loadData();
    </script>
    <style>
        /* JSON formatter styles */
        .json-formatter {
            background: #f8f9fa;
            border-radius: 4px;
            padding: 10px;
            font-family: monospace;
            font-size: 13px;
            overflow-x: auto;
            white-space: pre-wrap;
            max-height: 300px;
            overflow-y: auto;
        }
        
        [data-theme="dark"] .json-formatter {
            background: #2c3034;
        }
        
        .json-key {
            color: #0d6efd;
        }
        
        .json-string {
            color: #20c997;
        }
        
        .json-number {
            color: #fd7e14;
        }
        
        .json-boolean {
            color: #dc3545;
        }
        
        .json-null {
            color: #6c757d;
        }
        
        /* Adjust table styles */
        .card-body .table {
            font-size: 0.9rem;
        }
        
        .card-body .table td code {
            white-space: pre-wrap;
            max-width: 300px;
            display: block;
            overflow-x: auto;
        }
        
        /* Enhance accordion for custom events */
        .accordion-button {
            padding: 0.5rem 1rem;
        }
        
        .accordion-body {
            padding: 1rem;
        }
    </style>
</body>
</html>