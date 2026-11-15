# Graph link: keyboard focus and contrast in Cacti's themes

Research for [#40](https://github.com/somethingwithproof/plugin_smokeping/issues/40), run 2026-11-15. Question: is the link the plugin adds beside a device's graphs (`graph_buttons`) reachable by keyboard, visibly focused, and distinguishable from its background in every theme Cacti 1.2.31 ships?

**Result.** The link is reachable with Tab in all seven themes, and its icon contrasts with the background at 5.03:1 or more, above the 3:1 that WCAG 2.1 asks of non-text content (1.4.11). Focus was not visible in two themes: in **dark** and **modern**, focusing the link changed nothing on screen, and Cacti's own graph buttons behave the same way there. The plugin now draws its own focus ring on the link, a white ring inside a black one, which shows on light and dark backgrounds, and it is visible in all seven themes.

## Method

1. `tests/run-permissions-e2e.sh` built a Cacti 1.2.31 database with SmokePing targets; `cli/smokeping.php provision` then made a device with a summary and a smoke graph. Cacti was served with PHP's built-in server.
2. `tests/a11y/graph-link-check.mjs` ran in the `mcr.microsoft.com/playwright:v1.49.1-noble` image (Chromium 1148, headless, 1280×900), once per theme, with the theme set for the user and globally. It logs in, opens the graph page, and for the plugin's link and for the first of Cacti's own graph buttons on the page:
   - counts Tab presses from the top of the page until the link has focus;
   - takes the link's area with keyboard focus and again after `blur()` (which does not scroll), and compares the two;
   - computes the contrast of the most contrasting icon pixel against the median of the pixels just outside the link, from a screenshot, so gradients and background images count.
3. Each pair of focus images was also looked at: a pixel difference alone does not prove a visible ring.

midwinter fades the page in from `opacity: 0` by script, which did not finish in the headless browser, so the page stayed blank. For that theme the check sets `body` to `opacity: 1` before measuring, which is the state the theme fades to.

## Results

Plugin link, before the change unless noted. Contrast is the icon against its background; Cacti's own button is the first graph button on the same page.

| Theme | Tab presses | Icon contrast | Focus visible | Focus visible with the plugin's ring | Cacti's button contrast |
|---|---|---|---|---|---|
| classic | 14 | 6.50 | yes, thin dark ring | yes | 8.19 |
| dark | 14 | 5.03 | **no** | yes | 15.46 |
| midwinter | 12 | 6.50 | yes, thin dark ring | yes | 8.19 |
| modern | 14 | 6.28 | **no** | yes | 7.92 |
| paper-plane | 13 | 6.50 | yes, thin dark ring | yes | 8.19 |
| paw | 13 | 6.50 | yes, thin dark ring | yes | 8.19 |
| sunrise | 13 | 5.71 | yes, thin light ring | yes | 17.94 |

## Change

`GraphLinks::style()` prints, once per page, a rule for the link's class: `a.smokepingGraphLink:focus-visible{outline:2px solid #fff;outline-offset:0;box-shadow:0 0 0 4px #000}`. It applies to keyboard focus only and to no other element. Cacti 1.2.31 and the current 1.2.x branch keep `'unsafe-inline'` in `style-src`, so the inline rule is allowed.

## Limits

- One browser engine (Chromium) and one viewport.
- Contrast is measured for the icon. The link has no visible text; its name, "SmokePing Targets of this Device", is in `title` and `aria-label`.
- Cacti's own graph buttons have no visible focus in dark and modern either. That is a theme matter for Cacti and is not changed here.
