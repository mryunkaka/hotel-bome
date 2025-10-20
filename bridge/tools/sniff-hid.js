// C:\laragon\www\hotel-bome\bridge\tools\sniff-hid.js
const HID = require('node-hid');

// Pakai PATH yang tadi kamu kirim (FORCE_DEVICE_PATH)
const PATH = '\\\\?\\HID#VID_5458&PID_0002#7&a2f077c&0&0000#{4d1e55b2-f16f-11cf-88cb-001111000030}';

function hex(buf) { return Buffer.from(buf).toString('hex').toUpperCase(); }

console.log('Open HID:', PATH);
const dev = new HID.HID(PATH);

dev.on('data', d => console.log('[event]', hex(d)));
dev.on('error', e => console.error('[error]', e.message || e));

setInterval(() => {
    try {
        const arr = dev.readTimeout(10); // [] saat tidak ada data
        if (arr && arr.length) console.log('[poll]', hex(arr));
    } catch (_) { }
}, 20);

console.log('Sniffing... tempel kartu sekarang.');
