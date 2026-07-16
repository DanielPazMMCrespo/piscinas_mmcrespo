<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acesso não autorizado — Piscinas MMCrespo</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: #f4f6f8;
            color: #1f2937;
            padding: 1.5rem;
        }
        .card {
            max-width: 26rem;
            width: 100%;
            background: #fff;
            border-radius: 1rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            padding: 2.5rem 2rem;
            text-align: center;
        }
        img { height: 2.5rem; margin-bottom: 1.5rem; }
        h1 { font-size: 1.25rem; margin: 0 0 0.5rem; }
        p { color: #6b7280; margin: 0 0 1.5rem; line-height: 1.5; }
        a.btn {
            display: inline-block;
            background: #2563eb;
            color: #fff;
            text-decoration: none;
            padding: 0.6rem 1.5rem;
            border-radius: 0.5rem;
            font-weight: 600;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="card">
        <img src="{{ asset('images/logo-mmcrespo.png') }}" alt="Piscinas MMCrespo">
        @if (session('mmc_inativo'))
            <h1>Ficou sem acesso</h1>
            <p>A sua conta foi encerrada por inatividade. Se acha que isto é um engano, contacte o administrador.</p>
        @else
            <h1>Não tem acesso a esta página</h1>
            <p>A sua conta não tem permissão para ver ou realizar esta ação. Se acha que isto é um engano, contacte o administrador.</p>
        @endif
        <a class="btn" href="{{ url('/admin') }}">Voltar ao Painel</a>
    </div>
</body>
</html>
