#!/usr/bin/env python3
# Writes a small valid DNG (16-bit RGGB Bayer data) with the tags a raw developer needs.
import struct
W, H = 64, 48
pix = bytearray()
for y in range(H):
    for x in range(W):
        v = ((x * 997 + y * 613) % 3000) + 500
        pix += struct.pack('<H', v)
entries = []
def e(tag, typ, count, value): entries.append((tag, typ, count, value))
SHORT, LONG, RATIONAL, SRATIONAL, ASCII, BYTE = 3, 4, 5, 10, 2, 1
e(0x00FE, LONG, 1, [0])
e(0x0100, LONG, 1, [W]); e(0x0101, LONG, 1, [H]); e(0x0102, SHORT, 1, [16]); e(0x0103, SHORT, 1, [1])
e(0x0106, SHORT, 1, [32803]); e(0x0111, LONG, 1, ['DATA']); e(0x0115, SHORT, 1, [1]); e(0x0116, LONG, 1, [H])
e(0x0117, LONG, 1, [len(pix)]); e(0x011C, SHORT, 1, [1])
e(0x828D, SHORT, 2, [2, 2]); e(0x828E, BYTE, 4, [0, 1, 1, 2])
e(0xC612, BYTE, 4, [1, 4, 0, 0]); e(0xC613, BYTE, 4, [1, 1, 0, 0])
e(0xC614, ASCII, 0, b'TestCam\x00')
e(0xC61D, LONG, 1, [4095])
e(0xC621, SRATIONAL, 9, [(1, 1), (0, 1), (0, 1), (0, 1), (1, 1), (0, 1), (0, 1), (0, 1), (1, 1)])
e(0xC628, RATIONAL, 3, [(1, 2), (1, 1), (1, 2)])
e(0xC65A, SHORT, 1, [21])
entries.sort()
sizes = {BYTE: 1, ASCII: 1, SHORT: 2, LONG: 4, RATIONAL: 8, SRATIONAL: 8}
ifd_off = 8
n = len(entries)
extra_off = ifd_off + 2 + 12 * n + 4
extra = bytearray()
data_off = None
raw = []
for tag, typ, count, value in entries:
    if typ == ASCII:
        payload = value; count = len(value)
    elif typ in (RATIONAL, SRATIONAL):
        payload = b''.join(struct.pack('<ii' if typ == SRATIONAL else '<II', a, b) for a, b in value)
    elif value == ['DATA']:
        payload = None
    else:
        fmt = {BYTE: 'B', SHORT: 'H', LONG: 'I'}[typ]
        payload = struct.pack('<' + fmt * len(value), *value)
    raw.append([tag, typ, count, payload])
for r in raw:
    if r[3] is not None and len(r[3]) > 4:
        r.append(extra_off + len(extra)); extra += r[3]
        if len(extra) % 2: extra += b'\x00'
    else:
        r.append(None)
data_off = extra_off + len(extra)
out = bytearray(b'II*\x00' + struct.pack('<I', ifd_off) + struct.pack('<H', n))
for tag, typ, count, payload, off in raw:
    if payload is None:
        out += struct.pack('<HHII', tag, typ, 1, data_off)
    elif off is not None:
        out += struct.pack('<HHII', tag, typ, count, off)
    else:
        out += struct.pack('<HHI', tag, typ, count) + payload.ljust(4, b'\x00')
out += struct.pack('<I', 0) + extra + pix
open('plain.dng', 'wb').write(out)
