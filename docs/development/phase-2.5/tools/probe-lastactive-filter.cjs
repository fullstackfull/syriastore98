const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path=require('path');
const REPO='/home/user/syriastore98';
const STATE=path.join(REPO,'docs/development/phase-2/tools/set-admin-preview-state.php');
(async()=>{
  execFileSync('php',[STATE,'1','light','ltr','en','merchant@example.test'],{cwd:path.dirname(STATE),
    env:{...process.env,DB_CONNECTION:'sqlite',DB_DATABASE:path.join(REPO,'database/p2dev.sqlite'),CACHE_STORE:'file'},stdio:['ignore','pipe','pipe']});
  const b=await chromium.launch(); const p=await (await b.newContext({viewport:{width:1440,height:900}})).newPage();
  await p.goto('http://127.0.0.1:8899/login',{waitUntil:'domcontentloaded'});
  await p.fill('input[name="email"]','merchant@example.test'); await p.fill('input[name="password"]','password');
  await Promise.all([p.waitForLoadState('domcontentloaded'),p.click('button[type="submit"]')]);
  await p.goto('http://127.0.0.1:8899/customer',{waitUntil:'domcontentloaded'}); await p.waitForTimeout(1500);
  const before = await p.locator('#customer-table tbody tr').count();
  await p.selectOption('#customer_field','Last active'); await p.waitForTimeout(900);
  const ops = await p.locator('#select-container select option').allTextContents();
  await p.selectOption('#select-container select','After');
  await p.fill('#text-field input','2026-01-01');
  await Promise.all([p.waitForLoadState('domcontentloaded'),p.click('#applyfilter')]);
  await p.waitForTimeout(1800);
  const after = await p.locator('#customer-table tbody tr').count();
  const names = (await p.locator('#customer-table tbody').innerText().catch(()=>''))||'';
  console.log('   operators offered :', JSON.stringify(ops));
  console.log('   url               :', new URL(p.url()).search);
  console.log('   rows              :', before, '->', after);
  console.log('   remaining         :', names.replace(/\s+/g,' ').slice(0,120));
  await b.close(); process.exit(0);
})();
