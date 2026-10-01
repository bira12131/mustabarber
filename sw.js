const VERSION = '1.5.3';  // ← ALTERE AQUI quando fizer atualizações
const CACHE_NAME = `mustabarber-v${VERSION}`;
const ASSETS_CACHE = `mustabarber-assets-v${VERSION}`;
const PAGES_CACHE = `mustabarber-pages-v${VERSION}`;

console.log(`[SW] Service Worker vers&#65533;o ${VERSION}`);

const ESSENTIAL_ASSETS = [
  './login.html',
  './paginas/home.php',
  './paginas/dashboard/dashboard.html',
  './uploads/logo/logo.png',
  './manifest.json'
];

self.addEventListener('install', (event) => {
  console.log(`[SW ${VERSION}] Instalando...`);
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(ESSENTIAL_ASSETS.map(url => new Request(url, { cache: 'reload' })));
    }).catch((error) => console.error(`[SW ${VERSION}] Erro:`, error))
  );
});

self.addEventListener('activate', (event) => {
  console.log(`[SW ${VERSION}] Ativando...`);
  event.waitUntil(
    Promise.all([
      caches.keys().then((cacheNames) => {
        return Promise.all(cacheNames.map((cacheName) => {
          if (cacheName !== CACHE_NAME && cacheName !== ASSETS_CACHE && cacheName !== PAGES_CACHE) {
            return caches.delete(cacheName);
          }
        }));
      }),
      self.clients.claim()
    ]).then(() => {
      return self.clients.matchAll().then(clients => {
        clients.forEach(client => client.postMessage({ type: 'SW_UPDATED', version: VERSION }));
      });
    })
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);
  if (!request.url.startsWith('http') || request.method !== 'GET') return;

  if (url.pathname.includes('/bancodedados/') || url.pathname.endsWith('.php')) {
    // Requisições dinâmicas (PHP/API) nunca devem ser cacheadas — sempre buscar da rede
    return;
  } else if (url.pathname.match(/\.(jpg|jpeg|png|gif|svg|ico|webp|css|js|woff|woff2|ttf|eot)$/i) || 
             url.pathname.includes('/assets/') || url.pathname.includes('/uploads/')) {
    event.respondWith(
      caches.match(request).then((cachedResponse) => {
        if (cachedResponse) return cachedResponse;
        return fetch(request).then((response) => {
          if (response && response.status === 200) {
            const responseClone = response.clone(); // clonar antes de qualquer operação assíncrona
            caches.open(ASSETS_CACHE).then((cache) => cache.put(request, responseClone));
          }
          return response;
        });
      })
    );
  }
});

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});