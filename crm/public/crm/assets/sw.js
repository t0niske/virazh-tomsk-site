// Сервис-воркер админки: только показ уведомлений о заявках и переход по нажатию на них. Ничего не кэширует
// и сеть не трогает. Нужен потому, что часть браузеров (Яндекс Браузер, Chrome на Android) не показывает
// уведомления, созданные прямо со страницы (new Notification), — только через сервис-воркер.
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (event) { event.waitUntil(self.clients.claim()); });

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var url = (event.notification.data && event.notification.data.url) || '/crm/queue';

  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    for (var i = 0; i < list.length; i++) {
      var client = list[i];
      if (client.url.indexOf('/crm') === -1) continue;
      return client.focus().then(function (c) {
        // вкладка под воркером переходит сама, остальным — сообщение, страница перейдёт по нему (crm.js)
        if (c.navigate) return c.navigate(url).catch(function () { c.postMessage({ open: url }); });
        c.postMessage({ open: url });
      }).catch(function () { return self.clients.openWindow(url); });
    }
    return self.clients.openWindow(url);
  }));
});
