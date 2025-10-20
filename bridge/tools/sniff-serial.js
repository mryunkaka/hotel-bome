// C:\laragon\www\hotel-bome\bridge\tools\sniff-serial.js
const { SerialPort } = require('serialport');
const { ReadlineParser } = require('@serialport/parser-readline');

// GANTI ini sesuai hasil Get-CimInstance / mode
const PORT = process.env.SERIAL_PORT || 'COM3';

// Coba baud umum: 9600 atau 115200 (ganti jika tidak keluar apa-apa)
const BAUD = Number(process.env.SERIAL_BAUD || 9600);

const port = new SerialPort({ path: PORT, baudRate: BAUD }, (err) => {
    if (err) return console.error('Open error:', err.message);
    console.log('Serial opened:', PORT, 'baud', BAUD);
});

const parser = port.pipe(new ReadlineParser({ delimiter: /\r?\n/ }));
parser.on('data', line => console.log('[line]', line.trim()));

port.on('data', buf => console.log('[raw]', buf.toString('hex').toUpperCase()));
port.on('error', e => console.error('Serial error:', e.message || e));

console.log('Sniffing serial... tempel kartu sekarang.');
