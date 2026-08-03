// Service worker expandido — Piscinas MMCrespo (PWA Offline / Sync).
//
// Estratégia:
// - Ativos estáticos (/build/assets/*, /images/*, manifesto): Stale-while-revalidate ou Cache-First.
// - Navegações HTML (/admin/*): Network-First com fallback para cache local, permitindo ao técnico abrir o formulário de registo e o dashboard mesmo no terreno sem rede.
// - Notificações Push: VAPID nativo para alertas e timers.

const VERSAO = 'mmcrespo-v15';

// Sem isto uma rede que aceita a ligação mas não responde (wifi municipal com
// captive portal, 4G fraca na casa das máquinas) pendura a navegação até o
// browser desistir — foram medidos 25 s por página. Passado o limite usamos a
// cache; a resposta da rede continua a atualizar a cache em segundo plano.
const TIMEOUT_NAVEGACAO_MS = 4000;

const TIMEOUT_ASSET_MS = 8000;

function fetchComTimeout(request, ms) {
    return new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error('timeout')), ms);
        fetch(request).then(
            (response) => {
                clearTimeout(timer);
                resolve(response);
            },
            (erro) => {
                clearTimeout(timer);
                reject(erro);
            }
        );
    });
}
const CORE_ASSETS = [
    '/manifest.json',
    '/images/icon-192.png',
    '/images/logo-mmcrespo.png',
    '/admin',
    '/admin/daily-records/create'
];

self.addEventListener('install', (event) => {
    self.skipWaiting();
    event.waitUntil(
        caches.open(VERSAO).then((cache) => {
            return cache.addAll(CORE_ASSETS).catch(() => {
                // Se algum endpoint falhar durante a instalação, o SW instala na mesma
            });
        })
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const chaves = await caches.keys();
            await Promise.all(
                chaves.filter((c) => c !== VERSAO).map((c) => caches.delete(c))
            );
            await self.clients.claim();
        })()
    );
});

self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') {
        return;
    }

    const url = new URL(event.request.url);

    // Pedidos a outras origens (fontes, CDNs) não passam pelo SW: não os podemos
    // cachear com utilidade e só serviam para atrasar tudo o que corre aqui.
    if (url.origin !== self.location.origin) {
        return;
    }

    const isStaticAsset = url.pathname.startsWith('/build/') || url.pathname.startsWith('/images/') || url.pathname === '/manifest.json';
    const isNavigation = event.request.mode === 'navigate';

    if (isStaticAsset) {
        event.respondWith(
            caches.match(event.request).then((cachedResponse) => {
                const fetchPromise = fetchComTimeout(event.request, TIMEOUT_ASSET_MS).then((networkResponse) => {
                    if (networkResponse && networkResponse.status === 200) {
                        const responseClone = networkResponse.clone();
                        caches.open(VERSAO).then((cache) => cache.put(event.request, responseClone));
                    }
                    return networkResponse;
                }).catch(() => cachedResponse);

                return cachedResponse || fetchPromise;
            })
        );
        return;
    }

    if (isNavigation) {
        event.respondWith(
            fetchComTimeout(event.request, TIMEOUT_NAVEGACAO_MS).then((networkResponse) => {
                if (networkResponse && networkResponse.status === 200) {
                    const responseClone = networkResponse.clone();
                    caches.open(VERSAO).then((cache) => cache.put(event.request, responseClone));
                }
                return networkResponse;
            }).catch(async () => {
                const cachedResponse = await caches.match(event.request);
                if (cachedResponse) {
                    return cachedResponse;
                }
                const fallbackForm = await caches.match('/admin/daily-records/create');
                if (fallbackForm && url.pathname.includes('/daily-records/create')) {
                    return fallbackForm;
                }
                const fallbackAdmin = await caches.match('/admin');
                if (fallbackAdmin) {
                    return fallbackAdmin;
                }
                return new Response(
                    '<h1>Sem ligação e sem cache</h1><p>Esta aplicação não conseguiu carregar a página offline. Verifique a ligação.</p>',
                    { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
                );
            })
        );
        return;
    }

    event.respondWith(
        fetchComTimeout(event.request, TIMEOUT_ASSET_MS)
            .catch(() => caches.match(event.request))
            .then((resposta) => resposta ?? fetch(event.request))
    );
});

// Web Push: o servidor envia via VAPID e o browser acorda o SW mesmo com a app fechada.
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
        actions: payload.actions || [],
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
                    // Só navega se o separador não estiver já no destino — navegar
                    // para o mesmo URL ainda recarrega a página e apaga um registo
                    // diário a meio de preenchimento (era o próprio bug reportado).
                    const jaNoDestino = new URL(aba.url).pathname === new URL(destino, self.location.origin).pathname;
                    if (!jaNoDestino && 'navigate' in aba && destino) {
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
