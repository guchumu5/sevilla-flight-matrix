import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../public/assets/js/belt-alarm.js', import.meta.url), 'utf8');
const swSource = fs.readFileSync(new URL('../public/sw.js', import.meta.url), 'utf8');
const cases = JSON.parse(fs.readFileSync(new URL('./fixtures/belt-alarm-cases.json', import.meta.url)));
const event = (id, from='A/3', to='B/7', flight_id='1', extra={}) => ({
  id:String(id), flight_id, source:'aena', event_type:'belt_changed', from, to,
  expires_at:Math.floor(Date.now()/1000)+1100, ...extra
});
function page(storage = new Map(), blocked = false) {
  const overlays = []; const vibrations = []; let created = 0;
  class Element {
    constructor(tag) { this.tag=tag; this.children=[]; this.handlers={}; }
    setAttribute() {}
    appendChild(child) { this.children.push(child); }
    replaceChildren() { this.children=[]; }
    querySelector() { return this.children.find(child => child.tag === 'button'); }
    addEventListener(type, fn) { this.handlers[type]=fn; }
    remove() { const i=overlays.indexOf(this); if (i>=0) overlays.splice(i,1); }
  }
  const context = vm.createContext({
    Date, console, navigator:{vibrate:pattern=>vibrations.push(pattern)},
    document:{getElementById:()=>null, head:new Element('head'), body:{appendChild:el=>{created++; overlays.push(el);}},
      createElement:tag=>new Element(tag)},
    localStorage:{getItem:key=>{if(blocked) throw Error('storage blocked'); return storage.get(key)||null;},
      setItem:(key,value)=>{if(blocked) throw Error('storage blocked'); storage.set(key,value);}},
    setInterval:()=>1, clearInterval:()=>{}, setTimeout:()=>1, clearTimeout:()=>{}
  });
  context.window=context;
  vm.runInContext(source,context);
  return {alarm:context.MatrixBeltAlarm, overlays, vibrations, count:()=>created};
}
const p=page();
for (const [from,to,expected] of cases) assert.equal(p.alarm.isBlockChange(from,to),expected,`${from} → ${to}`);
assert.equal(p.alarm.format('B/3'),'A/3');
assert.equal(p.alarm.body(event(1)), 'A/3 → B/7 · cambio de bloque 1–6 a 7–8. Hay que moverse YA.');
for (const bad of [event(0),event(1,'A','B'),event(1,'7','8'),event(1,'3','7','1',{source:'airlabs'}),
  event(1,'3','7','1',{event_type:'hall_changed'}), event(1,'3','7','1',{expires_at:1})]) {
  assert.equal(p.alarm.validEvent(bad),false);
  p.alarm.process([{id:1, critical_belt_event:bad}]);
}
assert.equal(p.count(),0);
const card = e => ({id:e.flight_id, physical_flight:'VLG3190', origin_name:'<img src=x>', critical_belt_event:e});
const first=event(100);
p.alarm.process([card(first)]);
assert.equal(p.count(),1);
assert.match(p.overlays[0].children[2].textContent, /<img src=x>/); // literal text, no HTML interpolation
p.alarm.process([card(first)]); assert.equal(p.count(),1);
p.alarm.process([card(event(101,'B/7','A/3'))]); assert.equal(p.count(),2);
p.alarm.process([card(event(102))]); assert.equal(p.count(),3);
p.alarm.process([{id:1, critical_belt_event:null}]); assert.equal(p.overlays.length,0);
assert.equal(p.vibrations.at(-1),0);
const storage=new Map();
page(storage).alarm.process([card(first)]);
const reloaded=page(storage); reloaded.alarm.process([card(first)]); assert.equal(reloaded.count(),0);
const noStorage=page(new Map(),true); noStorage.alarm.process([card(first)]); noStorage.alarm.process([card(first)]);
assert.equal(noStorage.count(),1);
const queued=page(); queued.alarm.process([card(first),card(event(200,'3','8','2'))]);
assert.equal(queued.count(),1);
queued.overlays[0].querySelector().handlers.click(); assert.equal(queued.count(),2);
queued.overlays[0].querySelector().handlers.click(); assert.equal(queued.overlays.length,0);

function worker(cacheStore=new Map()) {
  const shown=[]; const handlers={}; let fail=false; let fallback=null;
  const cache={match:async key=>cacheStore.has(key) ? new Response(cacheStore.get(key)) : undefined,
    put:async(key,response)=>cacheStore.set(key,await response.text())};
  const context=vm.createContext({Date,Response,URL,console,
    caches:{open:async()=>cache},
    registration:{scope:'https://example.test/', showNotification:async(title,options)=>{
      if(fail) throw Error('controlled display error'); shown.push({title,...options});
    }}, clients:{claim:async()=>{}}, location:{href:'https://example.test/sw.js'},
    skipWaiting:async()=>{}, addEventListener:(type,fn)=>{handlers[type]=fn;},
    fetch:async()=>({ok:true,json:async()=>({notification:fallback})})
  });
  context.self=context;
  context.importScripts=()=>vm.runInContext(source,context);
  vm.runInContext(swSource,context);
  return {shown,show:notice=>context.show(notice),fail:value=>{fail=value;},fallback:value=>{fallback=value;},
    push:async data=>{let promise; handlers.push({data,waitUntil:p=>{promise=p;}}); await promise;}};
}
const notice = e => ({title:'🚨 ALARMA CINTA',body:'untrusted old body',urgent:true,
  tag:`matrix-watch-${e.flight_id}-belt-critical`,target_url:'index.php?flight='+e.flight_id,critical_belt:e});
const cacheStore=new Map([['/matrix-device-token','test-device']]); const w=worker(cacheStore);
await Promise.all([w.show(notice(first)),w.show(notice(first))]); assert.equal(w.shown.length,1);
assert.equal(w.shown[0].body,p.alarm.body(first)); assert.equal(w.shown[0].requireInteraction,true);
assert.equal(w.shown[0].data.critical_belt.id,first.id);
await w.show(notice(event(101,'7','3'))); await w.show(notice(event(102))); assert.equal(w.shown.length,3);
await w.show(notice(first)); assert.equal(w.shown.length,3); // late old push
for(const bad of [event(103,'A','B'),event(103,'7','8'),event(103,'3','7','1',{source:'manual'}),
  event(103,'3','7','1',{expires_at:1})]) await w.show(notice(bad));
await w.show({title:'ALARMA CINTA',urgent:true}); assert.equal(w.shown.length,3);
const restarted=worker(cacheStore); await restarted.show(notice(event(102))); assert.equal(restarted.shown.length,0);
const big=event('90071992547409930'); await restarted.show(notice(big));
await restarted.show(notice(event('90071992547409931','7','3'))); assert.equal(restarted.shown.length,2);
const retry=worker(); retry.fail(true); await assert.rejects(retry.show(notice(first)));
retry.fail(false); await retry.show(notice(first)); assert.equal(retry.shown.length,1);
const fallback=worker(new Map([['/matrix-device-token','fallback-device']])); fallback.fallback(notice(first));
await fallback.push(null); await fallback.push({json:()=>notice(first)}); assert.equal(fallback.shown.length,1);
await fallback.show({title:'Cambio de sala',body:'A → B',tag:'ordinary'});
assert.equal(fallback.shown[1].requireInteraction,false);
console.log('Belt visual alarm and Service Worker regressions: OK');
