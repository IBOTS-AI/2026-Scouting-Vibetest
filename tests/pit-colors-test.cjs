const fs=require('fs'),vm=require('vm'),assert=require('assert');
const context={document:{getElementById:()=>null}};vm.createContext(context);vm.runInContext(fs.readFileSync('public/pit-field-builder.js','utf8'),context);
for(const [value,expected] of [
 ['#f80','#ff8800'],[' AABBCC ','#aabbcc'],['#A0B1C2','#a0b1c2'],
 ['rgb(255, 128, 0)','#ff8000'],['rgb(100% 0% 50%)','#ff0080'],
 ['hsv(0, 100%, 100%)','#ff0000'],['hsv(120, 100, 100)','#00ff00'],['hsv(240, 100%, 100%)','#0000ff'],['hsv(360, 100, 100)','#ff0000'],['hsv(0, 0%, 50%)','#808080'],
 ['hsl(60, 100%, 50%)','#ffff00'],['hsl(180 100% 50%)','#00ffff'],['hsl(300, 100%, 50%)','#ff00ff'],
 ['rgb(256,0,0)',null],['rgb(-1,0,0)',null],['rgb(1,2)',null],['hsv(361,100,100)',null],['hsv(0,101%,100%)',null],['hsl(0,100,101)',null],['#zzzzzz',null],['#ff0000;display:none',null],['url(https://example.com)',null],['',null]
])assert.equal(context.parsePitColor(value),expected,value);
console.log('Passed: HEX, RGB and percentage RGB, HSV hue/grayscale, HSL, invalid ranges and CSS injection.');
