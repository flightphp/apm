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
            <!-- Slowest Requests -->
            <div class="col-md-6 col-lg-4">
                <div class="card">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-clock me-2" style="color: var(--badge-primary)"></i> Top 5 Slowest Requests
                    </div>
                    <ul class="list-group list-group-flush" id="slow-requests"></ul>
                    <div class="card-footer bg-transparent text-center">
                        <a href="#" id="slow-requests-link" class="btn btn-outline-primary btn-sm">View Details</a>
                    </div>
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
        const rangeSelector = document.getElementById('range');
        const themeToggle = document.getElementById('theme-toggle');
        let isDarkMode = localStorage.getItem('darkMode') === 'true';

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

        rangeSelector.addEventListener('change', loadData);
        loadData();

        function loadData() {
            const range = rangeSelector.value;
            fetch(`/apm/data/dashboard?range=${range}`)
                .then(response => {
                    if (!response.ok) throw new Error('Network response was not ok');
                    return response.json();
                })
                .then(data => {
                    console.log('Data received:', data);
                    populateWidgets(data);
                    drawChart(data.chartData);
                    document.getElementById('slow-requests-link').href = `/apm/slow-requests?range=${range}`;
                })
                .catch(error => console.error('Error loading data:', error));
        }

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

            document.getElementById('p95').textContent = (data.p95 * 1000).toFixed(3);
            document.getElementById('p99').textContent = (data.p99 * 1000).toFixed(3);
        }

        let chart;
        function drawChart(chartData) {
            const ctx = document.getElementById('latencyChart').getContext('2d');
            if (chart) chart.destroy();
            chart = new Chart(ctx, {
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
        }
    </script>
</body>
</html>