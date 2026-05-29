// Service worker mínimo — Piscinas MMCrespo (PWA leve).
//
// Estratégia: network-first sem cache de conteúdo dinâmico. Esta app gere
// registos sanitários legais; mostrar dados em cache desatualizados seria
// pior que mostrar um erro de rede. O SW existe sobretudo para tornar a app
// instalável no ecrã principal do telemóvel.
//
// NOTA: cache offline real (rascunhos em localStorage / sincronização) está
// planeado para o futuro — ver CLAUDE.md, "PWA com cache offline".

const VERSAO = 'mmcrespo-v1';

self.addEventListener('install', (event) => {
    // Ativa imediatamente a nova versão sem esperar pelas abas antigas.
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            // Limpa caches de versões anteriores, se existirem.
            const chaves = await caches.keys();
            await Promise.all(
                chaves.filter((c) => c !== VERSAO).map((c) => caches.delete(c))
            );
            await self.clients.claim();
        })()
    );
});

self.addEventListener('fetch', (event) => {
    // Só tratamos navegações GET; o resto segue o caminho normal da rede.
    if (event.request.method !== 'GET') {
        return;
    }

    event.respondWith(
        fetch(event.request).catch(() => {
            // Sem rede: devolve uma resposta simples (não há página offline cacheada).
            return new Response(
                '<h1>Sem ligação</h1><p>Esta aplicação precisa de internet. Verifique a ligação e tente novamente.</p>',
                { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
            );
        })
    );
});
