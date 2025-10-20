// C:\laragon\www\hotel-bome\bridge\tools\list-hid.js
const HID = require('node-hid');

const devs = HID.devices();
const nice = d => ({
    vendorId: '0x' + (d.vendorId || 0).toString(16).padStart(4, '0'),
    productId: '0x' + (d.productId || 0).toString(16).padStart(4, '0'),
    usagePage: d.usagePage ? ('0x' + d.usagePage.toString(16)) : null,
    interface: d.interface ?? null,
    product: d.product || null,
    manufacturer: d.manufacturer || null,
    path: d.path
});

console.log('\n=== ALL HID DEVICES ===');
devs.forEach(d => console.log(nice(d)));

console.log('\n=== CANDIDATES (likely readers) ===');
const candidates = devs.filter(d => {
    const name = (d.product || '').toLowerCase();
    if (name.includes('hidi2c') || name.includes('touch') || name.includes('keyboard')) return false;
    if (d.vendorId === 0x04b8) return false; // Epson
    // prioritaskan usagePage vendor (0xff00/65280) atau known VID:PID
    return (d.vendorId === 0x5458 && d.productId === 0x0002)
        || d.usagePage === 0xff00 || d.usagePage === 65280;
});
candidates.forEach(d => console.log(nice(d)));
