const path = require( 'path' );
const playwright = require( 'playwright' );
const logPath = path.resolve(path.join(__dirname, 'log' ))

const main = async() => {
    const browser = await playwright[ 'chromium' ].launch();
    const context = await browser.newContext();
    const page = await context.newPage();
    await page.goto( 'http://localhost:8080/w' );
    await page.screenshot({
        path: `${logPath}/screenshot.png`
    });
    await browser.close();
}

main();