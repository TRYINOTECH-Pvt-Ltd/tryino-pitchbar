<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment canceled</title>
    <style>
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
               background: #f8fafc; color: #0f172a; min-height: 100vh;
               display: flex; align-items: center; justify-content: center; padding: 24px; }
        .card { background: white; border-radius: 16px; padding: 32px; max-width: 420px; text-align: center;
                box-shadow: 0 2px 16px rgba(15, 23, 42, 0.08); }
        .x { width: 56px; height: 56px; border-radius: 50%; background: #fef2f2;
             display: inline-flex; align-items: center; justify-content: center;
             color: #b91c1c; font-size: 28px; margin-bottom: 16px; }
        h1 { margin: 0 0 8px; font-size: 20px; font-weight: 600; }
        p { margin: 0; color: #475569; font-size: 14px; line-height: 1.5; }
        button { margin-top: 20px; background: #0f172a; color: white; border: none;
                 border-radius: 8px; padding: 10px 20px; font-size: 14px; font-weight: 600;
                 cursor: pointer; }
    </style>
</head>
<body>
    <div class="card">
        <div class="x">×</div>
        <h1>Payment canceled</h1>
        <p>You can close this tab and return to the chat to try again or ask another question.</p>
        <button onclick="window.close()">Close this tab</button>
    </div>
</body>
</html>
