<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php // saveData is left false on purpose: passing true keeps the section
          // between renders, so two pages rendered in one process (a test run,
          // a queue worker) concatenate their titles. ?>
    <title><?= esc($this->renderSection('title') ?: 'Track your order') ?></title>
    <?php // A shipment page is per-shopper and changes as the parcel moves. ?>
    <meta name="robots" content="noindex, nofollow">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <style>
        :root { --ink: #12151a; --muted: #6b7280; --line: #e5e7eb; --accent: #12151a; }
        body {
            background: #f6f7f9;
            color: var(--ink);
            -webkit-font-smoothing: antialiased;
        }
        .site-head {
            background: #12151a;
            color: #fff;
            padding: 18px 0;
        }
        .site-head .brand {
            font-weight: 700;
            letter-spacing: .22em;
            text-transform: uppercase;
            font-size: 15px;
            color: #fff;
            text-decoration: none;
        }
        .site-head .sub {
            color: rgba(255,255,255,.6);
            font-size: 12px;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .shell { max-width: 720px; }
        .card { border: 1px solid var(--line); border-radius: 12px; }
        .site-foot {
            color: var(--muted);
            font-size: 12.5px;
            padding: 28px 0 40px;
        }
    </style>
</head>
<body>
<header class="site-head">
    <div class="container shell d-flex align-items-baseline justify-content-between">
        <a class="brand" href="<?= site_url('track') ?>"><?= esc(config('Couriers')->shipperBrand ?: 'Delami') ?></a>
        <span class="sub">Order tracking</span>
    </div>
</header>

<main class="container shell py-4 py-md-5">
    <?= $this->renderSection('content') ?>
</main>

<footer class="container shell site-foot">
    Tracking information comes from the courier carrying your parcel and can take
    a few hours to appear after your order is dispatched.
</footer>
</body>
</html>
