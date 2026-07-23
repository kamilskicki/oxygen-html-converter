'use strict';

const path = require('path');
const { pathToFileURL } = require('url');
const { chromium } = require('playwright');

const assetsDir = path.resolve(__dirname, '..');

const jobs = [
  ['icon.svg', 'icon-128x128.png', 128, 128],
  ['icon.svg', 'icon-256x256.png', 256, 256],
  ['banner.svg', 'banner-772x250.png', 772, 250],
  ['banner.svg', 'banner-1544x500.png', 1544, 500],
];

async function render() {
  const browser = await chromium.launch({ headless: true });

  try {
    for (const [sourceName, outputName, width, height] of jobs) {
      const page = await browser.newPage({
        viewport: { width, height },
        deviceScaleFactor: 1,
      });

      await page.goto(pathToFileURL(path.join(assetsDir, sourceName)).href, {
        waitUntil: 'load',
      });
      await page.evaluate(() => document.fonts && document.fonts.ready);
      await page.screenshot({
        path: path.join(assetsDir, outputName),
        type: 'png',
        animations: 'disabled',
      });
      await page.close();
      console.log(`rendered ${outputName} (${width}x${height})`);
    }

    const proofPage = await browser.newPage({
      viewport: { width: 1120, height: 640 },
      deviceScaleFactor: 1,
    });
    await proofPage.goto(pathToFileURL(path.join(__dirname, 'proof.html')).href, {
      waitUntil: 'load',
    });
    await proofPage.waitForFunction(() => {
      const image = document.querySelector('#verified-asset');
      return image && image.complete && image.naturalWidth === 772 && image.naturalHeight === 250;
    });
    await proofPage.screenshot({
      path: path.join(__dirname, 'banner-772x250-browser-proof.png'),
      type: 'png',
      animations: 'disabled',
    });
    await proofPage.close();
    console.log('captured verification/banner-772x250-browser-proof.png');
  } finally {
    await browser.close();
  }
}

render().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
