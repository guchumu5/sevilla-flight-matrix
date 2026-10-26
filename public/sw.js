'use strict';
const CONFIG_CACHE = 'matrix-push-config-v1';
self.addEventListener('install', event => event.waitUntil(self.skipWaiting()));
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()));
self.addEventListener('message', event => {
  if (!event.data) return;
  if (event.data.type === 'matrix-device-token') {
    event.waitUntil(caches.open(CONFIG_CACHE).then(cache => cache.put('/matrix-device-token', new Response(String(event.data.token || '')))));
    return;
  }
  if (event.data.type === 'matrix-show-notice' && event.data.notice) {
    event.waitUntil(show(event.data.notice));
  }
});

function show(notice) {
  return self.registration.showNotification(notice.title || 'Matriz Sevilla', {
    body: notice.body || 'Actualización operativa',
    tag: notice.tag || 'matrix-' + (notice.target_url || 'flight'),
    renotify: true,
    data: {url: notice.target_url || 'index.php'}
  });
}

const payloadNotice = event => {
  if (!event.data) return null;
  try {
    const notice = event.data.json();
    return notice && (notice.title || notice.body) ? notice : null;
  } catch (_) {
    return null;
  }
};

async function pendingNotice() {
  const cache = await caches.open(CONFIG_CACHE);
  const stored = await cache.match('/matrix-device-token');
  const token = stored ? await stored.text() : '';
  if (!token) return null;
  const endpoint = new URL('api/push.php', self.registration.scope);
  endpoint.searchParams.set('action', 'pending');
  endpoint.searchParams.set('device_token', token);
  const response = await fetch(endpoint.href, {cache:'no-store'});
  if (!response.ok) return null;
  const data = await response.json();
  return data.notification || null;
}

self.addEventListener('push', event => {
  event.waitUntil((async () => {
    const direct = payloadNotice(event);
    if (direct) return show(direct);
    let notice = null;
    try {
      notice = await pendingNotice();
    } catch (_) {
      notice = null;
    }
    // iOS revoca la suscripción si un push no termina en una notificación visible.
    return show(notice || {
      title: 'Matriz Sevilla',
      body: 'Hay novedades en tus vuelos vigilados. Abre la app para verlas.',
      tag: 'matrix-generic',
      target_url: 'index.php'
    });
  })());
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  event.waitUntil(clients.matchAll({type:'window',includeUncontrolled:true}).then(list => {
    const target = new URL(event.notification.data?.url || 'index.php', self.location.href).href;
    for (const client of list) { if ('focus' in client) { client.navigate(target); return client.focus(); } }
    return clients.openWindow ? clients.openWindow(target) : undefined;
  }));
});
