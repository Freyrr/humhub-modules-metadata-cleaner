#!/usr/bin/env python3
# Writes meta-objstm.pdf: a PDF 1.5 whose Info dictionary is inside a compressed
# object stream, with a cross-reference stream and a compressed XMP stream.
import zlib

xmp = (b"<?xpacket begin='' id='W5M0MpCehiHzreSzNTczkc9d'?><x:xmpmeta xmlns:x='adobe:ns:meta/'>"
       b"<rdf:RDF xmlns:rdf='http://www.w3.org/1999/02/22-rdf-syntax-ns#'><rdf:Description rdf:about='' "
       b"xmlns:dc='http://purl.org/dc/elements/1.1/'><dc:creator><rdf:Seq><rdf:li>Mario Rossi</rdf:li></rdf:Seq>"
       b"</dc:creator></rdf:Description></rdf:RDF></x:xmpmeta>" + b" " * 400 + b"<?xpacket end='w'?>")
content = b"BT /F1 24 Tf 72 720 Td (Hello world) Tj ET"

# Objects 1-4 and 6 are inside the object stream 7; 5 (content) and 8 (XMP) are plain streams.
inner = {
    1: b"<< /Type /Catalog /Pages 2 0 R /Metadata 8 0 R >>",
    2: b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
    3: b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 5 0 R /Resources << /Font << /F1 4 0 R >> >> >>",
    4: b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
    6: b"<< /Author (Mario Rossi) /Title <FEFF0053006500630072006500740020007400690074006C0065> /Creator (Secret Tool) /CreationDate (D:20261104080000Z) >>",
}
header = b""
body = b""
for num, obj in inner.items():
    header += b"%d %d " % (num, len(body))
    body += obj + b"\n"
objstm = zlib.compress(header + body, 6)

out = bytearray(b"%PDF-1.5\n%\xe2\xe3\xcf\xd3\n")
offsets = {}
def add(num, data):
    offsets[num] = len(out)
    out.extend(b"%d 0 obj\n" % num + data + b"\nendobj\n")
add(5, b"<< /Length %d >>\nstream\n" % len(content) + content + b"\nendstream")
add(7, b"<< /Type /ObjStm /N %d /First %d /Length %d /Filter /FlateDecode >>\nstream\n" % (len(inner), len(header), len(objstm)) + objstm + b"\nendstream")
xmpz = zlib.compress(xmp, 9)
add(8, b"<< /Type /Metadata /Subtype /XML /Length %d /Filter /FlateDecode >>\nstream\n" % len(xmpz) + xmpz + b"\nendstream")

# Cross-reference stream (object 9): type, offset/objstm, generation/index; widths 1 4 2.
xref_offset = len(out)
rows = [(0, 0, 65535)]
index = {n: i for i, n in enumerate(inner)}
for num in range(1, 10):
    if num in inner:
        rows.append((2, 7, index[num]))
    elif num == 9:
        rows.append((1, xref_offset, 0))
    else:
        rows.append((1, offsets[num], 0))
data = b"".join(bytes([t]) + a.to_bytes(4, "big") + b.to_bytes(2, "big") for t, a, b in rows)
dz = zlib.compress(data)
out.extend(b"9 0 obj\n<< /Type /XRef /Size 10 /W [1 4 2] /Root 1 0 R /Info 6 0 R /Length %d /Filter /FlateDecode >>\nstream\n" % len(dz) + dz + b"\nendstream\nendobj\n")
out.extend(b"startxref\n%d\n%%%%EOF\n" % xref_offset)
open("meta-objstm.pdf", "wb").write(out)
