// Web Push: subscrição do dispositivo e registo/cancelamento dos timers de
// retrolavagem no servidor. O envio efetivo é feito pelo servidor via VAPID; o
// service worker (public/sw.js) mostra a notificação mesmo com a app fechada.

const suportado = () =>
    'serviceWorker' in navigator &&
    'PushManager' in window &&
    'Notification' in window;

const csrfToken = () =>
    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

const urlBase64ToUint8Array = (base64String) => {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(base64);
    const output = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) {
        output[i] = raw.charCodeAt(i);
    }
    return output;
};

const postJson = (url, body, method = 'POST') =>
    fetch(url, {
        method,
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
            Accept: 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });

const getRegistration = async () => {
    const reg = await navigator.serviceWorker.getRegistration();
    return reg ?? navigator.serviceWorker.ready;
};

const guardarSubscription = async (subscription) => {
    const json = subscription.toJSON();
    const response = await postJson('/push/subscribe', {
        endpoint: json.endpoint,
        keys: json.keys,
        // Safari/iOS não implementa supportedContentEncodings e só fala aes128gcm
        // (RFC 8291) — aesgcm é o esquema antigo/descontinuado.
        contentEncoding:
            (PushManager.supportedContentEncodings || ['aes128gcm'])[0] ?? 'aes128gcm',
    });
    if (!response.ok) {
        throw new Error(`Falha ao registar subscrição (HTTP ${response.status})`);
    }
};

// iOS só permite push quando o site está instalado no ecrã inicial (PWA).
export const iosPrecisaInstalar = () => {
    const ios = /iphone|ipad|ipod/i.test(navigator.userAgent);
    const standalone =
        window.navigator.standalone === true ||
        window.matchMedia('(display-mode: standalone)').matches;
    return ios && !standalone;
};

export const estadoNotificacoes = () => {
    if (!suportado()) return 'nao-suportado';
    if (iosPrecisaInstalar()) return 'ios-instalar';
    return Notification.permission; // 'default' | 'granted' | 'denied'
};

export const ativarNotificacoes = async () => {
    if (!suportado()) return { ok: false, estado: 'nao-suportado' };
    if (iosPrecisaInstalar()) return { ok: false, estado: 'ios-instalar' };
    if (!window.__vapidPublicKey) return { ok: false, estado: 'sem-vapid' };

    const permissao = await Notification.requestPermission();
    if (permissao !== 'granted') return { ok: false, estado: permissao };

    try {
        const registration = await getRegistration();
        let subscription = await registration.pushManager.getSubscription();

        if (!subscription) {
            subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(window.__vapidPublicKey),
            });
        }

        await guardarSubscription(subscription);
        return { ok: true, estado: 'granted' };
    } catch (error) {
        console.error("Erro ao subscrever push service:", error);
        return { ok: false, estado: 'erro-subscricao', erroMsg: error.message };
    }
};

// Re-sync silencioso: mantém a subscription do servidor fresca a cada carregamento.
const sincronizarSubscription = async () => {
    if (!suportado() || Notification.permission !== 'granted') return;
    try {
        const registration = await getRegistration();
        const subscription = await registration.pushManager.getSubscription();
        if (subscription) {
            await guardarSubscription(subscription);
        }
    } catch (e) {
        /* silencioso */
    }
};

// --- Timers de retrolavagem -------------------------------------------------

export const registarTimer = (seconds, poolId, fase) => {
    if (!suportado() || Notification.permission !== 'granted') return;
    postJson('/push/timer', { seconds, pool_id: poolId, fase }).catch(() => {});
};

export const cancelarTimer = (poolId, fase) => {
    if (!suportado()) return;
    postJson('/push/timer', { pool_id: poolId, fase }, 'DELETE').catch(() => {});
};

export const cancelarTodosTimers = () => {
    if (!suportado()) return;
    postJson('/push/timer', {}, 'DELETE').catch(() => {});
};

// Expõe as funções ao Alpine/blade (botão de ativar, componente do timer).
window.mmcPush = {
    estado: estadoNotificacoes,
    ativar: ativarNotificacoes,
    iosPrecisaInstalar,
    registarTimer,
    cancelarTimer,
    cancelarTodosTimers,
};

if (suportado()) {
    window.addEventListener('load', () => {
        sincronizarSubscription();
    });
}
