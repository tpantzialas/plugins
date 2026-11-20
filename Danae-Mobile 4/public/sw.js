const CACHE = 'danae-mobile-shell-v1-2-10';
const SHELL = ['/manifest.webmanifest?v=1.2.10', '/assets/app.css?v=1.2.10', '/assets/app.js?v=1.2.10', '/assets/danae-hero-emblem.webp', '/assets/danae-emblem-white.webp', '/assets/danae-icon.png?v=1.2.10'];
self.addEventListener('install', event => event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(SHELL)).then(() => self.skipWaiting())));
self.addEventListener('activate', event => event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key.startsWith('danae-mobile-shell-') && key !== CACHE).map(key => caches.delete(key)))).then(() => self.clients.claim())));
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);
  if (url.origin !== self.location.origin || url.pathname.startsWith('/api/')) return;
  if (event.request.mode === 'navigate') return; // Never cache the session page or its CSRF token.
  event.respondWith(caches.match(event.request).then(hit => hit || fetch(event.request)));
});
self.addEventListener('push', event => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; } catch { data = { title: 'Danae Mobile', body: 'Υπάρχει νέα ειδοποίηση.' }; }
  const title = String(data.title || 'Danae Mobile').slice(0, 120);
  const options = { body: String(data.body || '').slice(0, 220), icon: '/assets/danae-icon.png', badge: '/assets/danae-icon.png', tag: String(data.tag || 'danae-notification').slice(0, 120), renotify: false, data: { url: String(data.url || '/') } };
  event.waitUntil(self.registration.showNotification(title, options));
});
self.addEventListener('notificationclick', event => {
  event.notification.close();
  const target = new URL(event.notification.data?.url || '/', self.location.origin);
  if (target.protocol !== 'https:') return;
  event.waitUntil(clients.openWindow(target.href));
});
