// sw.js — service worker “neutro” (pass-through) para evitar cache offline antigo
// Atualize a URL de registro para forçar re-instalação: sw.js?v=DATA
self.addEventListener('install', (e) => {
  self.skipWaiting();
});
self.addEventListener('activate', (e) => {
  // Remove caches antigos se existirem
  if (self.registration && self.registration.unregister) {
    // opcional: manter registrado, mas vamos apenas assumir controle
  }
  e.waitUntil(self.clients.claim());
});
// Pass-through: não intercepta nem armazena nada
self.addEventListener('fetch', (event) => {
  // deixa seguir para a rede (sem cache custom)
});
