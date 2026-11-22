import io, re, sys

pat = re.compile(r"^    '((?:[^'\\]|\\.)*)' =>", re.M)
base = sys.argv[1] if len(sys.argv) > 1 else 'messages'
en = set(pat.findall(io.open(base + '/en/base.php', encoding='utf-8').read()))
ok = True
for loc in ['it', 'de', 'es', 'fr', 'pt', 'zh-CN']:
    src = io.open('%s/%s/base.php' % (base, loc), encoding='utf-8').read()
    keys = set(pat.findall(src))
    missing, extra = en - keys, keys - en
    empty = len(re.findall(r"=> ''", src))
    print(loc, 'keys:', len(keys), 'missing:', missing or '-', 'extra:', extra or '-', 'empty:', empty)
    if missing or extra or (loc != 'en' and empty):
        ok = False
print('ALL ALIGNED' if ok else 'MISMATCH')
