<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Piscina Encerrada — Piscinas MMCrespo</title>
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
    max-width: 480px;
    width: 100%;
  }
  .logo { font-size: 13px; font-weight: 700; color: #1d4ed8; letter-spacing: .5px; text-transform: uppercase; margin-bottom: 6px; }
  h1 { font-size: 22px; font-weight: 800; margin-bottom: 4px; }
  .subtitle { color: #64748b; font-size: 14px; margin-bottom: 20px; }
  .alert-warning {
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 8px;
    padding: 14px 16px;
    margin-bottom: 16px;
    font-size: 13px;
    line-height: 1.6;
    color: #92400e;
  }
  .alert-warning strong { display: block; font-size: 14px; margin-bottom: 2px; }
  .alert-info {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 20px;
    font-size: 13px;
    line-height: 1.5;
    color: #1e40af;
  }
  .alert-danger {
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: 8px;
    padding: 12px 16px;
    color: #991b1b;
    font-size: 13px;
    margin-bottom: 20px;
    line-height: 1.5;
  }
  label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: #374151;
    margin: 16px 0 5px;
  }
  textarea {
    width: 100%;
    padding: 10px 14px;
    border: 1.5px solid #e2e8f0;
    border-radius: 8px;
    font-size: 15px;
    outline: none;
    transition: border-color .15s;
    font-family: inherit;
    resize: vertical;
  }
  textarea:focus { border-color: #1d4ed8; box-shadow: 0 0 0 3px rgba(29,78,216,.1); }
  .hint { font-size: 11px; color: #94a3b8; margin-top: 4px; line-height: 1.4; }
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
    margin-top: 20px;
    transition: background .15s;
  }
  .btn:hover { background: #1e40af; }
  .btn-ghost {
    width: 100%;
    padding: 12px;
    background: transparent;
    color: #64748b;
    border: none;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    margin-top: 10px;
    text-decoration: underline;
  }
</style>
</head>
<body>
<div class="card">
  <div class="logo">Piscinas MMCrespo</div>
  <h1>Piscina encerrada</h1>
  <p class="subtitle">Não pode aceder à app enquanto a sua piscina estiver encerrada.</p>

  @foreach ($piscinas as $piscina)
    @php($encerramento = $piscina->encerramentoEm())
    <div class="alert-warning">
      <strong>{{ $piscina->nome_completo }}</strong>
      Motivo: {{ $encerramento?->motivo_label ?? 'Sem motivo registado' }}<br>
      Período: {{ $encerramento?->descricao_periodo ?? '—' }}
    </div>
  @endforeach

  @if (session('success'))
    <div class="alert-info">{{ session('success') }}</div>
  @endif

  @if ($errors->any())
    <div class="alert-danger">
      @foreach ($errors->all() as $error)
        <div>{{ $error }}</div>
      @endforeach
    </div>
  @endif

  @if ($pedidoPendente)
    <div class="alert-info">
      Pedido enviado a {{ $pedidoPendente->created_at->format('d/m/Y H:i') }}. A aguardar resposta do administrador.
    </div>
  @elseif ($ultimoPedido && $ultimoPedido->status === 'negado')
    <div class="alert-danger">
      O seu último pedido foi negado{{ $ultimoPedido->resposta_admin ? ': '.$ultimoPedido->resposta_admin : '.' }}
      Pode enviar um novo pedido abaixo.
    </div>
  @endif

  @if (! $pedidoPendente)
    <form method="POST" action="/piscinas-encerradas/pedir-acesso">
      @csrf

      <label for="motivo">Motivo do pedido de acesso *</label>
      <textarea id="motivo" name="motivo" rows="3" required minlength="10" maxlength="1000" placeholder="Explique porque precisa de aceder à app agora">{{ old('motivo') }}</textarea>
      <p class="hint">O administrador vê este motivo e decide se lhe concede acesso.</p>

      <button class="btn" type="submit">Pedir acesso ao administrador</button>
    </form>
  @endif

  <form method="POST" action="/admin/logout">
    @csrf
    <button class="btn-ghost" type="submit">Sair</button>
  </form>
</div>
</body>
</html>
