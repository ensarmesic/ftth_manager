const CACHE = 'ftth-shell-v1',
    SHELL = ['/offline.html', '/manifest.webmanifest', '/images/logo.png'],
    DB_NAME = 'ftth-offline',
    STORE = 'requests';
self.addEventListener('install', (e) =>
    e.waitUntil(
        caches
            .open(CACHE)
            .then((c) => c.addAll(SHELL))
            .then(() => self.skipWaiting()),
    ),
);
self.addEventListener('activate', (e) =>
    e.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    ),
);
function database() {
    return new Promise((resolve, reject) => {
        const r = indexedDB.open(DB_NAME, 1);
        r.onupgradeneeded = () => r.result.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
        r.onsuccess = () => resolve(r.result);
        r.onerror = () => reject(r.error);
    });
}
async function queueRequest(request) {
    const db = await database(),
        body = await request.clone().text(),
        headers = Object.fromEntries(request.headers.entries());
    await new Promise((resolve, reject) => {
        const tx = db.transaction(STORE, 'readwrite');
        tx.objectStore(STORE).add({ url: request.url, method: request.method, headers, body, createdAt: Date.now() });
        tx.oncomplete = resolve;
        tx.onerror = () => reject(tx.error);
    });
    if (self.registration.sync) await self.registration.sync.register('ftth-field-sync');
}
async function replayQueue() {
    const db = await database(),
        rows = await new Promise((resolve, reject) => {
            const r = db.transaction(STORE).objectStore(STORE).getAll();
            r.onsuccess = () => resolve(r.result);
            r.onerror = () => reject(r.error);
        });
    for (const row of rows) {
        const response = await fetch(row.url, {
            method: row.method,
            headers: row.headers,
            body: row.body,
            credentials: 'include',
        });
        if (response.ok)
            await new Promise((resolve, reject) => {
                const tx = db.transaction(STORE, 'readwrite');
                tx.objectStore(STORE).delete(row.id);
                tx.oncomplete = resolve;
                tx.onerror = () => reject(tx.error);
            });
    }
    const clients = await self.clients.matchAll();
    clients.forEach((client) => client.postMessage({ type: 'ftth-sync-complete' }));
}
self.addEventListener('sync', (e) => {
    if (e.tag === 'ftth-field-sync') e.waitUntil(replayQueue());
});
self.addEventListener('message', (e) => {
    if (e.data?.type === 'ftth-sync-now') e.waitUntil(replayQueue());
});
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    if (event.request.method === 'POST' && /\/projekti\/\d+\/teren\/tacke$/.test(url.pathname)) {
        event.respondWith(
            fetch(event.request.clone()).catch(async () => {
                await queueRequest(event.request);
                return new Response(
                    JSON.stringify({
                        queued: true,
                        message: 'Tačka je sačuvana offline i bit će poslana kada se veza vrati.',
                    }),
                    { status: 202, headers: { 'Content-Type': 'application/json' } },
                );
            }),
        );
        return;
    }
    if (event.request.method === 'GET' && event.request.mode === 'navigate')
        event.respondWith(fetch(event.request).catch(() => caches.match('/offline.html')));
});
