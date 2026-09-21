import * as mapCore from './map-core.js';

window.FtthMapCore = mapCore;

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js'));
    window.addEventListener('online', () => navigator.serviceWorker.controller?.postMessage({ type: 'ftth-sync-now' }));
    navigator.serviceWorker.addEventListener('message', (event) => {
        if (event.data?.type === 'ftth-sync-complete')
            window.ftthToast?.('Offline terenski unosi su sinhronizovani.', 'success');
    });
}
