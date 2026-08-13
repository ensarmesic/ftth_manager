import { chromium } from 'playwright';

const baseUrl=process.env.BASE_URL??'http://127.0.0.1:8000',username=process.env.E2E_USERNAME,password=process.env.E2E_PASSWORD;
if(!username||!password){process.stderr.write('✗ E2E_USERNAME i E2E_PASSWORD su obavezni.\n');process.exit(1);}
const browser=await chromium.launch();
const page=await browser.newPage({viewport:{width:1440,height:1000}});
const errors=[],failed=[];
page.on('pageerror',error=>errors.push(error.message));
page.on('console',message=>{if(message.type()==='error'&&!message.text().includes('ERR_NETWORK_ACCESS_DENIED'))errors.push(message.text());});
page.on('response',response=>{if(response.url().startsWith(baseUrl)&&response.status()>=400)failed.push(`${response.status()} ${response.url()}`);});
try{
    await page.goto(`${baseUrl}/prijava`,{waitUntil:'networkidle'});await page.fill('input[name="username"]',username);await page.fill('input[name="password"]',password);
    await Promise.all([page.waitForURL(`${baseUrl}/**`),page.click('button[type="submit"]')]);
    await page.evaluate(()=>localStorage.setItem('ftthOnboardingComplete','1'));
    await page.goto(`${baseUrl}/fiber-sema`,{waitUntil:'networkidle'});
    await page.waitForSelector('.schema-project',{state:'visible'});
    const project=page.locator('.schema-project:visible').first();
    await project.locator('[data-schema-view="power-budget"]').click();
    await project.locator('[data-schema-panel="power-budget"]:not(.hidden)').waitFor();
    await project.locator('[data-schema-view="fiber-check"]').click();
    await project.locator('[data-schema-panel="fiber-check"]:not(.hidden)').waitFor();
    await project.locator('[data-schema-view="topology"]').click();
    await project.locator('.topology-graph-stage svg').waitFor();
    await project.locator('[data-topology-action="collapse"]').click();
    const search=project.locator('[data-fiber-search]');await search.fill('ODO');await search.fill('');
    if(errors.length)throw new Error(`JavaScript/console: ${errors.join(' | ')}`);
    if(failed.length)throw new Error(`HTTP: ${failed.join(' | ')}`);
    await page.screenshot({path:'storage/logs/fiber-workflow-desktop.png',fullPage:true});
    await page.setViewportSize({width:820,height:1180});await page.screenshot({path:'storage/logs/fiber-workflow-tablet.png',fullPage:true});
    await page.setViewportSize({width:390,height:844});await page.screenshot({path:'storage/logs/fiber-workflow-mobile.png',fullPage:true});
    process.stdout.write('✓ Fiber šema: tracing kontrole, power-budget, provjera, topologija, pretraga i responsive prikaz rade.\n');
}catch(error){process.stderr.write(`✗ Fiber workflow nije prošao: ${error.message}\n`);process.exitCode=1;}finally{await browser.close();}
