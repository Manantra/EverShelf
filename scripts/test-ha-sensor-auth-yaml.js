#!/usr/bin/env node
'use strict';
/* Regression: secure HA REST templates must not leak API tokens into YAML. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/app.js'),'utf8');
const start = source.indexOf('function _renderHaSensorYaml() {');
const end = source.indexOf('\nfunction copyHaSensorYaml()',start);
assert(start>=0 && end>start, 'HA YAML generator must exist');
const fn = source.slice(start,end);
const origin='http://evershelf.example.test';
const secret='TEST_SHOULD_NOT_APPEAR_IN_YAML_012345';
function make(token,required) {
  const panel={textContent:''};
  const ctx={document:{getElementById(id){return id==='ha-sensor-yaml'?panel:null;}},
    window:{location:{origin,pathname:'/index.html'},_apiTokenRequired:required},
    getApiToken:()=>token};
  vm.runInNewContext(fn+'\n_renderHaSensorYaml();',ctx);
  return panel.textContent;
}
for(const [name,token,needed,expectHeaders] of [
  ['secure paired',secret,false,true],
  ['secure announced', '',true,true],
  ['legacy open LAN','',false,false]
]){
 const yaml=make(token,needed);
 const count=(yaml.match(/^    headers:$/gm)||[]).length;
 const auth=(yaml.match(/^      Authorization: !secret evershelf_api_authorization$/gm)||[]).length;
 const sensors=(yaml.match(/^  - platform: rest$/gm)||[]).length;
 assert.equal(sensors,4,name+' sensors');
 assert.equal(count,expectHeaders?4:0,name+' auth header count');
 assert.equal(auth,expectHeaders?4:0,name+' secret references');
 assert(!yaml.includes(secret),name+' secret must not be embedded');
 assert(!yaml.includes('Authorization: Bearer '+secret),name+' must not leak token');
 assert.equal((yaml.match(/^    resource: "http:\/\/evershelf\.example\.test\/api\/\?action=ha_sensor/gm)||[]).length,4,
   name+' resource routes');
 assert(yaml.includes('scan_interval: 300'),name+' preserves sensor fields');
 if(expectHeaders){
  assert(yaml.includes('secrets.yaml'),name+' must explain secret storage');
  assert(yaml.includes('NOT the Home Assistant HA_TOKEN'),name+' distinction must be explicit');
 }else{
  assert(!yaml.includes('secrets.yaml'),name+' open LAN YAML remains simple');
 }
 console.log('PASS generated HA YAML:',name);
}
const docs=fs.readFileSync(path.join(__dirname,'../docs/wiki/Home-Assistant.md'),'utf8');
assert(docs.includes('evershelf_api_authorization: "Bearer YOUR_EVERSHELF_API_TOKEN"'));
assert((docs.match(/Authorization: !secret evershelf_api_authorization/g)||[]).length>=3);
assert(docs.includes('are not') || docs.includes('not the **EverShelf API token**'));
console.log('PASS HA documentation uses private secrets.yaml, not inline credentials');
