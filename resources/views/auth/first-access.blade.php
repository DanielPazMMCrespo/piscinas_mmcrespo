<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Alterar Palavra-passe — Piscinas MMCrespo</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    background: #f1f5f9;
    color: #1e293b;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
  }
  .card {
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 4px 28px rgba(0,0,0,.09);
    padding: 40px;
    max-width: 460px;
    width: 100%;
  }
  .logo { font-size: 13px; font-weight: 700; color: #1d4ed8; letter-spacing: .5px; text-transform: uppercase; margin-bottom: 6px; }
  h1 { font-size: 22px; font-weight: 800; margin-bottom: 4px; }
  .subtitle { color: #64748b; font-size: 14px; margin-bottom: 20px; }
  .alert-info {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 24px;
    font-size: 13px;
    line-height: 1.5;
    color: #1e40af;
  }
  label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: #374151;
    margin: 16px 0 5px;
  }
  input[type=password],
  input[type=number] {
    width: 100%;
    padding: 10px 14px;
    border: 1.5px solid #e2e8f0;
    border-radius: 8px;
    font-size: 15px;
    outline: none;
    transition: border-color .15s;
    -webkit-appearance: none;
  }
  input:focus { border-color: #1d4ed8; box-shadow: 0 0 0 3px rgba(29,78,216,.1); }
  .hint { font-size: 11px; color: #94a3b8; margin-top: 4px; line-height: 1.4; }
  .section-title {
    text-align: center;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: #94a3b8;
    margin: 24px 0 4px;
  }
  .section-hint { font-size: 12px; color: #64748b; text-align: center; margin-bottom: 4px; }
  .btn {
    width: 100%;
    padding: 14px;
    background: #1d4ed8;
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 16px;
    font-weight: 700;
    cursor: pointer;
    margin-top: 28px;
    transition: background .15s;
  }
  .btn:hover { background: #1e40af; }
  .alert-error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: 8px;
    padding: 12px 16px;
    color: #991b1b;
    font-size: 13px;
    margin-bottom: 20px;
    line-height: 1.5;
  }
</style>
</head>
<body>
<div class="card">
  <div class="logo">Piscinas MMCrespo</div>
  <h1>Alterar palavra-passe</h1>
  <p class="subtitle">Primeira vez que acede. Defina uma nova palavra-passe pessoal.</p>

  <div class="alert-info">
    ⚠️ Por questões de segurança, a password inicial não pode continuar a ser utilizada.
  </div>

  @if($errors->any())
    <div class="alert-error">
      @foreach($errors->all() as $error)
        <div>{{ $error }}</div>
      @endforeach
    </div>
  @endif

  <form method="POST" action="/primeiro-acesso">
    @csrf

    <label>Nova palavra-passe *</label>
    <input type="password" name="password" required autocomplete="new-password" placeholder="Mínimo 8 caracteres">
    <p class="hint">Deve ser diferente da password inicial.</p>

    <label>Confirmar palavra-passe *</label>
    <input type="password" name="password_confirmation" required autocomplete="new-password">

    <div class="section-title">PIN numérico (opcional)</div>
    <p class="section-hint">Para acesso rápido em campo.</p>

    <label>PIN <span style="font-weight:400; color:#94a3b8">(4–6 dígitos)</span></label>
    <input type="number" name="pin" inputmode="numeric" placeholder="Ex: 1234" min="0" max="999999">
    <p class="hint">Pode ter tanto a palavra-passe como o PIN.</p>

    <button class="btn" type="submit">Confirmar e continuar</button>
  </form>
</div>
</body>
</html>
