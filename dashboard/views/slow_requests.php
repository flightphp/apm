<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Slow Requests - APM</title>
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
        }

        [data-theme="dark"] {
            --bg-color: #212529;
            --text-color: #f8f9fa;
            --card-bg: #343a40;
            --card-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
            --header-bg: #2c3034;
            --badge-primary: #6ea8fe;
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
        }
        .table {
            margin-bottom: 0;
        }
        .table th {
            background-color: var(--header-bg);
            border-top: none;
            font-weight: 600;
            color: var(--text-color);
        }
        .table td {
            vertical-align: middle;
            font-size: 0.95rem;
        }
        .badge {
            font-size: 0.9rem;
            font-weight: 500;
        }
        .badge-primary { background-color: var(--badge-primary); }
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
            <h1 class="display-6 fw-bold" style="color: var(--text-color)">
                <i class="bi bi-clock me-2" style="color: var(--badge-primary)"></i> Slow Requests (<?= ucfirst($range) ?>)
            </h1>
            <div>
                <button id="theme-toggle" class="btn btn-outline-secondary">
                    <i class="bi bi-moon-stars"></i> Dark Mode
                </button>
                <a href="/apm/dashboard" class="btn btn-outline-primary ms-3">
                    <i class="bi bi-arrow-left me-2"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th scope="col">Request URL</th>
                            <th scope="col">Total Time (ms)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $request): ?>
                            <tr>
                                <td><?= htmlspecialchars($request['request_url']) ?></td>
                                <td>
                                    <span class="badge bg-primary rounded-pill">
                                        <?php
                                        $time_ms = floatval($request['total_time']) * 1000;
                                        echo number_format($time_ms, 3);
                                        ?> ms
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Theme Toggle Script -->
    <script>
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
    </script>
</body>
</html>