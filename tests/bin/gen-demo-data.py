#!/usr/bin/env python3
"""Generate realistic demo rows for metadata_cleaner_log / _stats (dev only)."""
import random, hashlib, json, datetime, sys

random.seed(42)

FORMATS = [
    ("jpeg",  "image/jpeg", 18, (400_000, 8_000_000)),
    ("png",   "image/png", 15, (200_000, 6_000_000)),
    ("mp4",   "video/mp4", 12, (5_000_000, 200_000_000)),
    ("pdf",   "application/pdf", 10, (80_000, 25_000_000)),
    ("heic",  "image/heic", 8, (800_000, 12_000_000)),
    ("webp",  "image/webp", 7, (150_000, 5_000_000)),
    ("mp3",   "audio/mpeg", 6, (1_000_000, 30_000_000)),
    ("docx",  "application/vnd.openxmlformats-officedocument.wordprocessingml.document", 6, (50_000, 20_000_000)),
    ("gif",   "image/gif", 5, (100_000, 15_000_000)),
    ("webm",  "video/webm", 4, (4_000_000, 120_000_000)),
    ("m4a",   "audio/x-m4a", 3, (800_000, 25_000_000)),
    ("xlsx",  "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet", 3, (60_000, 15_000_000)),
    ("tiff",  "image/tiff", 2, (1_000_000, 40_000_000)),
    ("pptx",  "application/vnd.openxmlformats-officedocument.presentationml.presentation", 2, (100_000, 30_000_000)),
    ("odt",   "application/vnd.oasis.opendocument.text", 2, (50_000, 15_000_000)),
    ("flac",  "audio/flac", 1.5, (3_000_000, 60_000_000)),
    ("wav",   "audio/wav", 1.5, (1_000_000, 50_000_000)),
    ("aac",   "audio/aac", 1.5, (500_000, 20_000_000)),
    ("ogg",   "audio/ogg", 1, (400_000, 15_000_000)),
]
NAMES = {
 "jpeg": ["IMG_{n}.jpg","DSC_{n}.jpg","photo_{n}.jpg","Screenshot {n}.jpg","vacation_{n}.jpg","IMG_{n}-edit.jpg"],
 "png":  ["screenshot_{n}.png","logo_{n}.png","export_{n}.png","render_{n}.png","banner_{n}.png"],
 "mp4":  ["video_{n}.mp4","IMG_{n}.mp4","clip_{n}.mp4","meeting_{n}.mp4","travel_{n}.mp4"],
 "pdf":  ["document_{n}.pdf","report_{n}.pdf","invoice_{n}.pdf","manual_{n}.pdf","contract_{n}.pdf","cv_{n}.pdf"],
 "heic": ["IMG_{n}.heic","photo_{n}.heic","DSC_{n}.heic"],
 "webp": ["image_{n}.webp","avatar_{n}.webp","thumb_{n}.webp"],
 "mp3":  ["track_{n}.mp3","podcast_{n}.mp3","voice_{n}.mp3","song_{n}.mp3"],
 "docx": ["letter_{n}.docx","notes_{n}.docx","project_{n}.docx","memo_{n}.docx","draft_{n}.docx"],
 "gif":  ["anim_{n}.gif","reaction_{n}.gif","loop_{n}.gif"],
 "webm": ["clip_{n}.webm","recording_{n}.webm","stream_{n}.webm"],
 "m4a":  ["voice_{n}.m4a","memo_{n}.m4a","recording_{n}.m4a"],
 "xlsx": ["data_{n}.xlsx","budget_{n}.xlsx","sheet_{n}.xlsx","sales_{n}.xlsx"],
 "tiff": ["scan_{n}.tiff","doc_scan_{n}.tiff","photo_{n}.tif"],
 "pptx": ["slides_{n}.pptx","presentation_{n}.pptx","pitch_{n}.pptx"],
 "odt":  ["document_{n}.odt","text_{n}.odt","notes_{n}.odt"],
 "flac": ["music_{n}.flac","recording_{n}.flac"],
 "wav":  ["recording_{n}.wav","voice_{n}.wav","audio_{n}.wav"],
 "aac":  ["audio_{n}.aac","voice_{n}.aac"],
 "ogg":  ["voice_{n}.ogg","podcast_{n}.ogg"],
}
EXT = {"jpeg":"jpg","png":"png","mp4":"mp4","pdf":"pdf","heic":"heic","webp":"webp","mp3":"mp3","docx":"docx","gif":"gif","webm":"webm","m4a":"m4a","xlsx":"xlsx","tiff":"tif","pptx":"pptx","odt":"odt","flac":"flac","wav":"wav","aac":"aac","ogg":"ogg"}
# Realistic bytes removable per format class: EXIF/XMP/ID3 tags are a few KB,
# embedded thumbnails and cover art reach a few hundred KB, docProps similar.
SAVED_RANGE = {
    "jpeg": (2_000, 60_000), "png": (2_000, 60_000), "webp": (2_000, 40_000),
    "gif": (2_000, 40_000), "heic": (10_000, 300_000), "tiff": (5_000, 120_000),
    "mp4": (5_000, 400_000), "webm": (5_000, 200_000),
    "mp3": (10_000, 800_000), "m4a": (10_000, 400_000), "flac": (10_000, 800_000),
    "wav": (5_000, 100_000), "aac": (5_000, 60_000), "ogg": (5_000, 200_000),
    "pdf": (10_000, 500_000), "docx": (10_000, 400_000), "xlsx": (10_000, 300_000),
    "pptx": (10_000, 500_000), "odt": (10_000, 300_000),
}
META_FIELDS = [
    "exif:Make","exif:Model","exif:DateTimeOriginal","exif:Software",
    "gps:GPSLatitude","gps:GPSLongitude","gps:GPSAltitude",
    "xmp:CreatorTool","xmp:CreateDate","xmp:Rights",
    "id3:Artist","id3:Album","id3:Title",
    "pdf:Producer","pdf:Creator","docx:LastModifiedBy",
    "quicktime:Make","quicktime:Model","tiff:Make","tiff:Model",
]
META_VALUES = {
    "exif:Make": ["Apple","Samsung","Canon","NIKON","Google"],
    "exif:Model": ["iPhone 15 Pro","SM-S918B","EOS R5","D850","Pixel 9 Pro"],
    "exif:DateTimeOriginal": ["2026:{m}:1{d} 12:{h}:0{s}","2026:{m}:2{d} 18:{h}:3{s}"],
    "exif:Software": ["GIMP 2.10","Adobe Photoshop 26.0","darktable 5.0"],
    "gps:GPSLatitude": ["45.{a} N","44.{b} N"],
    "gps:GPSLongitude": ["9.{a} E","12.{b} E"],
    "gps:GPSAltitude": ["152.{c} m","48.{c} m"],
    "xmp:CreatorTool": ["Adobe Photoshop 26.0","Luminar Neo","Capture One"],
    "xmp:CreateDate": ["2026-{m:02d}-{d:02d}T{h:02d}:{mi:02d}:00+01:00"],
    "xmp:Rights": ["Copyright 2026","All rights reserved","CC BY-SA 4.0"],
    "id3:Artist": ["Artist Name","DJ Sample","Band XYZ"],
    "id3:Album": ["Album Title","Greatest Hits","Demo 2026"],
    "id3:Title": ["Song Title","Track One","Intro"],
    "pdf:Producer": ["Microsoft Word","LibreOffice","Adobe Acrobat"],
    "pdf:Creator": ["Mario Rossi","Luigi Bianchi","user@example.com"],
    "docx:LastModifiedBy": ["mario.rossi","laura.b","admin"],
    "quicktime:Make": ["samsung","Apple","Xiaomi"],
    "quicktime:Model": ["SM-S918B","iPhone 15","23127PN0CG"],
    "tiff:Make": ["Canon","NIKON","Hasselblad"],
    "tiff:Model": ["EOS R5","D850","X2D"],
}
ERRORS = ["unexpected end of file","corrupt box header","invalid atom size","truncated moov atom","encrypted pdf","bad zip structure","broken ogg page","malformed id3v2","CRC mismatch in chunk","unsupported brand"]
SOURCES = [("upload",72),("stored",20),("console",8)]
USERS = [1,2,3]
FILES = [1,2]
MONTHS = [(2026,6),(2026,7),(2026,8),(2026,9),(2026,10),(2026,11)]

def fmt_val(t, y, m, d, h, s, mi, a, b, c):
    v = random.choice(META_VALUES[t])
    try:
        return v.format(m=m,d=d,h=h,s=s,mi=mi,a=a,b=b,c=c,y=y)
    except Exception:
        return v

def rand_dt(y, m):
    day = random.randint(1, 6 if m == 11 else 28)
    return datetime.datetime(y, m, day, random.randint(0,23), random.randint(0,59), random.randint(0,59))

def pick_weighted(items):
    r = random.uniform(0, sum(x[2] for x in items)); acc = 0
    for it in items:
        acc += it[2]
        if r <= acc: return it
    return items[-1]

def pick_status():
    r = random.random()
    return "cleaned" if r < 0.60 else ("clean" if r < 0.90 else ("passed" if r < 0.96 else "rejected"))

def pick_source():
    r = random.randint(1,100)
    for s,w in SOURCES:
        if r <= w: return s
        r -= w
    return "upload"

def esc(s):
    if s is None: return "NULL"
    return "'" + s.replace("\\","\\\\").replace("'","\\'") + "'"

events = []
for (y, m) in MONTHS:
    for _ in range(random.randint(155, 175)):
        code, mime, w, (lo, hi) = pick_weighted(FORMATS)
        status = pick_status()
        size = random.randint(lo, hi)
        if status == "cleaned":
            lo_s, hi_s = SAVED_RANGE.get(code, (5_000, 200_000))
            saved = min(random.randint(lo_s, hi_s), size // 10)
            size_after = size - saved
        elif status == "clean":
            saved = 0; size_after = size
        elif status == "passed":
            saved = 0; size_after = size
        else:
            saved = 0; size_after = None
        name = random.choice(NAMES[code]).replace("{n}", str(random.randint(1000,99999)))
        name = name.rsplit(".",1)[0] + "." + EXT[code]
        meta = {}
        if status == "cleaned":
            for t in random.sample(META_FIELDS, random.randint(2,6)):
                meta[t] = fmt_val(t, y, m, random.randint(1,28), random.randint(0,23), random.randint(0,59),
                                  random.randint(0,59), random.randint(100000,999999), random.randint(100000,999999), random.randint(0,999))
        events.append(dict(dt=rand_dt(y,m), fmt=code, mime=mime, status=status, size=size, after=size_after,
                           saved=saved, source=pick_source(), user=random.choice(USERS), file=random.choice(FILES),
                           name=name, meta=meta, err=random.choice(ERRORS) if status in ("passed","rejected") else None))

random.shuffle(events)
log_rows = []; stats = {}
for e in events:
    ym = e["dt"].strftime("%Y-%m")
    key = (ym, e["fmt"], e["status"])
    stats.setdefault(key, [0,0]); stats[key][0] += 1; stats[key][1] += e["saved"]
    if e["status"] == "clean":
        continue
    guid = hashlib.sha1((e["name"] + str(e["dt"].timestamp())).encode()).hexdigest()
    chash = hashlib.sha1((guid + e["mime"]).encode()).hexdigest()
    meta = json.dumps(e["meta"], ensure_ascii=False, separators=(",",":")) if e["meta"] else None
    log_rows.append("(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)" % (
        esc(str(e["file"])), esc(guid), esc(e["name"]), esc(e["mime"]),
        e["size"], "NULL" if e["after"] is None else e["after"], esc(e["status"]), esc(e["err"]),
        esc(e["fmt"]), esc(e["source"]), esc(meta), e["user"],
        "'%s'" % e["dt"].strftime("%Y-%m-%d %H:%M:%S"), esc(chash)))

out = ["SET FOREIGN_KEY_CHECKS=0;", "TRUNCATE TABLE metadata_cleaner_log;", "TRUNCATE TABLE metadata_cleaner_stats;"]
B = 400
for i in range(0, len(log_rows), B):
    out.append("INSERT INTO metadata_cleaner_log (file_id,file_guid,file_name,mime_type,size_before,size_after,status,error,format,source,metadata,created_by,created_at,content_hash) VALUES\n" + ",\n".join(log_rows[i:i+B]) + ";")
srows = []
for (ym,fmt,st),(tot,saved) in sorted(stats.items()):
    srows.append("(%s,%s,%s,%d,%d)" % (esc(ym), esc(fmt), esc(st), tot, saved))
for i in range(0, len(srows), B):
    out.append("INSERT INTO metadata_cleaner_stats (`year_month`,`format`,`status`,`total`,`bytes_saved`) VALUES\n" + ",\n".join(srows[i:i+B]) + ";")
out.append("SET FOREIGN_KEY_CHECKS=1;")
open(sys.argv[1] if len(sys.argv)>1 else "demo.sql","w",encoding="utf-8").write("\n".join(out))
print("events:", len(events), "| log rows:", len(log_rows), "| stat buckets:", len(stats), "| total processed:", sum(v[0] for v in stats.values()))
