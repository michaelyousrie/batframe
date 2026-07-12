<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Batframe')</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; background: #0f172a; color: #e2e8f0; }
        .wrap { max-width: 640px; margin: 0 auto; padding: 4rem 1.5rem; }
        h1 { font-size: 2.5rem; margin: 0 0 .5rem; }
        a { color: #38bdf8; }
        code { background: #1e293b; padding: .15rem .4rem; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="wrap">
        @yield('content')
    </div>
</body>
</html>
