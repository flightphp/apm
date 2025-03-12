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
        body {
            background-color: #f8f9fa;
            font-family: 'Inter', sans-serif;
        }
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .table {
            margin-bottom: 0;
        }
        .table th {
            background-color: #fff;
            border-top: none;
            font-weight: 600;
            color: #333;
        }
        .table td {
            vertical-align: middle;
            font-size: 0.95rem;
        }
        .badge {
            font-size: 0.9rem;
            font-weight: 500;
        }
        .btn-outline-primary {
            transition: all 0.3s;
        }
        .btn-outline-primary:hover {
            background-color: #0d6efd;
            color: #fff;
        }
    </style>
</head>
<body>
    <div class="container py-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="display-6 fw-bold text-dark">
                <i class="bi bi-clock me-2 text-primary"></i> Slow Requests (<?= ucfirst($range) ?>)
            </h1>
            <a href="/" class="btn btn-outline-primary">
                <i class="bi bi-arrow-left me-2"></i> Back to Dashboard
            </a>
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
                                        // Convert total_time from seconds to milliseconds and format
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

    <!-- Bootstrap 5 JS (for any interactivity) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>