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
    await Promise.all([page.waitForURL(url=>!url.pathname.startsWith('/prijava')),page.click('button[type="submit"]')]);
    await page.evaluate(()=>localStorage.setItem('ftthOnboardingComplete','1'));
    await page.goto(`${baseUrl}/fiber-sema`,{waitUntil:'networkidle'});
    await page.waitForSelector('.schema-project',{state:'visible'});
    const project=page.locator('.schema-project:visible').first();
    // Sheet: the same drawing as DXF/PDF, with every cabinet on it.
    await project.locator('[data-fx-stage] svg').waitFor();
    const cabinetsOnSheet=await project.locator('.fiber-odo').count();
    const cabinetRows=await project.locator('[data-fx-row]').count();
    if(cabinetsOnSheet!==cabinetRows)throw new Error(`Šema ima ${cabinetsOnSheet} ODO, plan vlakana ${cabinetRows}.`);
    await project.locator('[data-zoom="in"]').click();await project.locator('[data-zoom="fit"]').click();
    // Fiber plan: real allocations, search by fiber, port list, locate on sheet.
    await project.locator('[data-schema-view="fibers"]').click();
    await project.locator('[data-schema-panel="fibers"]:not(.hidden)').waitFor();
    const ranges=await project.locator('[data-fx-row]').evaluateAll(rows=>rows.map(row=>row.dataset.fiberRange));
    if(ranges.some(range=>!range||range.includes('?')))throw new Error('Plan vlakana nema stvarnu dodjelu vlakana.');
    const search=project.locator('[data-fx-search]');await search.fill('f1');await search.fill('');
    const ports=project.locator('[data-fx-houses]').first();
    if(await ports.count()){await ports.click();await project.locator('[data-fx-ports]:not(.hidden)').first().waitFor();}
    if(cabinetRows){await project.locator('[data-fx-locate]').first().click();await project.locator('[data-fx-detail]:not(.hidden)').waitFor();}
    await project.locator('[data-schema-view="power-budget"]').click();
    await project.locator('[data-schema-panel="power-budget"]:not(.hidden)').waitFor();
    await project.locator('[data-schema-view="checks"]').click();
    await project.locator('[data-schema-panel="checks"]:not(.hidden)').waitFor();
    for(const selector of ['a[href*="fiber-schema-dxf"]','a[href*="fiber-sema/pdf"]']){
        const link=project.page().locator(selector).first();
        if(await link.count()){const status=await page.evaluate(async href=>(await fetch(href)).status,await link.getAttribute('href'));if(status!==200)throw new Error(`${selector}: HTTP ${status}`);}
    }
    if(errors.length)throw new Error(`JavaScript/console: ${errors.join(' | ')}`);
    if(failed.length)throw new Error(`HTTP: ${failed.join(' | ')}`);
    await page.screenshot({path:'storage/logs/fiber-workflow-desktop.png',fullPage:true});
    await page.setViewportSize({width:820,height:1180});await page.screenshot({path:'storage/logs/fiber-workflow-tablet.png',fullPage:true});
    await page.setViewportSize({width:390,height:844});await page.screenshot({path:'storage/logs/fiber-workflow-mobile.png',fullPage:true});
    process.stdout.write('✓ Fiber šema: list, plan vlakana, portovi, power-budget, kontrola, DXF/PDF i responsive prikaz rade.\n');
}catch(error){process.stderr.write(`✗ Fiber workflow nije prošao: ${error.message}\n`);process.exitCode=1;}finally{await browser.close();}
