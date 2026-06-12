@auth
    <span
        class="mmc-saudacao"
        style="display:flex; align-items:center; margin-right:0.75rem; font-size:0.875rem; font-weight:500; color:#374151; white-space:nowrap;"
    >
        Bem-vindo, {{ filament()->auth()->user()->name }}
    </span>
    <style>
        .dark .mmc-saudacao { color:#d1d5db; }
        @media (max-width: 640px) { .mmc-saudacao { display:none !important; } }
    </style>
@endauth
