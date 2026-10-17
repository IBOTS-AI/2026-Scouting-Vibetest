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
 $fields=[['id'=>'custom_1111111111111111','label'=>'Mechanisms','type'=>'multiselect','options'=>['Intake','Climb']],['id'=>'custom_2222222222222222','label'=>'Inspection image','type'=>'image','options'=>[]],['id'=>'robot_meta','label'=>'Robot Meta','type'=>'dropdown','options'=>['Turret','Other']]];
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
  browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_BIN||undefined,args:['--no-sandbox','--disable-dev-shm-usage']});const page=await browser.newPage({viewport:{width:390,height:844}});const errors=[];page.on('pageerror',e=>errors.push(e.message));page.on('dialog',d=>d.accept());
  const schema=[{id:'robot_photo',label:'Robot photo',type:'image',options:[]},{id:'notes',label:'Notes',type:'textarea',options:[]}];
  await page.goto(`http://127.0.0.1:${port}`);
  await page.setContent(`<link rel="stylesheet" href="http://127.0.0.1:${port}/style.css"><form id="pitFieldBuilder"><input type="hidden" id="pitFieldSchema"><div id="pitFieldList"></div><button type="button" id="pitFieldAdd">Add field</button><button>Save</button></form><script type="application/json" id="pitFieldData">${JSON.stringify(schema)}</script>`);
  await page.addStyleTag({path:path.join(root,'public/style.css')});
  await page.addScriptTag({path:path.join(root,'public/pit-field-builder.js')});
  await page.locator('#pitFieldAdd').click();let panel=page.locator('.pit-field-editor').last();
  await panel.getByLabel('Field name').fill('Capabilities');await panel.getByLabel('Input type').selectOption('multiselect');await panel.getByLabel('Choices (one per line)').fill('Climb\nIntake');
  await panel.getByRole('button',{name:'Move up'}).click();
  assert.equal(await page.locator('.pit-field-editor').nth(1).getByLabel('Field name').inputValue(),'Capabilities');
  await page.evaluate(()=>document.getElementById('pitFieldBuilder').addEventListener('submit',e=>e.preventDefault()));
  await page.getByRole('button',{name:'Save',exact:true}).click();let saved=JSON.parse(await page.locator('#pitFieldSchema').inputValue());
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);if(process.env.PIT_SCREENSHOT)await page.screenshot({path:process.env.PIT_SCREENSHOT,fullPage:true});
  assert.equal(saved[1].label,'Capabilities');assert.deepEqual(saved[1].options,['Climb','Intake']);assert.match(saved[1].id,/^custom_[a-f0-9]{24}$/);
  await page.locator('.pit-field-editor').nth(1).getByRole('button',{name:'Remove field'}).click();await page.getByRole('button',{name:'Save',exact:true}).click();saved=JSON.parse(await page.locator('#pitFieldSchema').inputValue());assert.equal(saved.length,2);
  await page.goto(`http://127.0.0.1:${port}`);await page.addScriptTag({path:path.join(root,'public/pit-field-form.js')});
  await page.getByLabel('Intake',{exact:true}).check();await page.getByLabel('Climb',{exact:true}).check();await page.locator('[data-robot-meta]').selectOption('Other');assert.equal(await page.getByLabel('Custom Robot Meta').isVisible(),true);await page.getByLabel('Custom Robot Meta').fill('Custom turret');
  const image=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a6ZkAAAAASUVORK5CYII=','base64');
  await page.locator('input[type=file]').setInputFiles({name:'robot.png',mimeType:'image/png',buffer:image});await page.getByRole('button',{name:'Save pit record'}).click();let result=JSON.parse(await page.locator('body').innerText());assert.deepEqual(result.custom_1111111111111111,['Intake','Climb']);assert.equal(result.robot_meta_other,'Custom turret');assert.equal(result.removed,'preserved');assert.equal(result.image.mime,'image/png');assert.equal(result.image.base64,image.toString('base64'));
  await page.goto(`http://127.0.0.1:${port}`);await page.locator('input[type=file]').setInputFiles({name:'bad.png',mimeType:'image/png',buffer:Buffer.from('<script>bad</script>')});await page.getByRole('button',{name:'Save pit record'}).click();result=JSON.parse(await page.locator('body').innerText());assert.match(result.error,/JPEG/);
  assert.deepEqual(errors,[]);console.log('Passed: mobile builder add/edit/reorder/remove/save; pit multiselect, Other input, real multipart image upload and unsafe-image rejection.');
 }finally{await browser?.close();server.kill();fs.rmSync(dir,{recursive:true,force:true});}
})().catch(e=>{console.error(e);process.exitCode=1;});
