<?php
http_response_code(404);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>404 — Page Not Found</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: grid; place-items: center;
            font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
            background: #f6f7f9; color: #1f2937;
        }
        .card { text-align: center; padding: 2.5rem 3rem; max-width: 480px; }
        h1 { font-size: 4rem; margin: 0 0 .5rem; color: #2563eb; letter-spacing: -2px; }
        p  { margin: 0 0 1.5rem; color: #4b5563; line-height: 1.5; }
        a  {
            display: inline-block; padding: .6rem 1.4rem; border-radius: 8px;
            background: #2563eb; color: #fff; text-decoration: none;
            font-weight: 500; transition: background .15s;
        }
        a:hover { background: #1d4ed8; }
    </style>
</head>
<body>
    <div class="card">
        <h1>404</h1>
        <p>The page you're looking for doesn't exist or has been moved.</p>
        <a href="/">Go to homepage</a>
    </div>
</body>
</html>