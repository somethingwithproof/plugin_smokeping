// SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
// SPDX-License-Identifier: GPL-2.0-or-later
//
// Keyboard focus and contrast of the SmokePing graph link, for one Cacti
// theme; docs/research/graph-link-themes.md has the method and results.
//
//   CACTI_URL=<cacti base URL> node graph-link-check.mjs <theme>
//
// Run it from a directory whose node_modules holds playwright. Reads the
// admin password from /run81/pw and the id of a graph of a device with
// SmokePing targets from /run81/graph. Prints one JSON line. With SHOTS
// set, writes the focused and unfocused images to /shots.

import { chromium } from 'playwright';
import fs from 'node:fs';

const base = process.env.CACTI_URL;
// Cacti 1.2.31's themes; the name also names the image files.
const themes = ['classic', 'dark', 'midwinter', 'modern', 'paper-plane', 'paw', 'sunrise'];
const pw = fs.readFileSync('/run81/pw', 'utf8').trim();
const graph = fs.readFileSync('/run81/graph', 'utf8').trim();
// The theme comes from the list, never from argv, since it names files.
const theme = themes.find(t => t === process.argv[2]);

if (!base || theme === undefined) {
	console.error(`usage: CACTI_URL=<url> node graph-link-check.mjs <${themes.join('|')}>`);
	process.exit(2);
}

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
await page.goto(base + '/index.php');
await page.fill('input[name=login_username]', 'admin');
await page.fill('input[name=login_password]', pw);
await Promise.all([page.waitForLoadState('networkidle'), page.click('input[type=submit], button[type=submit]')]);
await page.goto(`${base}/graph.php?action=view&local_graph_id=${graph}&rra_id=0`, { waitUntil: 'networkidle' });
// Some themes fade the page in; let it settle before measuring pixels.
await page.waitForTimeout(2000);
// midwinter fades the body in from opacity 0 by script, which does not run
// to the end headless; show the page as the theme intends.
const forcedOpacity = await page.evaluate(() => getComputedStyle(document.body).opacity === '0');
if (forcedOpacity) await page.addStyleTag({ content: 'body{opacity:1!important}' });

const ours = page.locator('a[href*="smokeping.php?host_id="]').first();
const count = await page.locator('a[href*="smokeping.php?host_id="]').count();
const actualTheme = await page.evaluate(() => [...document.querySelectorAll('link[rel=stylesheet]')].map(l => l.href).find(h => h.includes('/themes/')) || '');

// Non-text contrast of an icon link (WCAG 1.4.11, 3:1) from the rendered
// pixels: the most contrasting pixel inside the icon against the median of
// the ring of pixels just outside the link, which is the real background
// (themes draw it with gradients and images).
const contrast = async (loc) => {
	await page.evaluate(() => document.activeElement?.blur());
	await page.mouse.move(0, 0);
	await loc.scrollIntoViewIfNeeded();
	const r = await loc.locator('img').evaluate(i => { const b = i.getBoundingClientRect(); return { x: b.left, y: b.top, width: b.width, height: b.height }; });
	const pad = 3;
	const clip = { x: r.x - pad, y: r.y - pad, width: r.width + 2 * pad, height: r.height + 2 * pad };
	const png = (await page.screenshot({ clip })).toString('base64');
	return page.evaluate(async ({ png, pad }) => {
		const img = new Image(); img.src = 'data:image/png;base64,' + png; await img.decode();
		const cv = document.createElement('canvas'); cv.width = img.width; cv.height = img.height;
		const cx = cv.getContext('2d'); cx.drawImage(img, 0, 0);
		const d = cx.getImageData(0, 0, cv.width, cv.height).data;
		const lum = (i) => { const f = c => { c /= 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; }; return 0.2126 * f(d[i]) + 0.7152 * f(d[i + 1]) + 0.0722 * f(d[i + 2]); };
		const ring = [], inner = [];
		for (let y = 0; y < cv.height; y++) for (let x = 0; x < cv.width; x++) {
			const i = (y * cv.width + x) * 4;
			(x < pad || y < pad || x >= cv.width - pad || y >= cv.height - pad ? ring : inner).push(lum(i));
		}
		ring.sort((a, b) => a - b);
		const bg = ring[Math.floor(ring.length / 2)];
		let best = 1;
		for (const l of inner) { const c = (Math.max(l, bg) + 0.05) / (Math.min(l, bg) + 0.05); if (c > best) best = c; }
		return { bgLum: Math.round(bg * 1000) / 1000, ratio: Math.round(best * 100) / 100 };
	}, { png, pad });
};

// Reach the link with Tab, then compare its pixels unfocused and focused.
const reachByTab = async (loc) => {
	await page.evaluate(() => { document.activeElement?.blur(); window.scrollTo(0, 0); });
	for (let i = 1; i <= 300; i++) {
		await page.keyboard.press('Tab');
		if (await loc.evaluate(a => a === document.activeElement)) return i;
	}
	return 0;
};
const focusVisible = async (loc) => {
	// Focus by keyboard first, then capture the same viewport area focused and
	// blurred: blur() does not scroll, so both shots show the same pixels.
	const tabs = await reachByTab(loc);
	await page.mouse.move(0, 0);
	await page.waitForTimeout(300);
	const r = await loc.evaluate(a => { const b = a.getBoundingClientRect(); return { x: b.left, y: b.top, width: b.width, height: b.height }; });
	const clip = { x: Math.max(0, r.x - 8), y: Math.max(0, r.y - 8), width: r.width + 16, height: r.height + 16 };
	const style = await loc.evaluate(a => { const s = getComputedStyle(a); return `focusVisible:${a.matches(':focus-visible')} outline:${s.outlineStyle} ${s.outlineWidth} ${s.outlineColor}; shadow:${s.boxShadow}; bg:${s.backgroundColor}`; });
	const after = await page.screenshot({ clip });
	await loc.evaluate(a => a.blur());
	await page.waitForTimeout(300);
	const before = await page.screenshot({ clip });
	if (process.env.SHOTS) { const tag = (await loc.getAttribute('href') || '').includes('smokeping') ? 'ours' : 'core'; fs.writeFileSync(`/shots/${theme}-${tag}-before.png`, before); fs.writeFileSync(`/shots/${theme}-${tag}-after.png`, after); }
	return { tabs, changed: !before.equals(after), style, stayed: page.url().includes('graph.php') };
};

const result = { theme, actualTheme: actualTheme.slice(actualTheme.lastIndexOf('/themes/') + 8).split('/')[0], links: count, forcedOpacity };
if (count > 0) {
	result.ours = { contrast: await contrast(ours), focus: await focusVisible(ours) };
	const core = page.locator('a.iconLink:not([href*="smokeping.php"])').first();
	if (await core.count()) result.core = { href: (await core.getAttribute('href') || '').slice(0, 40), contrast: await contrast(core), focus: await focusVisible(core) };
}
console.log(JSON.stringify(result));
await browser.close();
