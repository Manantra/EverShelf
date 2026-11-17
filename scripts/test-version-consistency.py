#!/usr/bin/env python3
"""Reject visible version drift and stale service-worker caches."""
import json,re
from pathlib import Path
root=Path(__file__).resolve().parent.parent
read=lambda f:(root/f).read_text(encoding='utf-8')
version=json.loads(read('manifest.json'))['version']
assert re.fullmatch(r'\d+\.\d+\.\d+',version)
html=read('index.html');sw=read('sw.js');js=read('assets/js/app.js')
readme=read('README.md');log=read('CHANGELOG.md')
for label,regex,source in [
 ('header',r'class="header-version">v([^<]+)<',html),
 ('preloader',r'id="preloader-version">v([^<]+)<',html),
 ('README',r'badge/version-(\d+\.\d+\.\d+)',readme),
]:
 match=re.search(regex,source)
 assert match and match.group(1)==version,(label,version,match.group(1) if match else None)
cache=re.search(r"const CACHE = 'evershelf-v([^']+)';",sw)
assert cache
name=cache.group(1)
assert re.fullmatch(r'\d+\.\d+\.\d+(?:[a-z]|-stability-\d{8}[a-z])',name),name
stamps=re.findall(r'\?v=(\d{8}[a-z])',html)
assert len(stamps)>=12 and len(set(stamps))==1,stamps
stamp=stamps[0]
i18n=re.search(r"_I18N_VERSION = '(\d{8}[a-z])'",js)
assert i18n and i18n.group(1)==stamp,(i18n,stamp)
assert name==(version+'-stability-'+stamp if '-stability-' in name
              else version+stamp[-1]),(name,version,stamp)
assert '## ['+version+']' in log
assert '**v'+version+'**' in readme
print('PASS v'+version+' cache='+name+' stamped-assets='+str(len(stamps)))
