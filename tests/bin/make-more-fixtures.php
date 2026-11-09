<?php
// Generates the synthetic fixtures for the RIFF/ASF/AIFF/PSD/JP2/JXL/RAW cleaners.
// Run once: php make-more-fixtures.php  (from tests/bin, writes to ../codeception/_data/fixtures)
$dir = __DIR__ . '/../codeception/_data/fixtures';
@mkdir($dir, 0777, true);

function chunk(string $fourcc, string $body): string {
    return $fourcc . pack('V', strlen($body)) . $body . (strlen($body) & 1 ? "\x00" : '');
}
function achunk(string $fourcc, string $body): string {
    return $fourcc . pack('N', strlen($body)) . $body . (strlen($body) & 1 ? "\x00" : '');
}

// ---- WAV: fmt + data + INFO LIST + bext + iXML + ID3 ----
$info = 'LIST' . pack('V', 4 + strlen(chunk('INAM', "Secret title\x00") . chunk('IART', "Mario Rossi\x00") . chunk('ICMT', "home studio\x00")))
    . 'INFO' . chunk('INAM', "Secret title\x00") . chunk('IART', "Mario Rossi\x00") . chunk('ICMT', "home studio\x00");
$fmt = chunk('fmt ', pack('vvVVvv', 1, 1, 8000, 8000, 1, 8));
$data = chunk('data', str_repeat("\x80", 800));
$meta = chunk('bext', "secret desc Mario Rossi" . str_repeat("\x00", 600))
    . chunk('iXML', '<?xml version="1.0"?><BWFXML><USER>Mario Rossi</USER></BWFXML>')
    . chunk('ID3 ', 'ID3 secret tag');
$wav = 'RIFF' . pack('V', 4 + strlen($fmt . $info . $meta . $data)) . 'WAVE' . $fmt . $info . $meta . $data;
file_put_contents("$dir/meta.wav", $wav);
file_put_contents("$dir/plain.wav", 'RIFF' . pack('V', 4 + strlen($fmt . $data)) . 'WAVE' . $fmt . $data);
file_put_contents("$dir/broken.wav", substr($wav, 0, 40));

// ---- AVI: hdrl/movi minimal + INFO LIST ----
$avih = chunk('avih', str_repeat("\x00", 56));
$movi = 'LIST' . pack('V', 4 + 8 + 100) . 'movi' . chunk('00db', str_repeat("\x00", 100));
$hdrl = 'LIST' . pack('V', 4 + strlen($avih)) . 'hdrl' . $avih;
$avi = 'RIFF' . pack('V', 4 + strlen($hdrl . $movi . $info)) . 'AVI ' . $hdrl . $movi . $info;
file_put_contents("$dir/meta.avi", $avi);
file_put_contents("$dir/broken.avi", substr($avi, 0, 40));

// ---- ASF/WMV: header with content desc, ext desc, script, padding, data ----
function asfObj(string $guid, string $payload): string {
    return $guid . pack('P', 24 + strlen($payload)) . $payload;
}
$H = "\x30\x26\xB2\x75\x8E\x66\xCF\x11\xA6\xD9\x00\xAA\x00\x62\xCE\x6C";
$gFileProps = "\xA1\xDC\xAB\x8C\x47\xA9\xCF\x11\x8E\xE4\x00\xC0\x0C\x20\x53\x65";
$gContentDesc = "\x33\x26\xB2\x75\x8E\x66\xCF\x11\xA6\xD9\x00\xAA\x00\x62\xCE\x6C";
$gExtDesc = "\x40\xA4\xD0\xD2\x07\xE3\xD2\x11\x97\xF0\x00\xA0\xC9\x5E\xA8\x50";
$gScript = "\x30\x1A\xFB\x1E\x62\x0B\xD0\x11\xA3\x9B\x00\xA0\xC9\x03\x48\xF6";
$gMarker = "\x01\xCD\x87\xF4\x51\xA9\xCF\x11\x8E\xE6\x00\xC0\x0C\x20\x53\x65";
$gPadding = "\x74\xD4\x06\x18\xDF\xCA\x09\x45\xA4\xBA\x9A\xAB\xCB\x96\xAA\xE8";
$gData = "\x36\x26\xB2\x75\x8E\x66\xCF\x11\xA6\xD9\x00\xAA\x00\x62\xCE\x6C";
$title = iconv('UTF-8', 'UTF-16LE', 'Secret WMV');
$author = iconv('UTF-8', 'UTF-16LE', 'Mario Rossi');
$cd = pack('vvvvv', strlen($title) + 2, strlen($author) + 2, 2, 2, 2) . $title . "\x00\x00" . $author . "\x00\x00" . "\x00\x00\x00\x00\x00\x00";
$ed = pack('v', 1) . pack('v', 8) . iconv('UTF-8', 'UTF-16LE', 'Secret') . pack('vvV', 0, 8, 0) . iconv('UTF-8', 'UTF-16LE', 'Mario');
$objs = asfObj($gFileProps, str_repeat("\x00", 80))
    . asfObj($gContentDesc, $cd)
    . asfObj($gExtDesc, $ed)
    . asfObj($gScript, pack('vv', 0, 0))
    . asfObj($gMarker, pack('Vv', 0, 0))
    . asfObj($gPadding, "old metadata junk here");
$header = $H . pack('P', 30 + strlen($objs)) . pack('V', 6) . "\x01\x02" . $objs;
$wmv = $header . $gData . pack('P', 24 + 200) . "\x00" . str_repeat("\xAA", 200);
file_put_contents("$dir/meta.wmv", $wmv);
file_put_contents("$dir/plain.wmv", $H . pack('P', 30 + strlen(asfObj($gFileProps, str_repeat("\x00", 80)))) . pack('V', 1) . "\x01\x02" . asfObj($gFileProps, str_repeat("\x00", 80)) . $gData . pack('P', 224) . "\x00" . str_repeat("\xAA", 200));
file_put_contents("$dir/broken.wmv", substr($wmv, 0, 40));

// ---- AIFF ----
$comm = achunk('COMM', pack('nViih', 1, 100, 8, 0x4000, 0));
$comm = achunk('COMM', pack('nV', 1, 100) . pack('n', 8) . "\x40\x0E\xAC\x44\x00\x00\x00\x00\x00\x00");
$ssnd = achunk('SSND', pack('VV', 0, 0) . str_repeat("\x00", 100));
$metaA = achunk('NAME', 'Secret song') . achunk('AUTH', 'Mario Rossi') . achunk('(c) ', 'secret corp')
    . achunk('ANNO', 'secret comment') . achunk('ID3 ', 'ID3 secret') . achunk('COMT', 'secret');
$aiff = 'FORM' . pack('N', 4 + strlen($metaA . $comm . $ssnd)) . 'AIFF' . $metaA . $comm . $ssnd;
file_put_contents("$dir/meta.aiff", $aiff);
file_put_contents("$dir/plain.aiff", 'FORM' . pack('N', 4 + strlen($comm . $ssnd)) . 'AIFF' . $comm . $ssnd);
file_put_contents("$dir/broken.aiff", substr($aiff, 0, 30));

// ---- PSD ----
function resBlock(int $id, string $body): string {
    $name = "\x00\x00"; // empty pascal name padded to even
    return '8BIM' . pack('n', $id) . $name . pack('N', strlen($body)) . $body . (strlen($body) & 1 ? "\x00" : '');
}
$res = resBlock(0x03ED, pack('VvVv', 300, 1, 300, 1))
    . resBlock(0x0404, 'IPTC Mario Rossi secret')
    . resBlock(0x0422, "Exif\x00\x00" . 'GPS secret')
    . resBlock(0x0424, '<x:xmpmeta>secret xmp Mario</x:xmpmeta>')
    . resBlock(0x040C, 'thumb secret bytes')
    . resBlock(0x040F, 'fake icc profile');
$layer = pack('N', 40) . str_repeat("\x11", 40);
$psd = '8BPS' . pack('n', 1) . str_repeat("\x00", 6) . pack('nVVnn', 3, 100, 80, 8, 3)
    . pack('N', 0) . pack('N', strlen($res)) . $res . $layer . pack('n', 0) . str_repeat("\x77", 200);
file_put_contents("$dir/meta.psd", $psd);
file_put_contents("$dir/plain.psd", '8BPS' . pack('n', 1) . str_repeat("\x00", 6) . pack('nVVnn', 3, 100, 80, 8, 3)
    . pack('N', 0) . pack('N', strlen(resBlock(0x03ED, pack('VvVv', 300, 1, 300, 1)))) . resBlock(0x03ED, pack('VvVv', 300, 1, 300, 1))
    . $layer . pack('n', 0) . str_repeat("\x77", 200));
file_put_contents("$dir/broken.psd", substr($psd, 0, 40));

// ---- JP2 ----
function box(string $type, string $body): string {
    return pack('N', 8 + strlen($body)) . $type . $body;
}
$jp2h = box('jp2h', box('ihdr', pack('VVvCv', 80, 100, 3, 7, 0)) . box('colr', "\x01\x00\x00" . 'fakeicc'));
$jp2 = "\x00\x00\x00\x0CjP  \r\n\x87\n" . box('ftyp', 'jp2 ' . pack('N', 0) . 'jp2 ')
    . box('uuid', "\xBE\x7A\xCF\xCB\x97\xA9\x42\xE8\x9C\x71\x99\x94\x91\xE3\xAF\xAC" . '<x:xmpmeta>secret</x:xmpmeta>')
    . box('xml ', '<xmp>Mario Rossi secret</xmp>')
    . $jp2h . box('jp2c', str_repeat("\x33", 300));
file_put_contents("$dir/meta.jp2", $jp2);
file_put_contents("$dir/plain.jp2", "\x00\x00\x00\x0CjP  \r\n\x87\n" . box('ftyp', 'jp2 ' . pack('N', 0) . 'jp2 ') . $jp2h . box('jp2c', str_repeat("\x33", 300)));
file_put_contents("$dir/broken.jp2", substr($jp2, 0, 40));

// ---- JXL ----
$jxl = "\x00\x00\x00\x0CJXL \r\n\x87\n" . box('ftyp', 'jxl ' . pack('N', 0) . 'jxl ')
    . box('Exif', 'II' . 'GPS Mario Rossi secret')
    . box('xml ', '<xmp>secret</xmp>')
    . box('jumb', 'c2pa secret manifest')
    . box('jxlc', str_repeat("\x44", 300));
file_put_contents("$dir/meta.jxl", $jxl);
file_put_contents("$dir/plain.jxl", "\x00\x00\x00\x0CJXL \r\n\x87\n" . box('ftyp', 'jxl ' . pack('N', 0) . 'jxl ') . box('jxlc', str_repeat("\x44", 300)));
file_put_contents("$dir/broken.jxl", substr($jxl, 0, 40));

// ---- RAW (NEF-like TIFF: Make + SubIFDs + EXIF/GPS + MakerNote) ----
// Minimal II TIFF: header, IFD0 with Make, Model, SubIFDs, ExifIFD, GpsIFD, MakerNote, then sub/exif/gps IFDs.
function tiff(array $entries, int $next = 0): string {
    $s = pack('v', count($entries));
    foreach ($entries as $e) { $s .= $e; }
    return $s . pack('V', $next);
}
function te(int $tag, int $type, int $count, int $val): string {
    return pack('vvV', $tag, $type, $count) . pack('V', $val);
}
$out = "II*\x00" . pack('V', 8);
$pos = 8;
// reserve layout: IFD0 (7 entries), then Make str, Model str, subIFD, exifIFD, gpsIFD, makernote data
$ifd0Count = 7;
$ifd0Size = 2 + 12 * $ifd0Count + 4;
$pMake = $pos + $ifd0Size;
$pModel = $pMake + 6;
$pSub = $pModel + 6;
$pExif = $pSub + 2 + 12 * 2 + 4;
$pGps = $pExif + 2 + 12 * 2 + 4;
$pMaker = $pGps + 2 + 12 * 1 + 4;
$ifd0 = tiff([
    te(0x010F, 2, 6, $pMake),
    te(0x0110, 2, 6, $pModel),
    te(0x0111, 4, 1, $pMaker + 12),   // strip offset (raw data)
    te(0x0117, 4, 1, 20),             // byte counts
    te(0x014A, 4, 1, $pSub),          // SubIFDs
    te(0x8769, 4, 1, $pExif),         // ExifIFD
    te(0x8825, 4, 1, $pGps),          // GpsIFD
]);
$raw = $out . $ifd0
    . "NIKON\x00" . "Z9\x00\x00\x00\x00"
    . tiff([te(0x0111, 4, 1, $pMaker + 12), te(0x927C, 7, 8, $pMaker)]) // subIFD + MakerNote
    . tiff([te(0x9003, 2, 4, 0x36323032), te(0x829A, 5, 1, $pMaker + 4)]) // exif ifd ("2026" inline)
    . tiff([te(0x0002, 5, 1, $pMaker + 4)]) // gps ifd
    . "Nikon mknote" . str_repeat("\x00", 4)
    . str_repeat("\x55", 20); // raw strip data
file_put_contents("$dir/meta.nef", $raw);
file_put_contents("$dir/plain.nef", "II*\x00" . pack('V', 8) . tiff([te(0x0111, 4, 1, 40), te(0x0117, 4, 1, 8)]) . str_repeat("\x55", 8));
file_put_contents("$dir/broken.nef", substr($raw, 0, 20));

echo "done\n";
