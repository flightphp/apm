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

			<!-- Response Code Distribution -->
            <div class="col-md-12 col-lg-12">
                <div class="card">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-pie-chart me-2" style="color: var(--badge-primary)"></i> Response Code Distribution
                    </div>
                    <div class="card-body">
                        <canvas id="responseCodeChart" height="100"></canvas>
                    </div>
                </div>
            </div>

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
    <script src="/js/script.js"></script>
</body>
</html>