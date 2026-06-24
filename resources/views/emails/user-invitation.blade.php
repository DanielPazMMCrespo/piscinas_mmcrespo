<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body { font-family: Arial, sans-serif; background: #f8fafc; color: #1e293b; margin: 0; padding: 20px; }
  .wrap { max-width: 560px; margin: 0 auto; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,.07); }
  .header { background: #1d4ed8; padding: 28px 32px; }
  .header h1 { color: #fff; margin: 0; font-size: 20px; font-weight: 700; }
  .header p { color: #bfdbfe; margin: 4px 0 0; font-size: 13px; }
  .body { padding: 28px 32px; }
  .body p { font-size: 15px; line-height: 1.6; margin: 0 0 16px; }
  .meta { background: #f0f4ff; border-left: 4px solid #1d4ed8; border-radius: 4px; padding: 12px 16px; margin: 20px 0; font-size: 14px; }
  .meta div { margin: 4px 0; }
  .badge { display: inline-block; background: #dbeafe; color: #1d4ed8; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 99px; text-transform: uppercase; letter-spacing: .5px; }
  .btn-wrap { text-align: center; margin: 28px 0; }
  .btn { display: inline-block; background: #1d4ed8; color: #fff; padding: 14px 32px; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 15px; }
  .btn:hover { background: #1e40af; }
  .note { font-size: 12px; color: #94a3b8; line-height: 1.5; }
  .footer { background: #f8fafc; padding: 16px 32px; font-size: 11px; color: #94a3b8; border-top: 1px solid #e2e8f0; }
</style>
</head>
<body>
<div class="wrap">
  <div class="header">
    <h1>Piscinas MMCrespo</h1>
    <p>Plataforma de gestão operacional</p>
  </div>
  <div class="body">
    <p>{{ $mensagem }}</p>
    <div class="meta">
      <div><strong>Email:</strong> {{ $email }}</div>
      <div><strong>Cargo:</strong> <span class="badge">{{ $role }}</span></div>
    </div>
    <div class="btn-wrap">
      <a href="{{ $url }}" class="btn">Aceitar Convite</a>
    </div>
    <p class="note">
      Este link é válido até <strong>{{ $expiresAt }}</strong> e só pode ser utilizado uma vez.<br>
      Se não esperava este convite, ignore este email.
    </p>
  </div>
  <div class="footer">
    Piscinas MMCrespo · Este email foi enviado para {{ $email }}
  </div>
</div>
</body>
</html>
