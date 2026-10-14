<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>APM Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/css/style.css" rel="stylesheet">
</head>
<body>
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="dashboard-header mb-4">
            <h1 class="display-6 fw-bold mb-0">APM Dashboard</h1>
            <div class="dashboard-controls">
                <div>
                    <label for="range" class="form-label mb-1">Time range</label>
                    <select id="range" class="form-select">
                        <option value="last_hour">Last hour</option>
                        <option value="last_day">Last day</option>
                        <option value="last_week">Last week</option>
                        <option value="custom">Custom</option>
                    </select>
                </div>
                <div id="custom-range" class="d-none custom-range">
                    <div>
                        <label for="range-from" class="form-label mb-1">From</label>
                        <input type="datetime-local" id="range-from" class="form-control">
                    </div>
                    <div>
                        <label for="range-until" class="form-label mb-1">Until</label>
                        <input type="datetime-local" id="range-until" class="form-control">
                    </div>
                </div>
                <div>
                    <label for="timezone" class="form-label mb-1">Timezone</label>
                    <select id="timezone" class="form-select">
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
                </div>
                <div class="form-check form-switch align-self-end mb-2">
                    <input class="form-check-input" type="checkbox" id="exclude-bots">
                    <label class="form-check-label" for="exclude-bots">Hide bots</label>
                </div>
                <button id="theme-toggle" class="btn btn-outline-secondary align-self-end" type="button">
                    <i class="bi bi-moon-stars"></i> Dark mode
                </button>
            </div>
        </div>

        <div id="load-error" class="alert alert-danger d-none" role="alert"></div>

        <div class="row g-3 mb-4">
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="stat-label">Requests</div>
                        <div class="stat-value" id="all-requests-count">…</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="stat-label">Error rate</div>
                        <div class="stat-value" id="error-rate">…</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="stat-label">p95</div>
                        <div class="stat-value" id="p95">…</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="stat-label">p99</div>
                        <div class="stat-value" id="p99">…</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="stat-label">Avg latency</div>
                        <div class="stat-value" id="avg-latency">…</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="stat-label">Throughput</div>
                        <div class="stat-value" id="throughput">…</div>
                        <div class="stat-label">per minute</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-3 mb-4 small">
            <span>Cache hit rate <strong id="cache-hit-rate">…</strong></span>
            <span id="backlog-wrap" class="d-none">Unprocessed metrics <strong id="worker-backlog">0</strong></span>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-7">
                <div class="card h-100">
                    <div class="card-header">Latency and traffic</div>
                    <div class="card-body chart-panel">
                        <canvas id="latencyChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card h-100">
                    <div class="card-header">Status codes</div>
                    <div class="card-body chart-panel">
                        <canvas id="responseCodeChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div id="empty-window" class="alert alert-secondary d-none">No requests in this window.</div>

        <div class="row g-4 mb-4">
            <div class="col-md-6 col-xl-3">
                <div class="card h-100">
                    <div class="card-header">Slowest routes</div>
                    <ul class="list-group list-group-flush" id="slow-routes"></ul>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="card h-100">
                    <div class="card-header">Slowest middleware</div>
                    <ul class="list-group list-group-flush" id="slow-middleware"></ul>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="card h-100">
                    <div class="card-header">Slow queries</div>
                    <ul class="list-group list-group-flush" id="long-queries"></ul>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="card h-100">
                    <div class="card-header">Slowest requests</div>
                    <ul class="list-group list-group-flush" id="slow-requests"></ul>
                </div>
            </div>
            <div class="col-12">
                <div class="card">
                    <div class="card-header">Error groups</div>
                    <ul class="list-group list-group-flush" id="error-groups"></ul>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Request log</span>
                <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#filter-panel">
                    <i class="bi bi-funnel"></i> Filters
                </button>
            </div>
            <div class="card-body">
                <div class="collapse mb-3" id="filter-panel">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="filter-url" class="form-label">URL contains</label>
                            <input type="text" class="form-control" id="filter-url" placeholder="Filter by URL path">
                        </div>
                        <div class="col-md-3">
                            <label for="filter-response-code" class="form-label">Response code</label>
                            <select class="form-select" id="filter-response-code">
                                <option value="">Any</option>
                                <option value="2">2xx</option>
                                <option value="3">3xx</option>
                                <option value="4">4xx</option>
                                <option value="5">5xx</option>
                                <option value="exact">Exact code</option>
                            </select>
                        </div>
                        <div class="col-md-3 d-none" id="exact-code-container">
                            <label for="filter-exact-code" class="form-label">Exact code</label>
                            <input type="number" class="form-control" id="filter-exact-code" placeholder="404">
                        </div>
                        <div class="col-md-3">
                            <label for="filter-request-id" class="form-label">Request ID</label>
                            <input type="text" class="form-control" id="filter-request-id" placeholder="req_…">
                        </div>
                        <div class="col-md-3">
                            <label for="filter-bot" class="form-label">Bot requests</label>
                            <select class="form-select" id="filter-bot">
                                <option value="">Any</option>
                                <option value="1">Yes</option>
                                <option value="0">No</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="filter-min-time" class="form-label">Min time (ms)</label>
                            <input type="number" class="form-control" id="filter-min-time">
                        </div>
                        <div class="col-md-3">
                            <label for="filter-method" class="form-label">Route</label>
                            <input type="text" class="form-control" id="filter-route" placeholder="Exact route pattern">
                        </div>
                        <div class="col-md-3">
                            <label for="filter-ip" class="form-label">IP address</label>
                            <input type="text" class="form-control" id="filter-ip">
                        </div>
                        <div class="col-md-3">
                            <label for="filter-host" class="form-label">Host</label>
                            <input type="text" class="form-control" id="filter-host">
                        </div>
                        <div class="col-md-3">
                            <label for="filter-session-id" class="form-label">Session ID</label>
                            <input type="text" class="form-control" id="filter-session-id">
                        </div>
                        <div class="col-md-6">
                            <label for="filter-user-agent" class="form-label">User agent</label>
                            <input type="text" class="form-control" id="filter-user-agent">
                        </div>
                        <div class="col-md-6">
                            <label for="filter-custom-event" class="form-label">Custom event type</label>
                            <input type="text" class="form-control" id="filter-custom-event">
                        </div>
                        <div class="col-md-6">
                            <label for="filter-error" class="form-label">Error message</label>
                            <input type="text" class="form-control" id="filter-error">
                        </div>
                        <div class="col-12">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="fw-semibold">Custom event data</span>
                                <button type="button" class="btn btn-sm btn-outline-primary" id="add-event-filter">Add filter</button>
                            </div>
                            <div id="event-filters-container"></div>
                            <div class="text-muted small" id="no-event-filters-msg">No event data filters.</div>
                        </div>
                        <div class="col-12 text-end">
                            <button type="button" class="btn btn-primary" id="apply-filters">Apply filters</button>
                            <button type="button" class="btn btn-outline-secondary" id="clear-filters">Clear filters</button>
                        </div>
                    </div>
                </div>
                <div id="active-filters" class="mb-2 d-none">
                    <span class="fw-bold me-2">Active filters:</span>
                    <span id="active-filters-list"></span>
                </div>
                <div class="table-responsive request-table">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Method</th>
                                <th>URL</th>
                                <th>Status</th>
                                <th>Time</th>
                                <th>Memory</th>
                                <th>Host</th>
                                <th>Bot</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="request-log"></tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <span id="pagination-info"></span>
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

    <template id="event-filter-template">
        <div class="row g-2 mb-2 event-filter-row">
            <div class="col-md-4">
                <select class="form-select form-select-sm event-key-select">
                    <option value="">Any key</option>
                </select>
            </div>
            <div class="col-md-3">
                <select class="form-select form-select-sm event-operator-select">
                    <option value="exact">Equals</option>
                    <option value="contains">Contains</option>
                    <option value="starts_with">Starts with</option>
                    <option value="ends_with">Ends with</option>
                    <option value="greater_than">&gt;</option>
                    <option value="less_than">&lt;</option>
                    <option value="greater_than_equal">&gt;=</option>
                    <option value="less_than_equal">&lt;=</option>
                </select>
            </div>
            <div class="col-md-4">
                <input type="text" class="form-control form-control-sm event-value-input" placeholder="Value">
            </div>
            <div class="col-md-1">
                <button type="button" class="btn btn-sm btn-outline-danger remove-event-filter" aria-label="Remove filter">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>
    </template>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="/js/script.js?ver=5"></script>
</body>
</html>
