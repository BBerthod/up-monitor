<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0b0c0e">
    <title>500 - Server Error | Up</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            font-family: 'IBM Plex Sans', ui-sans-serif, system-ui, sans-serif;
            background: #0b0c0e; color: #e8e8ea; line-height: 1.5; font-size: 14px;
            min-height: 100vh; display: flex; flex-direction: column;
            align-items: center; justify-content: center; padding: 24px;
        }
        .container { width: 100%; max-width: 440px; }
        .brand { display: inline-flex; align-items: center; gap: 8px; margin-bottom: 24px; color: #e8e8ea; text-decoration: none; font-weight: 600; font-size: 16px; }
        .brand-mark { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border: 1px solid #26272c; border-radius: 6px; background: #131417; }
        .brand-mark svg { width: 16px; height: 16px; color: #10b981; }
        .card { background: #131417; border: 1px solid #26272c; border-radius: 6px; padding: 32px; }
        .status {
            display: inline-flex; align-items: center; gap: 8px; margin-bottom: 16px;
            font-family: 'IBM Plex Mono', ui-monospace, monospace; font-size: 12px; font-weight: 500;
            letter-spacing: 0.08em; text-transform: uppercase; color: #f87171;
        }
        .status::before { content: ''; width: 8px; height: 8px; border-radius: 50%; background: #ef4444; }
        .title { font-size: 20px; font-weight: 600; color: #e8e8ea; margin-bottom: 8px; }
        .desc { color: #9a9aa1; margin-bottom: 24px; }
        .btn {
            display: inline-flex; align-items: center; gap: 8px; min-height: 36px;
            background: #10b981; color: #0b0c0e; font: 500 14px/20px 'IBM Plex Sans', sans-serif;
            padding: 8px 16px; border-radius: 6px; border: 1px solid #10b981;
            text-decoration: none; cursor: pointer; transition: background-color 0.15s ease;
        }
        .btn:hover { background: #34d399; border-color: #34d399; }
        .btn:focus-visible { outline: 2px solid #10b981; outline-offset: 2px; }
        .footer { margin-top: 24px; font-size: 13px; color: #85858c; }
        @media (max-width: 480px) { .card { padding: 24px; } }
    </style>
</head>
<body>
    <main class="container">
        <a href="/" class="brand" aria-label="Up home">
            <span class="brand-mark"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
            Up
        </a>
        <div class="card">
            <p class="status">500 &middot; Error detected</p>
            <h1 class="title">Server Error</h1>
            <p class="desc">Something went wrong on our end. We've been notified and are working on it.</p>
            <a href="/dashboard" class="btn">Back to Dashboard</a>
        </div>
        <p class="footer">Powered by Up</p>
    </main>
</body>
</html>
