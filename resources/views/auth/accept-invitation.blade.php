<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Aceitar Convite — Piscinas MMCrespo</title>
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
  .meta {
    background: #f0f4ff;
    border: 1px solid #c7d2fe;
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 24px;
    font-size: 14px;
  }
  .meta div { margin: 3px 0; }
  .meta strong { color: #1e293b; }
  .badge {
    display: inline-block;
    background: #1d4ed8;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    padding: 2px 10px;
    border-radius: 99px;
    text-transform: uppercase;
    letter-spacing: .5px;
  }
  label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: #374151;
    margin: 16px 0 5px;
  }
  input[type=text],
  input[type=tel],
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
  .alert {
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: 8px;
    padding: 14px 16px;
    color: #991b1b;
    font-size: 13px;
    margin-bottom: 20px;
    line-height: 1.5;
  }
  .alert-expired {
    background: #fff7ed;
    border: 1px solid #fed7aa;
    color: #9a3412;
    text-align: center;
    padding: 28px;
  }
  .alert-expired h2 { font-size: 18px; margin-bottom: 8px; }
</style>
</head>
<body>
<div class="card">
  <div class="logo">Piscinas MMCrespo</div>

  @if($expired)
    <div class="alert alert-expired">
      <h2>Convite inválido ou expirado</h2>
      <p>Este link já foi utilizado ou expirou ao fim de 48 horas.<br>Contacte o administrador para receber um novo convite.</p>
    </div>
  @else
    <h1>Complete o seu registo</h1>
    <p class="subtitle">Preencha os seus dados para ativar a conta.</p>

    <div class="meta">
      <div><strong>Email:</strong> {{ $invitation->email }}</div>
      <div style="margin-top:6px"><strong>Cargo:</strong> <span class="badge">{{ $invitation->role }}</span></div>
    </div>

    @if($errors->any())
      <div class="alert">
        @foreach($errors->all() as $error)
          <div>{{ $error }}</div>
        @endforeach
      </div>
    @endif

    <form method="POST" action="{{ route('invitation.store', $invitation->token) }}">
      @csrf

      <label>Primeiro nome *</label>
      <input type="text" name="first_name" value="{{ old('first_name') }}" required autocomplete="given-name" placeholder="Ex: João">

      <label>Último nome *</label>
      <input type="text" name="last_name" value="{{ old('last_name') }}" required autocomplete="family-name" placeholder="Ex: Silva">

      <label>Telemóvel</label>
      <input type="tel" name="phone" value="{{ old('phone') }}" autocomplete="tel" placeholder="+351 9XX XXX XXX">
      <p class="hint">Opcional — usado para contacto em caso de urgência operacional.</p>

      <div class="section-title">Credenciais de acesso</div>
      <p class="section-hint">Defina uma palavra-passe, um PIN, ou ambos.</p>

      <label>Palavra-passe</label>
      <input type="password" name="password" autocomplete="new-password" placeholder="Mínimo 8 caracteres">

      <label>Confirmar palavra-passe</label>
      <input type="password" name="password_confirmation" autocomplete="new-password">

      <label>PIN numérico <span style="font-weight:400; color:#94a3b8">(4–6 dígitos)</span></label>
      <input type="number" name="pin" inputmode="numeric" placeholder="Ex: 1234" min="0" max="999999">
      <p class="hint">Para acesso rápido em tablet ou telemóvel no campo. Pode ter os dois.</p>

      <button class="btn" type="submit">Criar conta e entrar</button>
    </form>
  @endif
</div>
</body>
</html>
