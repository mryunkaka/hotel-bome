const assert = require('node:assert/strict');
const test = require('node:test');

const { UiohookKey } = require('uiohook-napi');
const {
    bestUidFromBurst,
    isExcludedHidDevice,
    isLikelyHidReader,
    mapWedgeKey,
    normalizeUid,
} = require('./bridge-auto');

test('normalizes a reader UID to uppercase hexadecimal', () => {
    assert.equal(normalizeUid('04:a1-b2 c3'), '04A1B2C3');
});

test('extracts an ASCII UID from a HID report burst', () => {
    assert.equal(bestUidFromBurst([Buffer.from('04A1B2C3')]), '04A1B2C3');
});

test('maps global keyboard events to UID characters', () => {
    assert.equal(mapWedgeKey(UiohookKey.A), 'A');
    assert.equal(mapWedgeKey(UiohookKey['0']), '0');
    assert.equal(mapWedgeKey(UiohookKey.Enter), '');
});

test('does not treat a gaming mouse as a card reader', () => {
    const mouse = { product: 'USB GAMING MOUSE', usagePage: 0xff00 };

    assert.equal(isExcludedHidDevice(mouse), true);
    assert.equal(isLikelyHidReader(mouse), false);
});

test('recognizes an explicitly named RFID reader', () => {
    assert.equal(isLikelyHidReader({ product: 'USB RFID Reader', usagePage: 0xff00 }), true);
});
