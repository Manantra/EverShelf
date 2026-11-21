#!/usr/bin/env node
'use strict';
// Issue #42: align published Home Assistant webhook examples with the real
// _fireHaWebhook() JSON and make the expiry automation ignore unrelated events.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'..');
const docs=fs.readFileSync(path.join(root,'docs/wiki/Home-Assistant.md'),'utf8');
const api=fs.readFileSync(path.join(root,'api/index.php'),'utf8');
const cron=fs.readFileSync(path.join(root,'api/cron_smart_shopping.php'),'utf8');

const body=docs.match(/### Webhook Payload \(POST body\)[\s\S]*?```json\s*([\s\S]*?)\s*```/);
assert(body,'HA wiki webhook JSON example must exist and be valid JSON');
const example=JSON.parse(body[1]);
assert.equal(example.event,'expiry_alert');
assert.equal(example.source,'evershelf');
assert(Number.isInteger(example.ts) && example.ts>1_000_000_000,
 'Actual _fireHaWebhook sends a Unix timestamp in ts, not timestamp');
assert.equal(example.type,'expiring_soon');
assert(Number.isInteger(example.count) && example.count>0);
assert(Array.isArray(example.items) && example.items.length>0);
assert(!('data' in example),'Actual webhook data is flattened at JSON root');
assert(!('timestamp' in example),'Actual webhook has ts, not timestamp');

const emitter=api.match(/function _fireHaWebhook\(string \$event, array \$data\): void \{([\s\S]*?)\n\}/);
assert(emitter,'Actual API webhook emitter must exist');
assert(emitter[1].includes("['event' => $event, 'source' => 'evershelf', 'ts' => time()]"),
 'Webhook emitter root fields changed; re-check documented payload');
assert(emitter[1].includes("], $data)"),
 'Webhook emitter no longer merges event fields at JSON root');
assert(cron.includes("_fireHaWebhook('expiry_alert', ["),
 'Expiry cron must still generate the documented event');
for(const key of ['count','items','type','days','summary'])
 assert(new RegExp("'"+key+"'\\s*=>").test(cron),
  'Expiry cron must still supply '+key);

const expiry=docs.match(/### Example: Expiry Alert → Telegram[\s\S]*?```yaml\s*([\s\S]*?)\s*```/);
assert(expiry,'HA wiki expiry automation example must exist');
const yaml=expiry[1];
assert(yaml.includes("trigger.json.get('event') == 'expiry_alert'"),
 'Expiry-only automation must reject unrelated shopping/stock events');
assert(yaml.includes("trigger.json.get('type') == 'expiring_soon'"),
 'Expiring-soon example must not mislabel expired events');
assert(yaml.includes("trigger.json['count']"),
 'Expiry count must be read from the JSON root');
assert(yaml.includes("trigger.json['items']"),
 'Expiry items must be root-level with bracket notation (avoid dict.items method)');
assert(!yaml.includes('trigger.json.data'),
 'Never use the non-existent data wrapper in Home Assistant automation');

const location=docs.match(/### Example: Automation on location\s*([\s\S]*?)(?:\n---\n|\n## )/);
assert(location,'HA wiki location-condition example must exist');
assert(location[1].includes("trigger.json.get('items', [])"),
 'Location condition must read root-level items safely');
assert(!location[1].includes('trigger.json.data'),
 'Location condition must not assume non-existent data wrapper');

console.log('PASS real webhook emitter + expiry cron match documented flat payload');
console.log('PASS HA expiry automation filters event/type and uses JSON-safe root-level item access');
console.log('PASS location condition guards missing root-level items');
