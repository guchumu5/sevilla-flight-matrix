'use strict';
const CONFIG_CACHE = 'matrix-push-config-v1';
self.addEventListener('message', event => {
  if (!event.data || event.data.type !== 'matrix-device-token') return;
  event.waitUntil(caches.open(CONFIG_CACHE).then(cache => cache.put('/matrix-device-token', new Response(String(event.data.token || '')))));
});
self.addEventListener('push', event => {
  event.waitUntil((async () => {
    const cache = await caches.open(CONFIG_CACHE);
    const stored = await cache.match('/matrix-device-token');
    const token = stored ? await stored.text() : '';
    if (!token) return;
    const response = await fetch(`api/push.php?action=pending&device_token=${encodeURIComponent(token)}`, {cache:'no-store'});
    const data = await response.json();
    const notice = data.notification;
    if (!notice) return;
    await self.registration.showNotification(notice.title || 'Matriz Sevilla', {
      body: notice.body || 'Actualización operativa',
      tag: 'matrix-' + (notice.target_url || 'flight'),
      renotify: true,
      data: {url: notice.target_url || 'index.php'}
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
