#!/usr/bin/env python3
"""Build the offline battery ship-photo catalog from Wikipedia and Wikimedia Commons.
Each bundled thumbnail retains its file source, artist, and license attribution.
Requires ImageMagick (convert) and network access.
Run from the repository root: python scripts/build-battery-ships.py
"""
import base64, concurrent.futures, html, json, pathlib, re, subprocess, threading, time, urllib.error, urllib.parse, urllib.request
US = [('Utah',31),('Wyoming',32),('Arkansas',33),('New York',34),('Texas',35),('Nevada',36),('Oklahoma',37),('Pennsylvania',38),('Arizona',39),('New Mexico',40),('Mississippi',41),('Idaho',42),('Tennessee',43),('California',44),('Colorado',45),('Maryland',46),('West Virginia',48),('North Carolina',55),('Washington',56),('South Dakota',57),('Indiana',58),('Massachusetts',59),('Alabama',60),('Iowa',61),('New Jersey',62),('Missouri',63),('Wisconsin',64)]
CATALOG = [(name, f'USS {name} (BB-{number})', [f'USS {name}', f'{name} BB {number}']) for name, number in US] + [
 ('Bismarck','German battleship Bismarck',['KMS Bismarck']),('Tirpitz','German battleship Tirpitz',['KMS Tirpitz']),('Scharnhorst','German battleship Scharnhorst',['KMS Scharnhorst']),('Gneisenau','German battleship Gneisenau',['KMS Gneisenau']),
 ('Yamato','Japanese battleship Yamato',['IJN Yamato']),('Musashi','Japanese battleship Musashi',['IJN Musashi']),('Nagato','Japanese battleship Nagato',['IJN Nagato']),('Mutsu','Japanese battleship Mutsu',['IJN Mutsu']),('Fuso','Japanese battleship Fusō',['Fusō','IJN Fuso']),('Yamashiro','Japanese battleship Yamashiro',['IJN Yamashiro']),('Kongo','Japanese battleship Kongō',['Kongō','IJN Kongo']),('Haruna','Japanese battleship Haruna',['IJN Haruna']),('Kirishima','Japanese battleship Kirishima',['IJN Kirishima']),('Hiei','Japanese battleship Hiei',['IJN Hiei']),('Ise','Japanese battleship Ise',['IJN Ise']),('Hyuga','Japanese battleship Hyūga',['Hyūga','IJN Hyuga']),
 ('Hood','HMS Hood',['HMS Hood']),('Warspite','HMS Warspite (03)',['HMS Warspite']),('Valiant','HMS Valiant (1914)',['HMS Valiant']),('Queen Elizabeth','HMS Queen Elizabeth (1913)',['HMS Queen Elizabeth']),('Barham','HMS Barham (04)',['HMS Barham']),('Malaya','HMS Malaya',['HMS Malaya']),('Nelson','HMS Nelson (28)',['HMS Nelson']),('Rodney','HMS Rodney (29)',['HMS Rodney']),('King George V','HMS King George V (41)',['HMS King George V','King George 5']),('Prince of Wales','HMS Prince of Wales (53)',['HMS Prince of Wales']),('Duke of York','HMS Duke of York (17)',['HMS Duke of York']),('Anson','HMS Anson (79)',['HMS Anson']),('Howe','HMS Howe (32)',['HMS Howe']),('Royal Oak','HMS Royal Oak (08)',['HMS Royal Oak']),('Revenge','HMS Revenge (06)',['HMS Revenge']),('Ramillies','HMS Ramillies (07)',['HMS Ramillies']),('Resolution','HMS Resolution (09)',['HMS Resolution']),('Royal Sovereign','HMS Royal Sovereign (05)',['HMS Royal Sovereign']),
 ('Richelieu','French battleship Richelieu',['FS Richelieu']),('Jean Bart','French battleship Jean Bart (1940)',['FS Jean Bart']),('Dunkerque','French battleship Dunkerque',['FS Dunkerque']),('Strasbourg','French battleship Strasbourg',['FS Strasbourg']),('Littorio','Italian battleship Littorio',['RN Littorio','Italia']),('Vittorio Veneto','Italian battleship Vittorio Veneto',['RN Vittorio Veneto']),('Roma','Italian battleship Roma (1940)',['RN Roma'])]
CACHE = pathlib.Path('/tmp/ibots-ship-source-cache'); CACHE.mkdir(exist_ok=True)
RATE_LOCK=threading.Lock()
LAST_REQUEST=0.0
def get(url, cache_key):
 global LAST_REQUEST
 path=CACHE / cache_key
 if path.exists():return path.read_bytes()
 for attempt in range(3):
  try:
   with RATE_LOCK:
    wait=1.1-(time.monotonic()-LAST_REQUEST)
    if wait>0:time.sleep(wait)
    LAST_REQUEST=time.monotonic()
   with urllib.request.urlopen(urllib.request.Request(url,headers={'User-Agent':'iBotsScoutingAssets/1.0 (FRC Team 2370)'}),timeout=18) as response: data=response.read()
   path.write_bytes(data);return data
  except urllib.error.HTTPError as error:
   if attempt==2 or error.code not in (429,500,502,503,504):raise
   time.sleep(min(60,max(5,int(error.headers.get('Retry-After','10')))))
  except Exception:
   if attempt==2:raise
   time.sleep(2)
def clean(value):return re.sub(r'\s+',' ',html.unescape(re.sub(r'<[^>]*>',' ',str(value)))).strip()
def build(item):
 name,title,aliases=item;key=re.sub('[^a-z0-9]+','-',name.lower()).strip('-')
 try:
  summary=json.loads(get('https://en.wikipedia.org/api/rest_v1/page/summary/'+urllib.parse.quote(title.replace(' ','_'),safe=''),key+'-summary.json'))
  thumb=summary['thumbnail']['source'].split('?')[0]
  filename=urllib.parse.unquote(urllib.parse.urlsplit(thumb).path.split('/')[-2])
  info_url='https://commons.wikimedia.org/w/api.php?'+urllib.parse.urlencode({'action':'query','format':'json','prop':'imageinfo','iiprop':'url|extmetadata','titles':'File:'+filename})
  metadata=json.loads(get(info_url,key+'-metadata.json'));page=next(iter(metadata['query']['pages'].values()));info=page['imageinfo'][0];ext=info['extmetadata']
  license_name=clean(ext.get('LicenseShortName',{}).get('value',''));artist=clean(ext.get('Artist',{}).get('value',''))
  if not (license_name.lower()=='public domain' or license_name.startswith(('CC BY ','CC BY-SA ','CC0'))):raise ValueError('Unsupported license: '+license_name)
  license_url=ext.get('LicenseUrl',{}).get('value','https://commons.wikimedia.org/wiki/Commons:Public_domain' if license_name.lower()=='public domain' else '')
  if license_name.lower()!='public domain' and (not artist or not license_url):raise ValueError('Missing attribution')
  image=get(thumb,key+'-thumbnail.bin')
  mime='image/jpeg' if image[:3]==b'\xff\xd8\xff' else 'image/png' if image[:8]==b'\x89PNG\r\n\x1a\n' else 'image/webp' if image[:4]==b'RIFF' and image[8:12]==b'WEBP' else None
  if not mime or len(image)>150000:raise ValueError('Invalid or oversized thumbnail')
  image=subprocess.check_output(['convert','-','-thumbnail','240x144>','-strip','-quality','78','jpg:-'],input=image);mime='image/jpeg'
  return key,{'name':name,'title':summary['title'],'aliases':list(dict.fromkeys([name]+aliases)),'article':summary['content_urls']['desktop']['page'],'source':info['descriptionurl'],'artist':artist,'license':license_name,'license_url':license_url,'image_mime':mime,'image_base64':base64.b64encode(image).decode(),'width':summary['thumbnail'].get('width'),'height':summary['thumbnail'].get('height')},None
 except Exception as error:return key,None,str(error)
if __name__=='__main__':
 out=pathlib.Path('public/battery-ships.json')
 ships=json.loads(out.read_text()).get('ships',{}) if out.exists() else {};failures={}
 with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
  for count,(key,value,error) in enumerate(pool.map(build,CATALOG),1):
   if count%10==0:print(f'Processed {count}/{len(CATALOG)} ship photos',flush=True)
   if value:ships[key]=value
   else:failures[key]=error
 temporary=out.with_suffix('.json.tmp');temporary.write_text(json.dumps({'version':1,'ships':dict(sorted(ships.items()))},ensure_ascii=False,separators=(',',':'))+'\n');temporary.replace(out)
 print(json.dumps({'ships':len(ships),'bytes':out.stat().st_size,'unavailable':failures},ensure_ascii=False),flush=True)
