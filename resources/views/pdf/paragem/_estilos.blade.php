<style>
    @page {
        margin: 85px 36px 50px 36px;
    }

    * {
        box-sizing: border-box;
    }

    body {
        font-family: "DejaVu Sans", sans-serif;
        font-size: 8px;
        color: #111;
        margin: 0;
        line-height: 1.35;
    }

    /* Cabeçalho fixo */
    .cabecalho-fixo {
        position: fixed;
        top: -70px;
        left: 0;
        right: 0;
        border-bottom: 1.5px solid #111;
        padding-bottom: 5px;
    }
    .cabecalho-fixo .titulo {
        font-size: 11px;
        font-weight: bold;
        text-transform: uppercase;
        margin: 0 0 2px 0;
        letter-spacing: 0.5px;
    }
    .cabecalho-fixo .subtitulo {
        font-size: 8px;
        color: #333;
        margin: 0;
    }
    .cabecalho-fixo .marca {
        position: absolute;
        top: 0;
        right: 0;
        text-align: right;
        font-size: 8.5px;
        font-weight: bold;
    }
    .cabecalho-fixo .marca .emissao {
        font-size: 7px;
        font-weight: normal;
        color: #555;
        margin-top: 2px;
    }

    /* Secções e quebras */
    .seccao {
        margin-bottom: 14px;
    }
    .seccao-titulo {
        font-size: 9px;
        font-weight: bold;
        text-transform: uppercase;
        background: #e5e7eb;
        border-left: 3px solid #1f2937;
        padding: 4px 6px;
        margin: 10px 0 6px 0;
    }
    .quebra {
        page-break-before: always;
    }

    /* Identificação / Bloco de Metadados */
    .meta-grid {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 8px;
        font-size: 8px;
    }
    .meta-grid td {
        padding: 3px 6px;
        border: 1px solid #d1d5db;
        vertical-align: top;
    }
    .meta-grid .label {
        font-weight: bold;
        color: #374151;
        background: #f9fafb;
        width: 25%;
    }

    /* Resumo / Contagem */
    .resumo-bloco {
        background: #f3f4f6;
        border: 1px solid #d1d5db;
        padding: 6px 8px;
        margin-bottom: 10px;
        font-size: 8.5px;
    }
    .resumo-bloco strong {
        color: #111827;
    }

    /* Tabelas */
    table.tabela-dados {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
        word-wrap: break-word;
        margin-bottom: 8px;
    }
    table.tabela-dados thead {
        display: table-header-group;
    }
    table.tabela-dados tr {
        page-break-inside: avoid;
    }
    table.tabela-dados th,
    table.tabela-dados td {
        border: 0.5px solid #9ca3af;
        padding: 4px 5px;
        vertical-align: top;
        font-size: 7.5px;
    }
    table.tabela-dados th {
        background: #f3f4f6;
        font-weight: bold;
        text-align: left;
        color: #1f2937;
    }
    table.tabela-dados tr.linha-inferida {
        background: #f9fafb;
        border-left: 2.5px solid #6b7280;
    }

    /* Badges / Destaques de Estado */
    .badge {
        display: inline-block;
        padding: 1px 4px;
        font-size: 7px;
        font-weight: bold;
        border-radius: 2px;
        text-transform: uppercase;
    }
    .badge-executado { background: #dcfce7; color: #166534; border: 0.5px solid #86efac; }
    .badge-previsto { background: #fef9c3; color: #854d0e; border: 0.5px solid #fde047; }
    .badge-nao-executado { background: #fee2e2; color: #991b1b; border: 0.5px solid #fca5a5; }
    .badge-nao-aplicavel { background: #f3f4f6; color: #4b5563; border: 0.5px solid #d1d5db; }
    .badge-obrigatorio { background: #ede9fe; color: #5b21b6; font-size: 6.5px; font-weight: bold; padding: 1px 3px; }

    /* Galeria de Fotos */
    .fotos-grid {
        width: 100%;
        margin-top: 6px;
    }
    .foto-box {
        display: inline-block;
        width: 31%;
        margin-right: 2%;
        margin-bottom: 8px;
        vertical-align: top;
        border: 1px solid #d1d5db;
        padding: 3px;
        background: #fafafa;
        text-align: center;
        page-break-inside: avoid;
    }
    .foto-box img {
        max-width: 100%;
        max-height: 140px;
        height: auto;
        display: block;
        margin: 0 auto;
    }
    .foto-legenda {
        font-size: 6.5px;
        color: #4b5563;
        margin-top: 3px;
        text-align: center;
    }

    /* Bloco "O que não é possível provar" */
    .aviso-limitacoes {
        background: #fffbeb;
        border: 1px solid #fef3c7;
        border-left: 3px solid #d97706;
        padding: 6px 8px;
        font-size: 7.5px;
        margin: 10px 0;
        page-break-inside: avoid;
    }
    .aviso-limitacoes h4 {
        margin: 0 0 4px 0;
        font-size: 8px;
        color: #92400e;
        text-transform: uppercase;
    }
    .aviso-limitacoes ul {
        margin: 0;
        padding-left: 15px;
        color: #78350f;
    }
    .aviso-limitacoes li {
        margin-bottom: 2px;
    }

    /* Assinaturas */
    .assinaturas-grid {
        width: 100%;
        border-collapse: collapse;
        margin-top: 15px;
        page-break-inside: avoid;
    }
    .assinaturas-grid td {
        width: 33.33%;
        padding: 8px 10px;
        vertical-align: top;
    }
    .caixa-assinatura {
        border-top: 1px solid #111;
        padding-top: 4px;
        text-align: center;
        font-size: 7.5px;
    }
    .caixa-assinatura .cargo {
        font-weight: bold;
        font-size: 8px;
        margin-bottom: 2px;
    }

    /* Gráficos SVG */
    .grafico-wrapper {
        margin: 8px 0;
        text-align: center;
        border: 0.5px solid #d1d5db;
        background: #fff;
        padding: 6px;
        page-break-inside: avoid;
    }
    .grafico-legenda {
        font-size: 7px;
        color: #4b5563;
        margin-top: 3px;
    }

    .nota-legal {
        font-size: 6.5px;
        color: #6b7280;
        border-top: 0.5px solid #d1d5db;
        padding-top: 4px;
        margin-top: 10px;
        page-break-inside: avoid;
    }
</style>
