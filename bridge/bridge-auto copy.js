// bridge/bridge-auto.js — HID/Serial + Keyboard-Wedge Mirroring (DLock-friendly)
require('dotenv').config();

/* ================== TLS mkcert (Laragon) ================== */
try {
    const fs = require('fs');
    const path = require('path');
    const { Agent, setGlobalDispatcher } = require('undici');
    const candidates = [
        path.join(process.env.HOMEPATH || '', 'AppData', 'Local', 'mkcert', 'rootCA.pem'),
        path.join(process.env.USERPROFILE || '', 'AppData', 'Local', 'mkcert', 'rootCA.pem'),
        'C:\\laragon\\bin\\mkcert\\rootCA.pem',
        'C:\\laragon\\etc\\ssl\\laragon.crt',
    ];
    const found = candidates.find(p => fs.existsSync(p));
    if (found) {
        const ca = fs.readFileSync(found, 'utf8');
        setGlobalDispatcher(new Agent({ connect: { ca } }));
        console.log('[INFO] TLS: mkcert root CA loaded for Node fetch:', found);
    } else {
        console.log('[WARN] Tidak menemukan mkcert rootCA.pem');
    }
} catch (e) {
    console.log('[WARN] TLS setup gagal:', e.message);
}

/* ================== Imports & Config ================== */
const HID = require('node-hid');
const express = require('express');
const cors = require('cors');

const BRIDGE_PORT = process.env.BRIDGE_PORT || 8200;
const BRIDGE_TOKEN = process.env.BRIDGE_TOKEN || 'hotelbome-bridge-2025';
const LARAVEL_API = (process.env.LARAVEL_API || 'http://hotel-bome.test').replace(/\/+$/, '');
const DEBOUNCE_MS = Number(process.env.DEBOUNCE_MS || 2000);
const FETCH_TIMEOUT_MS = Number(process.env.FETCH_TIMEOUT_MS || 5000);
const RETRIES = Number(process.env.BRIDGE_RETRIES || 2);
const RETRY_BASE_DELAY_MS = Number(process.env.RETRY_BASE_DELAY_MS || 400);

const POLL_INTERVAL = Number(process.env.POLL_INTERVAL || 100);
const READ_TIMEOUT_MS = Number(process.env.READ_TIMEOUT || 10);
const BURST_WINDOW_MS = Number(process.env.BURST_WINDOW_MS || 120);

const PREFERRED_VID = process.env.BRIDGE_VENDOR_ID ? parseInt(process.env.BRIDGE_VENDOR_ID) : null;
const PREFERRED_PID = process.env.BRIDGE_PRODUCT_ID ? parseInt(process.env.BRIDGE_PRODUCT_ID) : null;
const FORCE_DEVICE_PATH = process.env.FORCE_DEVICE_PATH || null;

const WAIT_FOR_FREE_MS = Number(process.env.WAIT_FOR_FREE_MS || 15000);
const WAIT_STEP_MS = Number(process.env.WAIT_STEP_MS || 300);

// SERIAL (opsional)
const SERIAL_PORT = process.env.SERIAL_PORT || null;
const SERIAL_BAUD = Number(process.env.SERIAL_BAUD || 9600);

// WEDGE (baru) — mirroring ketikan reader (sinkron dengan DLock)
const BRIDGE_WEDGE = String(process.env.BRIDGE_WEDGE || 'auto').toLowerCase(); // "auto" | "1" | "0"
const WEDGE_EXPECT_HEX = process.env.WEDGE_EXPECT_HEX !== '0'; // hanya kumpulkan [0-9A-F]
const WEDGE_TERMINATOR = process.env.WEDGE_TERMINATOR || 'enter'; // "enter" | "tab" | "none"
const WEDGE_MIN_LEN = Number(process.env.WEDGE_MIN_LEN || 6);
const WEDGE_MAX_LEN = Number(process.env.WEDGE_MAX_LEN || 24);
const WEDGE_IDLE_TIMEOUT_MS = Number(process.env.WEDGE_IDLE_TIMEOUT_MS || 250);

/* ================== State ================== */
let lastUID = null;
let lastScanTime = 0;
let activeDevice = null;
let inFlight = false;
let queue = [];
let wedgeActive = false;

/* ================== Utils ================== */
function log(level, message, data = undefined) {
    const timestamp = new Date().toISOString();
    const suffix = data === undefined ? '' : ' ' + JSON.stringify(data);
    console.log(`[${timestamp}] [${level}] ${message}${suffix}`);
}
const sleep = (ms) => new Promise(r => setTimeout(r, ms));

async function fetchWithTimeout(url, opts = {}, timeoutMs = 5000) {
    const { setTimeout: setT, clearTimeout: clearT } = require('timers');
    const controller = new AbortController();
    const id = setT(() => controller.abort(), timeoutMs);

    try {
        const merged = {
            // header standar JSON
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Bridge-Token': BRIDGE_TOKEN,
                ...(opts.headers || {}),
            },
            method: opts.method || 'POST',
            body: opts.body,
            signal: controller.signal,
        };
        const res = await fetch(url, merged);
        return res;
    } finally {
        clearT(id);
    }
}

function summarizeText(t, max = 300) {
    if (!t) return '';
    t = String(t);
    return t.length <= max ? t : t.slice(0, max) + '…';
}

/* ================== UID helpers ================== */
function bestUidFromBurst(buffers) {
    const counts = new Map();
    const inc = (hex) => {
        if (!hex) return;
        if (/^(00|FF)+$/i.test(hex)) return;
        counts.set(hex, (counts.get(hex) || 0) + 1);
    };
    for (const b of buffers) {
        if (!b || !b.length) continue;
        const ascii = Buffer.from(b).toString('utf8').replace(/[\x00-\x1F\x7F-\x9F]/g, '').trim();
        if (/^(?:[0-9A-F]{8}|[0-9A-F]{14}|[0-9A-F]{20})$/i.test(ascii)) inc(ascii.toUpperCase());
        const LENS = [4, 7, 10];
        for (const L of LENS) {
            if (b.length >= L) {
                for (let off = 0; off <= b.length - L && off < 12; off++) {
                    const sliceHex = b.slice(off, off + L).toString('hex').toUpperCase();
                    inc(sliceHex);
                }
            }
        }
    }
    if (!counts.size) return null;
    let best = null, bestScore = -1;
    for (const [hex, freq] of counts.entries()) {
        const lenScore = hex.length;
        const tailPenalty = /(00|FF)$/i.test(hex) ? -0.5 : 0;
        const statusPenalty = /^(0001|0100|00FF|FF00)/i.test(hex) ? -0.25 : 0;
        const score = freq * 10 + lenScore + tailPenalty + statusPenalty;
        if (score > bestScore) { bestScore = score; best = hex; }
    }
    return best;
}

function normalizeUid(uid) {
    return String(uid || '').replace(/[^0-9A-F]/gi, '').toUpperCase();
}

/* ================== HTTP → Laravel (queue+retry) ================== */
async function sendToLaravel(uid) {
    uid = normalizeUid(uid);
    if (!uid) return;

    const now = Date.now();
    if (uid === lastUID && (now - lastScanTime) < DEBOUNCE_MS) return;
    lastUID = uid;
    lastScanTime = now;

    queue.push(uid);
    if (inFlight) return;

    inFlight = true;
    while (queue.length) {
        const nextUid = queue.shift();
        const url = `${LARAVEL_API}/api/card-scan`;
        let attempt = 0, sent = false;

        while (attempt <= RETRIES && !sent) {
            const isRetry = attempt > 0;
            if (isRetry) {
                const delay = RETRY_BASE_DELAY_MS * Math.pow(2, attempt - 1);
                log('INFO', `⏳ Retry #${attempt} setelah ${delay}ms`);
                await sleep(delay);
            }
            attempt++;

            try {
                log('INFO', `📤 Kirim UID → Laravel: ${nextUid} (try ${attempt}/${RETRIES + 1})`, { url });
                const payload = {
                    uid: nextUid,
                    scanned_at: new Date().toISOString(),
                    reader_id: wedgeActive ? 'bridge-wedge' : 'bridge-hid',
                };

                const res = await fetchWithTimeout(url, {
                    method: 'POST',
                    headers: { 'X-Bridge-Token': BRIDGE_TOKEN, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                }, FETCH_TIMEOUT_MS);

                if (res.ok) {
                    log('SUCCESS', `✅ UID terkirim: ${nextUid}`);
                    sent = true;
                    break;
                }

                const text = summarizeText(await res.text().catch(() => ''), 600);
                log('ERROR', `❌ HTTP ${res.status} dari Laravel`, { body: text });

                // Kalau 403/404/419: hentikan retry karena perlu perbaikan sisi server
                if ([403, 404, 419].includes(res.status)) break;

            } catch (e) {
                const msg = String(e?.message || e);
                if (msg.includes('self signed') || msg.includes('certificate') || msg.includes('UNABLE_TO_VERIFY')) {
                    log('ERROR', '🔒 TLS error. Pakai HTTP lokal atau trust sertifikat.');
                    break;
                }
                if (msg.includes('aborted')) log('ERROR', `⏱️ Timeout ${FETCH_TIMEOUT_MS}ms.`);
            }
        }
    }
    inFlight = false;
}

/* ================== HID Probe ================== */
async function testDevice(deviceInfo) {
    const deadline = Date.now() + WAIT_FOR_FREE_MS;
    while (Date.now() < deadline) {
        let dev = null;
        try {
            dev = new HID.HID(deviceInfo.path);
            try { dev.close(); } catch { }
            return true;
        } catch {
            try {
                if (deviceInfo.vendorId && deviceInfo.productId) {
                    dev = new HID.HID(deviceInfo.vendorId, deviceInfo.productId);
                    try { dev.close(); } catch { }
                    return true;
                }
            } catch { }
        }
        await sleep(WAIT_STEP_MS);
    }
    return false;
}

async function autoDetectDevice() {
    log('INFO', '🔍 Auto-detecting Mifare reader…');
    const all = HID.devices();

    if (FORCE_DEVICE_PATH) {
        const forced = all.find(d => d.path === FORCE_DEVICE_PATH);
        if (forced) {
            log('INFO', '📌 FORCE_DEVICE_PATH match', { path: forced.path });
            return (await testDevice(forced)) ? forced : null;
        } else log('WARN', 'FORCE_DEVICE_PATH tidak ditemukan');
    }

    const skip = d => {
        const name = (d.product || '').toLowerCase();
        if (name.includes('hidi2c') || name.includes('touch') || name.includes('keyboard')) return true;
        if (d.vendorId === 0x04b8) return true; // Epson dsb yang sering "bising"
        return false;
    };

    const preferred = all.filter(d => !skip(d) && PREFERRED_VID && PREFERRED_PID && d.vendorId === PREFERRED_VID && d.productId === PREFERRED_PID);
    const known = all.filter(d =>
        !skip(d) && (
            (d.vendorId === 0x5458 && d.productId === 0x0002) ||           // banyak reader Wiegand/HID generic USB
            (d.vendorId === 0x072f && [0x2200, 0x90cc].includes(d.productId)) ||
            (d.vendorId === 0x076b && [0x5421, 0x5427].includes(d.productId))
        )
    );
    const vendorUsage = all.filter(d => !skip(d) && (d.usagePage === 0xff00 || d.usagePage === 65280));
    const generic = all.filter(d => !skip(d) && (d.usagePage === 0x01 || d.usagePage === 1));
    const candidates = [...preferred, ...known, ...vendorUsage, ...generic];
    const unique = [...new Map(candidates.map(d => [d.path, d])).values()];
    log('INFO', `📋 Kandidat: ${unique.length}`);

    for (let i = 0; i < unique.length; i++) {
        const d = unique[i];
        const vid = `0x${(d.vendorId || 0).toString(16).padStart(4, '0')}`;
        const pid = `0x${(d.productId || 0).toString(16).padStart(4, '0')}`;
        const usage = `0x${(d.usagePage || 0).toString(16)}`;
        log('INFO', `🔬 Test ${i + 1}: VID=${vid} PID=${pid} Usage=${usage} Product=${d.product || 'Unknown'}`);
        if (await testDevice(d)) {
            log('SUCCESS', `✅ Bisa dibuka`);
            return d;
        } else {
            log('WARN', `⚠️  Tidak bisa dibuka (in-use/perms)`);
        }
    }
    return null;
}

/* ================== Serial Mode ================== */
async function startSerial() {
    const { SerialPort } = require('serialport');
    const { ReadlineParser } = require('@serialport/parser-readline');

    log('INFO', `🔌 Serial mode: port=${SERIAL_PORT}, baud=${SERIAL_BAUD}`);
    let burst = [];
    let burstTimer = null;

    function flushBurst(reason = 'serial') {
        if (!burst.length) return;
        const uidHex = bestUidFromBurst(burst);
        burst = [];
        if (!uidHex) return;
        log('INFO', `📡 Kartu (${reason}): ${uidHex}`);
        sendToLaravel(uidHex);
    }

    const port = new SerialPort({ path: SERIAL_PORT, baudRate: SERIAL_BAUD }, (err) => {
        if (err) {
            log('ERROR', 'Serial open error: ' + (err.message || err));
            process.exit(3);
        }
    });

    const parser = port.pipe(new ReadlineParser({ delimiter: '\n' }));
    parser.on('data', (line) => {
        const s = String(line || '').trim();
        if (!s) return;
        burst.push(Buffer.from(s, 'utf8'));
        if (burstTimer) clearTimeout(burstTimer);
        burstTimer = setTimeout(() => flushBurst('serial-line'), BURST_WINDOW_MS);
    });
    port.on('data', (buf) => {
        burst.push(Buffer.from(buf));
        if (burstTimer) clearTimeout(burstTimer);
        burstTimer = setTimeout(() => flushBurst('serial-raw'), BURST_WINDOW_MS);
    });
    port.on('error', (e) => {
        log('ERROR', 'Serial error: ' + (e?.message || e));
        setTimeout(() => process.exit(4), 800);
    });

    startHttpServer({ mode: 'serial', lastProvider: () => ({ lastUID, lastScanTime }) });
}

/* ================== Keyboard-Wedge Mode (sinkron DLock) ================== */
function startWedge() {
    let buffer = '';
    let timer = null;
    wedgeActive = true;
    log('INFO', `🧲 Wedge mode aktif (mirroring). Terminator=${WEDGE_TERMINATOR}, hexOnly=${WEDGE_EXPECT_HEX}`);

    const iohook = require('iohook');

    const flush = (reason) => {
        const raw = buffer;
        buffer = '';
        const uid = normalizeUid(raw);
        if (!uid) return;
        if (uid.length < WEDGE_MIN_LEN || uid.length > WEDGE_MAX_LEN) return;
        log('INFO', `📡 Kartu (wedge:${reason}): ${uid}`);
        sendToLaravel(uid);
    };

    const scheduleIdleFlush = () => {
        if (WEDGE_IDLE_TIMEOUT_MS <= 0) return;
        if (timer) clearTimeout(timer);
        timer = setTimeout(() => flush('idle'), WEDGE_IDLE_TIMEOUT_MS);
    };

    iohook.on('keypress', e => {
        const kc = e.keychar || 0;

        if (WEDGE_TERMINATOR === 'enter' && e.keycode === 28) { flush('enter'); return; }
        if (WEDGE_TERMINATOR === 'tab' && e.keycode === 15) { flush('tab'); return; }

        if (kc > 0) {
            const ch = String.fromCharCode(kc);
            if (WEDGE_EXPECT_HEX) {
                if (/[0-9A-Fa-f]/.test(ch)) { buffer += ch; scheduleIdleFlush(); }
            } else {
                buffer += ch;
                scheduleIdleFlush();
            }
        }
    });

    iohook.on('keydown', e => {
        if (e.keycode === 14 && buffer.length) { // Backspace
            buffer = buffer.slice(0, -1);
            scheduleIdleFlush();
        }
    });

    iohook.start();

    startHttpServer({ mode: 'wedge', lastProvider: () => ({ lastUID, lastScanTime }) });
}

/* ================== HID Mode ================== */
async function startWithDevice(deviceInfo) {
    const vid = `0x${(deviceInfo.vendorId || 0).toString(16).padStart(4, '0')}`;
    const pid = `0x${(deviceInfo.productId || 0).toString(16).padStart(4, '0')}`;
    log('INFO', `🎯 Device: VID=${vid} PID=${pid} Product=${deviceInfo.product || 'Unknown'} Path=${deviceInfo.path}`);

    try {
        activeDevice = new HID.HID(deviceInfo.path);
        log('SUCCESS', '✅ Device dibuka');
    } catch (e) {
        log('ERROR', `❌ Gagal membuka device: ${e?.message || e}`);
        return false;
    }

    console.log('\n' + '='.repeat(60));
    console.log('🎯 Bridge aktif. Tempelkan kartu Mifare ke reader…');
    console.log('='.repeat(60) + '\n');

    let burst = [];
    let burstTimer = null;
    const flushBurst = (reason = 'event') => {
        if (!burst.length) return;
        const uidHex = bestUidFromBurst(burst);
        burst = [];
        if (!uidHex) return;
        log('INFO', `📡 Kartu (${reason}): ${uidHex}`);
        sendToLaravel(uidHex);
    };

    activeDevice.on('data', (data) => {
        const buf = Buffer.isBuffer(data) ? data : Buffer.from(data);
        burst.push(buf);
        if (burstTimer) clearTimeout(burstTimer);
        burstTimer = setTimeout(() => flushBurst('event'), BURST_WINDOW_MS);
    });

    const pollTimer = setInterval(() => {
        try {
            const arr = activeDevice.readTimeout(READ_TIMEOUT_MS);
            if (arr && arr.length) {
                const buf = Buffer.isBuffer(arr) ? arr : Buffer.from(arr);
                burst.push(buf);
                if (burstTimer) clearTimeout(burstTimer);
                burstTimer = setTimeout(() => flushBurst('poll'), BURST_WINDOW_MS);
            }
        } catch { }
    }, POLL_INTERVAL);

    activeDevice.on('error', (e) => {
        log('ERROR', `❌ Device error: ${e?.message || e}`);
        try { if (burstTimer) clearTimeout(burstTimer); } catch { }
        try { clearInterval(pollTimer); } catch { }
        log('INFO', 'Bridge akan restart…');
        setTimeout(() => process.exit(4), 800);
    });

    startHttpServer({
        mode: 'hid',
        deviceInfo: { vid, pid, product: deviceInfo.product || 'Unknown', path: deviceInfo.path, manufacturer: deviceInfo.manufacturer || null },
        lastProvider: () => ({ lastUID, lastScanTime })
    });

    process.on('SIGINT', () => {
        log('INFO', 'Shutting down…');
        try { if (burstTimer) clearTimeout(burstTimer); } catch { }
        try { activeDevice?.close(); } catch { }
        process.exit(0);
    });

    return true;
}

/* ================== HTTP server (Health & Debug) ================== */
function startHttpServer(ctx) {
    const app = express();
    app.use(cors());
    app.use(express.json());

    app.get('/health', (_req, res) => {
        const lp = ctx.lastProvider ? ctx.lastProvider() : {};
        res.json({
            ok: true,
            mode: ctx.mode,
            device: ctx.deviceInfo || null,
            lastScan: lastUID ? {
                uid: lastUID,
                timestamp: new Date(lastScanTime).toISOString(),
                ago_s: Math.round((Date.now() - lastScanTime) / 1000)
            } : null,
            config: {
                laravelApi: LARAVEL_API,
                debounceMs: DEBOUNCE_MS,
                fetchTimeoutMs: FETCH_TIMEOUT_MS,
                retries: RETRIES,
                wedge: { enabled: wedgeActive, terminator: WEDGE_TERMINATOR, minLen: WEDGE_MIN_LEN, maxLen: WEDGE_MAX_LEN }
            },
            uptime_s: Math.round(process.uptime()),
        });
    });

    app.get('/status', (_req, res) => res.json({ status: 'running', mode: ctx.mode }));

    app.post('/debug/laravel', async (req, res) => {
        const uid = req.body?.uid || 'DEBUG-' + Math.random().toString(36).slice(2, 10).toUpperCase();
        await sendToLaravel(uid);
        res.json({ tried: true, uid, target: `${LARAVEL_API}/api/card-scan` });
    });

    app.listen(BRIDGE_PORT, '127.0.0.1', () => {
        log('SUCCESS', `🛰️  HTTP Server: http://127.0.0.1:${BRIDGE_PORT}`);
        log('INFO', `🧪 Debug POST: curl -X POST http://127.0.0.1:${BRIDGE_PORT}/debug/laravel -H "Content-Type: application/json" -d "{\"uid\":\"TEST1234\"}"`);
    });
}

/* ================== Main ================== */
(async function main() {
    console.log('\n' + '='.repeat(60));
    console.log('  🏨 HOTEL BOME - Mifare Card Reader Bridge v2.2 (HID + Wedge + Serial)');
    console.log('  ⚙️  Auto-Detect • Retry • DLock-Sync (Keyboard-Wedge Mirroring)');
    console.log('='.repeat(60) + '\n');

    log('INFO', `Laravel API: ${LARAVEL_API}`);
    log('INFO', `Bridge Port: ${BRIDGE_PORT}`);

    // Prioritas: HID → Serial → Wedge
    if (SERIAL_PORT) {
        await startSerial();
        return;
    }

    const device = await autoDetectDevice();

    if (String(BRIDGE_WEDGE) === '1') {
        startWedge();
        return;
    }

    if (device) {
        const ok = await startWithDevice(device);
        if (ok) return;
        log('WARN', 'HID gagal start, evaluasi fallback…');
    } else {
        log('WARN', 'Tidak ada device HID yang siap.');
    }

    if (String(BRIDGE_WEDGE) !== '0') {
        log('INFO', 'Mengaktifkan Wedge (sinkron DLock)…');
        startWedge();
        return;
    }

    console.log('\n' + '='.repeat(60));
    log('ERROR', '❌ Tidak ada mode yang bisa dijalankan (HID/Serial/Wedge)');
    console.log('='.repeat(60));
    console.log('\n📋 TROUBLESHOOTING:\n');
    console.log('  1) Jika DLock berjalan, set BRIDGE_WEDGE=1 agar mirroring aktif');
    console.log('  2) Atau tutup DLock lalu gunakan HID langsung');
    console.log('  3) Atau gunakan SERIAL_PORT (COMx) jika reader via UART/USB-serial');
    console.log('');
    process.exit(1);
})().catch(err => {
    log('ERROR', 'Fatal: ' + (err?.message || err));
    process.exit(1);
});
