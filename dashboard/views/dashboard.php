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
    <link href="/css/style.css" rel="stylesheet">
    <style>
        /* Additional styles for JSON formatting */
        .json-container {
            background-color: var(--bs-dark);
            border-radius: 4px;
            padding: 10px;
            color: var(--bs-light);
            font-family: monospace;
            font-size: 0.85rem;
            line-height: 1.4;
        }
        
        .json-key {
            color: #9cdcfe;
        }
        
        .json-string {
            color: #ce9178;
        }
        
        .json-number {
            color: #b5cea8;
        }
        
        .json-boolean {
            color: #569cd6;
        }
        
        .json-null {
            color: #569cd6;
        }
        
        /* Dark mode adjustments */
        [data-theme="dark"] .json-container {
            background-color: #1e1e1e;
        }
        
        /* Style for the details row */
        tr.details-row {
            background-color: rgba(0,0,0,0.02);
        }
        
        tr.details-row > td {
            padding: 0;
        }
        
        [data-theme="dark"] tr.details-row {
            background-color: rgba(255,255,255,0.05);
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
                <label for="timezone" class="form-label ms-3 me-2" style="color: var(--text-color)">Timezone:</label>
                <select id="timezone" class="form-select d-inline-block w-auto">
                    <option value="UTC">UTC</option>
                    <option value="America/New_York">Eastern Time (ET)</option>
                    <option value="America/Chicago">Central Time (CT)</option>
                    <option value="America/Denver">Mountain Time (MT)</option>
                    <option value="America/Los_Angeles">Pacific Time (PT)</option>
                    <option value="America/Anchorage">Alaska Time (AKT)</option>
                    <option value="Pacific/Honolulu">Hawaii Time (HT)</option>
                    <option value="Europe/London">London (GMT)</option>
                    <option value="Europe/Paris">Paris (CET)</option>
                    <option value="Europe/Helsinki">Helsinki (EET)</option>
                    <option value="Asia/Dubai">Dubai (GST)</option>
                    <option value="Asia/Shanghai">China (CST)</option>
                    <option value="Asia/Tokyo">Japan (JST)</option>
                    <option value="Asia/Kolkata">India (IST)</option>
                    <option value="Australia/Sydney">Sydney (AEST)</option>
                    <option value="Pacific/Auckland">New Zealand (NZST)</option>
                </select>
                <button id="theme-toggle" class="btn btn-outline-secondary ms-3">
                    <i class="bi bi-moon-stars"></i> Dark Mode
                </button>
            </div>
        </div>

        <!-- Total Requests Summary -->
        <div class="d-flex align-items-center mb-4">
            <i class="bi bi-info-circle-fill me-2"></i>
            <div title="In the selected time period">
                <span class="fw-bold">Total Requests:</span> <span id="all-requests-count">Loading...</span> 
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

							<div style="height: 200px;">
								<canvas id="responseCodeChart"></canvas>
							</div>
							
							<button class="btn btn-sm btn-outline-secondary mb-2" type="button" data-bs-toggle="collapse" data-bs-target="#filter-panel" aria-expanded="false">
								<i class="bi bi-funnel"></i> Filters
							</button>
							
							<div class="collapse mb-3" id="filter-panel">
								<div class="card card-body">
									<div class="row g-3">
										<div class="col-md-6">
											<label for="filter-url" class="form-label">URL Contains</label>
											<input type="text" class="form-control" id="filter-url" placeholder="Filter by URL path...">
										</div>
										<div class="col-md-3">
											<label for="filter-response-code" class="form-label">Response Code</label>
											<select class="form-select" id="filter-response-code">
												<option value="">Any</option>
												<option value="2">2xx (Success)</option>
												<option value="3">3xx (Redirect)</option>
												<option value="4">4xx (Client Error)</option>
												<option value="5">5xx (Server Error)</option>
												<option value="exact">Exact Code...</option>
											</select>
										</div>
										<div class="col-md-3" id="exact-code-container" style="display: none;">
											<label for="filter-exact-code" class="form-label">Exact Code</label>
											<input type="number" class="form-control" id="filter-exact-code" placeholder="e.g. 404">
										</div>
										<div class="col-md-3">
											<label for="filter-request-id" class="form-label">Request ID</label>
											<input type="text" class="form-control" id="filter-request-id" placeholder="e.g. req_1234abcd">
										</div>
										<div class="col-md-3">
											<label for="filter-bot" class="form-label">Bot Requests</label>
											<select class="form-select" id="filter-bot">
												<option value="">Any</option>
												<option value="1">Yes</option>
												<option value="0">No</option>
											</select>
										</div>
										<div class="col-md-6">
											<label for="filter-custom-event" class="form-label">Custom Event Type</label>
											<input type="text" class="form-control" id="filter-custom-event" placeholder="Filter by event type...">
										</div>
										<div class="col-md-3">
											<label for="filter-min-time" class="form-label">Min Time (ms)</label>
											<input type="number" class="form-control" id="filter-min-time" placeholder="e.g. 100">
										</div>
										<!-- New metadata filters -->
										<div class="col-md-3">
											<label for="filter-ip" class="form-label">IP Address</label>
											<input type="text" class="form-control" id="filter-ip" placeholder="e.g. 192.168.0.1">
										</div>
										<div class="col-md-3">
											<label for="filter-host" class="form-label">Host</label>
											<input type="text" class="form-control" id="filter-host" placeholder="e.g. example.com">
										</div>
										<div class="col-md-3">
											<label for="filter-session-id" class="form-label">Session ID</label>
											<input type="text" class="form-control" id="filter-session-id" placeholder="Filter by session...">
										</div>
										<div class="col-md-3">
											<label for="filter-user-agent" class="form-label">User Agent</label>
											<input type="text" class="form-control" id="filter-user-agent" placeholder="Contains...">
										</div>
										

                                        <!-- Replace existing Event Key/Value filters with this new section -->
                                        <div class="col-12">
                                            <div class="card border-light">
                                                <div class="card-header d-flex justify-content-between align-items-center">
                                                    <span>Custom Event Filters</span>
                                                    <button type="button" class="btn btn-sm btn-outline-primary" id="add-event-filter">
                                                        <i class="bi bi-plus-circle"></i> Add Filter
                                                    </button>
                                                </div>
                                                <div class="card-body p-2" id="event-filters-container">
                                                    <!-- Filter rows will be added here dynamically -->
                                                    <div class="text-muted small text-center py-2" id="no-event-filters-msg">
                                                        No event data filters. Click "Add Filter" to add one.
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Event filter template (hidden) -->
                                        <template id="event-filter-template">
                                            <div class="row g-2 mb-2 event-filter-row">
                                                <div class="col-4">
                                                    <select class="form-select form-select-sm event-key-select">
                                                        <option value="">Any Key</option>
                                                        <!-- Will be populated by JavaScript -->
                                                    </select>
                                                </div>
                                                <div class="col-3">
                                                    <select class="form-select form-select-sm event-operator-select">
                                                        <!-- Will be populated by JavaScript -->
                                                    </select>
                                                </div>
                                                <div class="col-4">
                                                    <input type="text" class="form-control form-control-sm event-value-input" placeholder="Value...">
                                                </div>
                                                <div class="col-1">
                                                    <button type="button" class="btn btn-sm btn-outline-danger remove-event-filter">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </template>

                                        <div class="col-12 text-end">
                                            <button type="button" class="btn btn-primary" id="apply-filters">Apply Filters</button>
                                            <button type="button" class="btn btn-outline-secondary" id="clear-filters">Clear Filters</button>
                                        </div>
									</div>
								</div>
							</div>

							
							<div id="active-filters" class="mb-2 d-none">
								<span class="fw-bold me-2">Active filters:</span>
								<div class="d-inline-block" id="active-filters-list"></div>
							</div>
						</div>
						<div class="table-responsive">
							<table class="table table-hover">
								<thead>
									<tr>
										<th>Timestamp</th>
										<th>Request URL</th>
										<th>Total Time (ms)</th>
										<th>Response Code</th>
										<th>IP</th>
										<th>Bot</th>
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
    <script src="/js/script.js?ver=4"></script>
</body>
</html>