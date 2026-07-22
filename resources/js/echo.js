import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;
const key = document.querySelector('meta[name="pusher-key"]')?.content || '';
const cluster = document.querySelector('meta[name="pusher-cluster"]')?.content || 'mt1';
if (key) {
    window.Echo = new Echo({
        broadcaster: 'pusher',
        key: key,
        cluster: cluster,
        forceTLS: true,
        authEndpoint: '/broadcasting/auth',
        auth: {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept': 'application/json',
            },
        },
    });
} else {
    window.Echo = null;
}
