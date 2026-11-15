const { chromium } = require(process.env.CLUBUNIO_PLAYWRIGHT || 'playwright');
const { createHmac } = require('node:crypto');
const assert = require('node:assert/strict');

function otp(secret) {
    let bits = '';
    for (const c of secret.replace(/=|\s/g, '').toUpperCase()) {
        bits += 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'.indexOf(c).toString(2).padStart(5, '0');
    }
    const key = Buffer.from((bits.match(/.{8}/g) || []).map(byte => parseInt(byte, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
    const digest = createHmac('sha1', key).update(counter).digest();
    return String((digest.readUInt32BE(digest[19] & 15) & 0x7fffffff) % 1000000).padStart(6, '0');
}

(async () => {
    const accounts = JSON.parse(process.env.CLUBUNIO_WIZARD_QA || '[]');
    assert.equal(accounts.length, 4);
    const browser = await chromium.launch({ channel: 'msedge' });
    try {
        for (const [i, account] of accounts.entries()) {
            const mobile = i >= 2;
            const passkey = i % 2 === 0;
            const context = await browser.newContext(mobile
                ? { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 }
                : { viewport: { width: 1440, height: 900 } });
            try {
                const page = await context.newPage();
                if (passkey) {
                    const cdp = await context.newCDPSession(page);
                    await cdp.send('WebAuthn.enable');
                    await cdp.send('WebAuthn.addVirtualAuthenticator', { options: {
                        protocol: 'ctap2', transport: 'internal', hasResidentKey: true,
                        hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true,
                    } });
                }
                await page.goto('https://dev.clubunio.ch/account/security?setup=1');
                await page.locator('[data-enrollment] [name=username]').fill(account.username);
                await page.locator('[data-enrollment] [name=password]').fill(account.password);
                await page.locator('[data-step=account] button').click();
                await page.locator('[data-step=method]').waitFor();
                await page.locator(`[value=${passkey ? 'passkey' : 'totp'}]`).click();
                if (!passkey) {
                    await page.locator('[data-totp]').waitFor();
                    const secret = await page.locator('[data-secret]').textContent();
                    await page.locator('[data-totp] input').fill(otp(secret));
                    await page.locator('[data-totp] [value=confirm]').click();
                }
                await page.locator('[data-recovery]').waitFor();
                assert.equal(await page.locator('[data-codes] li').count(), 8);
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
                await page.locator('[data-finish]').click();
                await page.locator('[data-password]').waitFor();
                assert.equal(await page.locator('[data-codes] li').count(), 0);
                console.log(`${mobile ? 'Mobile viewport' : 'Desktop'} / ${passkey ? 'Passkey' : 'Authenticator'}: passed`);
            } finally {
                await context.close();
            }
        }
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
