// Requires @electric-sql/pglite; optional browser checks use Playwright.
// Run: PHP_BIN=/path/to/php CHROMIUM_BIN=/path/to/chromium node tests/field-background-test.cjs
const fs=require('fs'),path=require('path'),assert=require('assert'),{execFileSync}=require('child_process');
const {PGlite}=require('@electric-sql/pglite');
const source=fs.readFileSync('public/index.php','utf8');
const helpers=source.slice(source.indexOf('function fieldImageForEvent('),source.indexOf('function page('));
const php=process.env.PHP_BIN||'php';
const quote=s=>"'"+s.replace(/\\/g,'\\\\').replace(/'/g,"\\'")+"'";
function phpRun(body){return JSON.parse(execFileSync(php,['-n','-r',`class PDO {const FETCH_ASSOC=2;}
 function query($sql,$args){$GLOBALS['captured']=[$sql,$args];return new class{function fetch($mode){return $GLOBALS['row']??false;}};}
 ${helpers}\n${body}`],{encoding:'utf8'}));}
function sqlFor(event,data=true){return phpRun(`fieldImageForEvent(json_decode(${quote(JSON.stringify(event))},true),${data?'true':'false'});echo json_encode($GLOBALS['captured']);`);}
function urlFor(event,row){return phpRun(`$GLOBALS['row']=json_decode(${quote(JSON.stringify(row))},true);$event=json_decode(${quote(JSON.stringify(event))},true);echo json_encode(['url'=>fieldBackgroundUrl($event),'style'=>fieldBackgroundStyle($event)]);`);}
(async()=>{
 const db=new PGlite();let browser;
 try{
  await db.exec('CREATE TABLE events(id uuid PRIMARY KEY,event_key text);CREATE TABLE event_field_images(event_id uuid PRIMARY KEY,mime text,photo_base64 text,uploaded_at timestamptz);');
  const id=n=>`00000000-0000-4000-8000-${String(n).padStart(12,'0')}`;
  const events=[{id:id(1),event_key:'2026mawor'},{id:id(2),event_key:'2026ctsou'},{id:id(3),event_key:'2026nhman'},{id:id(4),event_key:'2027mawor'},{id:id(5),event_key:'2028ctsou'},{id:id(6),event_key:'custom'}];
  for(const event of events)await db.query('INSERT INTO events VALUES($1,$2)',[event.id,event.event_key]);
  const image=(i,date)=>db.query("INSERT INTO event_field_images VALUES($1,'image/png','YWJj',$2)",[id(i),date]);
  await image(1,'2026-03-01T12:00:00Z');await image(4,'2027-03-01T12:00:00Z');
  const resolve=async(event,data=true)=>{const [sql,args]=sqlFor(event,data);let n=0;return(await db.query(sql.replace(/\?/g,()=>`$${++n}`),args)).rows[0]||false;};
  const own=await resolve(events[0]);assert.equal(own.event_id,id(1));
  const fallback=await resolve(events[1]);assert.equal(fallback.event_id,id(1),'New offseason event inherits existing same-season image');
  assert.equal((await resolve(events[3])).event_id,id(4),'Different season retains its own image');
  assert.equal(await resolve(events[4]),false,'Never borrow from another game season');
  assert.equal(await resolve(events[5]),false,'Custom event without a season never borrows an unrelated image');
  await image(3,'2026-10-01T12:00:00Z');
  const latest=await resolve(events[1]);assert.equal(latest.event_id,id(3),'Fallback selects most recent image from same season');
  assert.equal((await resolve(events[0])).event_id,id(1),'An event-specific upload takes priority over fallback');
  const meta=await resolve(events[1],false);assert.equal(meta.photo_base64,undefined,'Page metadata does not load image bytes');
  const initial=urlFor(events[1],fallback),updated=urlFor(events[1],latest),missing=urlFor(events[4],false);
  assert.equal(new URL(initial.url,'http://scouting.test').searchParams.get('event'),id(1));assert.notEqual(initial.url,updated.url);
  await db.query('UPDATE event_field_images SET uploaded_at=$1 WHERE event_id=$2',['2026-10-17T12:00:00Z',id(3)]);
  const replaced=urlFor(events[1],await resolve(events[1]));assert.notEqual(replaced.url,updated.url,'Replacement upload changes image URL');
  assert.equal(missing.style,'--field-background:none');
  const css=fs.readFileSync('public/style.css','utf8');assert(!css.includes("url('/?p=field_image')"),'No canvas requests an image based on the mutable active-event state');
  const {chromium}=require('playwright');browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_BIN||undefined,args:['--no-sandbox','--disable-dev-shm-usage']});
  const page=await browser.newPage();const requests=[];const imageBytes=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a6ZkAAAAASUVORK5CYII=','base64');
  await page.route('http://scouting.test/**',route=>{const u=new URL(route.request().url());if(u.searchParams.get('p')==='field_image'){requests.push(u.href);return route.fulfill({contentType:'image/png',body:imageBytes});}return route.fulfill({contentType:'text/html',body:'<canvas id="strategyCanvas" width="800" height="480"></canvas><div class="path-widget"><canvas id="autoPathCanvas"></canvas><canvas id="teamPathsCanvas"></canvas></div>'});});
  await page.goto('http://scouting.test/?p=strategy');await page.addStyleTag({content:css});
  for(const background of [initial,updated,replaced]){
   const response=page.waitForResponse(r=>r.url()===new URL(background.url,'http://scouting.test').href);
   await page.evaluate(style=>document.documentElement.setAttribute('style',style),background.style);assert.equal((await response).status(),200);
   for(const canvas of ['strategyCanvas','autoPathCanvas','teamPathsCanvas'])assert((await page.locator('#'+canvas).evaluate(c=>getComputedStyle(c).backgroundImage)).includes(new URL(background.url,'http://scouting.test').href));
  }
  await page.evaluate(style=>document.documentElement.setAttribute('style',style),missing.style);assert(!(await page.locator('#strategyCanvas').evaluate(c=>getComputedStyle(c).backgroundImage)).includes('field_image'));
  assert.equal(requests.length,3);console.log('Passed: PostgreSQL same-season fallback, event overrides, season isolation, latest image, versioned replacement URLs, and browser backgrounds on all three canvas types.');
 }finally{await browser?.close();await db.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
