'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '..', '..', 'assets', 'app.js'), 'utf8');
const start = source.indexOf('function resolveTrustedApiUrl(path){');
const end = source.indexOf('\nasync function api(path', start);
if (start < 0 || end < 0) throw new Error('resolveTrustedApiUrl helper is missing or malformed');
const helper = source.slice(start, end) + '\nglobalThis.__resolver = resolveTrustedApiUrl;';
const fetchCallCount = (source.match(/\bfetch\s*\(/g) || []).length;
if (fetchCallCount !== 1 || !source.includes('await fetch(url,opt)')) {
  throw new Error('A raw fetch call bypasses the trusted API helper; expected exactly one guarded fetch sink, found ' + fetchCallCount);
}
function createResolver(config, href) {
  const sandbox = { A: config, URL, window: { location: { href } } };
  vm.runInNewContext(helper, sandbox, { timeout: 1000 });
  return sandbox.__resolver;
}
const base = 'https://erp.example.test/wp-json/stockland/v1';
const base16 = 'https://erp.example.test/wp-json/stockland/v16';
let resolver = createResolver({ api: base, api_v16: base16 }, 'https://erp.example.test/erp/');
const approved = resolver('/app/bootstrap');
if (!approved.startsWith(base + '/')) throw new Error('v1 API route was not resolved correctly');
const approved16 = resolver(base16 + '/sku-search?q=SKU-0001');
if (!approved16.startsWith(base16 + '/')) throw new Error('v16 API route was not resolved correctly');
for (const blocked of ['https://evil.example/collect', 'https://erp.example.test/wp-admin/options.php']) {
  let didBlock = false;
  try { resolver(blocked); } catch (error) { didBlock = /Blocked ERP request/.test(String(error.message)); }
  if (!didBlock) throw new Error('Unsafe request destination was accepted: ' + blocked);
}
resolver = createResolver({ api: 'https://erp.example.test/?rest_route=%2Fstockland%2Fv1', api_v16: 'https://erp.example.test/?rest_route=%2Fstockland%2Fv16' }, 'https://erp.example.test/');
if (!resolver('https://erp.example.test/?rest_route=%2Fstockland%2Fv1%2Fapp%2Fbootstrap').includes('rest_route=')) throw new Error('WordPress query-based REST route was rejected');
console.log('REST API origin QA PASS: v1/v16 accepted; external/arbitrary endpoints blocked; query-based WordPress REST supported.');
