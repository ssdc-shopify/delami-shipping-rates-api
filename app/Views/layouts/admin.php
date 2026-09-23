<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($this->renderSection('title', true) ?: 'Delami Shipping') ?></title>
    <?php // integrity pins the exact files: a tampered CDN copy is refused rather than run. ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer
            integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <style>
        body { background: #f6f7f9; }
        .navbar-brand { font-weight: 700; letter-spacing: .3px; }
        .table td, .table th { vertical-align: middle; }

        /* Mock-mode bar: a persistent state indicator, not an alert to read
           every page load — so it stays one line, with the how-to on demand. */
        .mock-bar {
            background: #fdecea;
            border-bottom: 1px solid #f3c2bd;
            font-size: 13px;
            color: #8a1c13;
        }
        .mock-bar .inner {
            display: flex; align-items: center; gap: 10px;
            padding: 7px 24px; flex-wrap: wrap;
        }
        .mock-bar .tag {
            font-size: 11px; font-weight: 700; letter-spacing: .04em;
            background: #b3261e; color: #fff;
            border-radius: 4px; padding: 2px 7px;
        }
    </style>
</head>
<body>
<?php if (\App\Libraries\Awb\MockMode::enabled()): ?>
    <div class="mock-bar">
        <div class="inner">
            <span class="tag">MOCK MODE</span>
            <span>No courier is called — waybills are invented locally.</span>
            <a class="link-danger" href="<?= site_url('admin/settings') ?>">Change in Settings</a>
        </div>
    </div>
<?php endif; ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?= site_url('admin') ?>">Delami Shipping</a>
        <?php // navbar-expand-lg needs a toggler, or the links simply overflow below lg. ?>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                data-bs-target="#admin-nav" aria-controls="admin-nav"
                aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="admin-nav">
            <div class="navbar-nav me-auto">
                <a class="nav-link" href="<?= site_url('admin') ?>">Dashboard</a>
                <a class="nav-link" href="<?= site_url('admin/orders') ?>">Orders</a>
                <a class="nav-link" href="<?= site_url('admin/rate-simulator') ?>">Rate Simulator</a>
                <a class="nav-link" href="<?= site_url('admin/track-simulator') ?>">Track Simulator</a>
                <a class="nav-link" href="<?= site_url('admin/stores') ?>">Stores</a>
                <a class="nav-link" href="<?= site_url('admin/settings') ?>">Settings</a>
            </div>
            <div class="navbar-nav">
                <div class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <?= esc(auth()->user()->username ?? '') ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="<?= site_url('admin/profile') ?>">My Profile</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= site_url('logout') ?>">Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</nav>
<main class="container-fluid px-4">
    <?php if (session('message')): ?>
        <div class="alert alert-success"><?= esc(session('message')) ?></div>
    <?php endif; ?>
    <?php if (session('error')): ?>
        <div class="alert alert-danger"><?= esc(session('error')) ?></div>
    <?php endif; ?>
    <?= $this->renderSection('content') ?>
</main>
</body>
</html>
