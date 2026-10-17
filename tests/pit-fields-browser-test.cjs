// Run with PHP_BIN=/path/to/php node tests/pit-fields-browser-test.cjs.
const fs = require('fs'), os = require('os'), path = require('path'), assert = require('assert');
const {spawn} = require('child_process');
const {chromium} = require('playwright');
(async () => {
 const root=path.resolve(__dirname,'..'), dir=fs.mkdtempSync(path.join(os.tmpdir(),'pit-fields-'));
 const helper=JSON.stringify(path.join(root,'public/pit-fields.php'));
 fs.writeFileSync(path.join(dir,'index.php'),`<?php
 require ${helper};
 function h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
 function tagStyle(string $v):string{return '';}
 $fields=[['id'=>'custom_1111111111111111','label'=>'Mechanisms','type'=>'multiselect','options'=>['Intake','Climb'],'colors'=>['Intake'=>'#ff0000','Climb'=>'#00ff00']],['id'=>'custom_2222222222222222','label'=>'Inspection image','type'=>'image','options'=>[]],['id'=>'robot_meta','label'=>'Robot Meta','type'=>'dropdown','options'=>['Turret','Other']]];
 if($_SERVER['REQUEST_METHOD']==='POST'){
  header('Content-Type: application/json');
  try{$data=readPitValues($fields,$_POST['fields']??[],['removed'=>'preserved']);if(isset($_FILES['image_custom_2222222222222222'])&&$_FILES['image_custom_2222222222222222']['error']!==UPLOAD_ERR_NO_FILE)$data['image']=readPitImage($_FILES['image_custom_2222222222222222']);echo json_encode($data);}catch(InvalidArgumentException $e){http_response_code(400);echo json_encode(['error'=>$e->getMessage()]);}exit;
 }
 echo '<form class="dynamic-pit-form" method="post" enctype="multipart/form-data">';foreach($fields as $f)renderPitField($f,[],2370,null);echo '<button>Save pit record</button></form>';
 `);
 const port=18739, server=spawn(process.env.PHP_BIN||'php',['-n','-S',`127.0.0.1:${port}`,'-t',dir],{stdio:'ignore'});
 let browser;
 try {
  for(let i=0;i<60;i++){try{await fetch(`http://127.0.0.1:${port}`);break;}catch(e){await new Promise(r=>setTimeout(r,50));}}
  browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_BIN||undefined,args:['--no-sandbox','--disable-dev-shm-usage']});const page=await browser.newPage({viewport:{width:390,height:844},hasTouch:true});const errors=[];page.on('pageerror',e=>errors.push(e.message));page.on('dialog',d=>d.accept());
  const schema=[{id:'robot_photo',label:'Robot photo',type:'image',options:[]},{id:'notes',label:'Notes',type:'textarea',options:[]}];
  await page.goto(`http://127.0.0.1:${port}`);
  await page.setContent(`<link rel="stylesheet" href="http://127.0.0.1:${port}/style.css"><form id="pitFieldBuilder"><input type="hidden" id="pitFieldSchema"><div id="pitFieldList"></div><button type="button" id="pitFieldAdd">Add field</button><button>Save</button></form><script type="application/json" id="pitFieldData">${JSON.stringify(schema)}</script>`);
  await page.addStyleTag({path:path.join(root,'public/style.css')});
  await page.addScriptTag({path:path.join(root,'public/pit-field-builder.js')});
  await page.locator('#pitFieldAdd').click();let panel=page.locator('.pit-field-editor').last();
  await panel.getByLabel('Field name').fill('Capabilities');await panel.getByLabel('Input type').selectOption('multiselect');await panel.getByLabel('Choices (one per line)').fill('Climb\nIntake');
  await panel.getByLabel('Color code for Climb',{exact:true}).fill('rgb(255, 128, 0)');
  await panel.getByLabel('Color code for Intake',{exact:true}).fill('hsv(240, 100%, 100%)');
  assert.equal(await panel.getByLabel('Pick color for Climb',{exact:true}).inputValue(),'#ff8000');
  assert.equal(await panel.getByLabel('Pick color for Intake',{exact:true}).inputValue(),'#0000ff');
  await panel.getByLabel('Color code for Climb',{exact:true}).fill('rgb(999, 0, 0)');assert.equal(await panel.getByLabel('Color code for Climb',{exact:true}).evaluate(n=>n.validity.valid),false);
  await panel.getByLabel('Pick color for Climb',{exact:true}).evaluate(n=>{n.value='#22aa44';n.dispatchEvent(new Event('input',{bubbles:true}));});assert.equal(await panel.getByLabel('Color code for Climb',{exact:true}).inputValue(),'#22aa44');
  await panel.locator('.pit-field-title').click();
  assert.equal(await page.locator('details[open]').count(),0);
  await page.evaluate(()=>scrollTo(0,0));
  const from=await page.getByRole('button',{name:'Drag to reorder Capabilities',exact:true}).boundingBox();
  const to=await page.getByRole('button',{name:'Drag to reorder Notes',exact:true}).boundingBox();
  await page.mouse.move(from.x+from.width/2,from.y+from.height/2);await page.mouse.down();await page.mouse.move(to.x+to.width/2,to.y+2,{steps:12});await page.mouse.up();
  assert.equal(await page.locator('details[open]').count(),0);
  // Keyboard movement uses the same handle and preserves unsaved color edits.
  await page.getByRole('button',{name:'Drag to reorder Capabilities',exact:true}).press('ArrowDown');
  await page.getByRole('button',{name:'Drag to reorder Capabilities',exact:true}).press('ArrowUp');
  assert.equal(await page.getByRole('button',{name:'Move up'}).count(),0);

  assert.equal(await page.locator('.pit-field-editor').nth(1).getByLabel('Field name').inputValue(),'Capabilities');
  // Touch handles move closed fields without starting page scrolling.
  const cdp=await page.context().newCDPSession(page);
  const touchFrom=await page.getByRole('button',{name:'Drag to reorder Capabilities',exact:true}).boundingBox();const touchTo=await page.getByRole('button',{name:'Drag to reorder Notes',exact:true}).boundingBox();
  const x=touchFrom.x+touchFrom.width/2,y=touchFrom.y+touchFrom.height/2,endY=touchTo.y+touchTo.height;
  await cdp.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x,y}]});
  for(let i=1;i<=10;i++)await cdp.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x,y:y+(endY-y)*i/10}]});
  await cdp.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});
  assert.equal(await page.locator('.pit-field-editor').nth(2).getByLabel('Field name').inputValue(),'Capabilities');
  await page.getByRole('button',{name:'Drag to reorder Capabilities',exact:true}).press('ArrowUp');
  // Escape restores the order from before a drag.
  const cancelFrom=await page.getByRole('button',{name:'Drag to reorder Capabilities',exact:true}).boundingBox();const cancelTo=await page.getByRole('button',{name:'Drag to reorder Robot photo',exact:true}).boundingBox();
  await page.mouse.move(cancelFrom.x+16,cancelFrom.y+18);await page.mouse.down();await page.mouse.move(cancelTo.x+16,cancelTo.y+1,{steps:10});await page.keyboard.press('Escape');await page.mouse.up();
  assert.equal(await page.locator('.pit-field-editor').nth(1).getByLabel('Field name').inputValue(),'Capabilities');
  await page.locator('.pit-field-editor').nth(1).locator('.pit-field-title').click();
  await page.evaluate(()=>document.getElementById('pitFieldBuilder').addEventListener('submit',e=>e.preventDefault()));
  await page.getByRole('button',{name:'Save',exact:true}).click();let saved=JSON.parse(await page.locator('#pitFieldSchema').inputValue());
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);if(process.env.PIT_SCREENSHOT)await page.screenshot({path:process.env.PIT_SCREENSHOT,fullPage:true});
  assert.equal(saved[1].label,'Capabilities');assert.deepEqual(saved[1].options,['Climb','Intake']);assert.deepEqual(saved[1].colors,{Climb:'#22aa44',Intake:'#0000ff'});assert.match(saved[1].id,/^custom_[a-f0-9]{24}$/);
  await page.locator('.pit-field-editor').nth(1).getByRole('button',{name:'Remove field'}).click();await page.getByRole('button',{name:'Save',exact:true}).click();saved=JSON.parse(await page.locator('#pitFieldSchema').inputValue());assert.equal(saved.length,2);
  await page.goto(`http://127.0.0.1:${port}`);await page.addScriptTag({path:path.join(root,'public/pit-field-form.js')});
  assert.equal(await page.getByLabel('Intake',{exact:true}).evaluate(n=>getComputedStyle(n.parentElement).backgroundColor),'rgb(255, 0, 0)');
  await page.getByLabel('Intake',{exact:true}).check();await page.getByLabel('Climb',{exact:true}).check();await page.locator('[data-robot-meta]').selectOption('Other');assert.equal(await page.getByLabel('Custom Robot Meta').isVisible(),true);await page.getByLabel('Custom Robot Meta').fill('Custom turret');
  const image=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a6ZkAAAAASUVORK5CYII=','base64');
  await page.locator('input[type=file]').setInputFiles({name:'robot.png',mimeType:'image/png',buffer:image});await page.getByRole('button',{name:'Save pit record'}).click();let result=JSON.parse(await page.locator('body').innerText());assert.deepEqual(result.custom_1111111111111111,['Intake','Climb']);assert.equal(result.robot_meta_other,'Custom turret');assert.equal(result.removed,'preserved');assert.equal(result.image.mime,'image/png');assert.equal(result.image.base64,image.toString('base64'));
  await page.goto(`http://127.0.0.1:${port}`);await page.locator('input[type=file]').setInputFiles({name:'bad.png',mimeType:'image/png',buffer:Buffer.from('<script>bad</script>')});await page.getByRole('button',{name:'Save pit record'}).click();result=JSON.parse(await page.locator('body').innerText());assert.match(result.error,/JPEG/);
  assert.deepEqual(errors,[]);console.log('Passed: mouse/touch/keyboard drag ordering, Escape cancellation, RGB/HSV/picker colors, invalid color rejection, mobile builder save; pit multiselect, Other input, real multipart image upload and unsafe-image rejection.');
 }finally{await browser?.close();server.kill();fs.rmSync(dir,{recursive:true,force:true});}
})().catch(e=>{console.error(e);process.exitCode=1;});
