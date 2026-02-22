<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f7fa; padding: 40px; }
        .card { background: white; border-radius: 12px; padding: 40px; max-width: 420px; margin: 0 auto; box-shadow: 0 2px 12px rgba(0,0,0,0.08); }
        .logo { text-align: center; margin-bottom: 24px; color: #1976D2; font-size: 24px; font-weight: bold; }
        .code { text-align: center; font-size: 36px; letter-spacing: 10px; font-weight: bold; color: #1976D2; background: #E3F2FD; border-radius: 8px; padding: 16px; margin: 24px 0; }
        .text { color: #555; font-size: 14px; line-height: 1.6; text-align: center; }
        .footer { color: #999; font-size: 12px; text-align: center; margin-top: 24px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">Sotetel</div>
        <p class="text">Utilisez le code suivant pour vous connecter :</p>
        <div class="code">{{ $code }}</div>
        <p class="text">Ce code expire dans <strong>5 minutes</strong>.</p>
        <p class="text">Si vous n'avez pas fait cette demande, ignorez cet email.</p>
        <p class="footer">Sotetel - Smart Enabler</p>
    </div>
</body>
</html>
