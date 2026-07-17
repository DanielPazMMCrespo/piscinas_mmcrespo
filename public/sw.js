// Service worker mínimo — Piscinas MMCrespo (PWA leve).
//
// Estratégia: network-first sem cache de conteúdo dinâmico. Esta app gere
// registos sanitários legais; mostrar dados em cache desatualizados seria
// pior que mostrar um erro de rede. O SW existe sobretudo para tornar a app
// instalável no ecrã principal do telemóvel.
//
// NOTA: cache offline real (rascunhos em localStorage / sincronização) está
// planeado para o futuro — ver CLAUDE.md, "PWA com cache offline".

const VERSAO = 'mmcrespo-v2';

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

// Web Push: o servidor envia via VAPID e o browser acorda o SW mesmo com a app
// fechada. O payload é JSON produzido por App\Notifications\*::toWebPush().
self.addEventListener('push', (event) => {
    let payload = {};
    try {
        payload = event.data ? event.data.json() : {};
    } catch (e) {
        payload = { title: 'Piscinas MMCrespo', body: event.data ? event.data.text() : '' };
    }

    const dados = payload.data || {};
    const title = payload.title || 'Piscinas MMCrespo';
    const options = {
        body: payload.body || '',
        icon: payload.icon || '/images/icon-192.png',
        badge: payload.badge || '/images/icon-192.png',
        tag: payload.tag || undefined,
        renotify: Boolean(payload.tag),
        vibrate: payload.vibrate || [200, 100, 200],
        data: { url: dados.url || payload.url || '/admin' },
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const destino = (event.notification.data && event.notification.data.url) || '/admin';

    event.waitUntil(
        (async () => {
            const abas = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
            for (const aba of abas) {
                if ('focus' in aba) {
                    await aba.focus();
                    if ('navigate' in aba && destino) {
                        try { await aba.navigate(destino); } catch (e) { /* origem diferente: ignora */ }
                    }
                    return;
                }
            }
            if (self.clients.openWindow) {
                await self.clients.openWindow(destino);
            }
        })()
    );
});
