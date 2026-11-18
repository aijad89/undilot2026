const { test, expect } = require('@playwright/test');
const { signInAs, watchErrors } = require('./helpers');

/**
 * The participant's menu, as the office has it: a navy rail fixed down the left
 * on a desk, which the menu button hides and shows; a drawer on a phone. A
 * browser behaviour, so only a browser can check it.
 *
 * THE RAIL HOLDS THREE LINKS — Events, Dashboard, Profile. Notifications and
 * Past events were taken off it on purpose (see partials/portal-bar.blade.php):
 * both screens still work at their own URLs, they are simply not offered here,
 * because the participant's job in this app is one thing and a bar with five
 * doors makes it harder to find.
 *
 * `Profile` is the stand-in wherever a test needs "a link in the rail" — it is
 * present on every page below and is never the current one at that moment.
 */
const DESK = { width: 1280, height: 800 };
const PHONE = { width: 390, height: 844 };

test('on a desk the menu is a rail that stays, and the button hides and shows it', async ({ page }) => {
    await page.setViewportSize(DESK);
    const errors = watchErrors(page);
    await signInAs(page, 'E2E-TENUN');

    const menu = page.getByRole('button', { name: 'Open menu' });
    const rail = page.locator('#apSidebar');
    const content = page.locator('#main-content');

    // There without asking, beside the page, not over it.
    await expect(rail.getByRole('link', { name: 'Dashboard' })).toBeVisible();
    await expect(rail.getByRole('link', { name: 'Dashboard' })).toHaveAttribute('aria-current', 'page');
    await expect(rail.getByRole('link', { name: 'Profile' })).toBeVisible();
    await expect(rail.getByRole('button', { name: 'Sign Out' })).toBeVisible();
    await expect(rail.getByRole('button', { name: 'Close menu' })).toBeHidden();
    await expect(menu).toHaveAttribute('aria-expanded', 'true');
    const railBox = await rail.boundingBox();
    expect((await content.boundingBox()).x).toBeGreaterThanOrEqual(railBox.x + railBox.width);

    // The button hides it and the page takes the room; again shows it.
    await menu.click();
    await expect(rail.getByRole('link', { name: 'Profile' })).toBeHidden();
    await expect(menu).toHaveAttribute('aria-expanded', 'false');
    await expect.poll(async () => (await content.boundingBox()).x).toBeLessThan(railBox.width);
    await menu.click();
    await expect(rail.getByRole('link', { name: 'Profile' })).toBeVisible();

    // The rail offers these three and no others. `a.ap-nav-item` rather than
    // every link or every nav-item: the brand at the top is a link but is the
    // frame rather than a choice, and Sign Out carries the same class on a
    // button. Three destinations is the claim.
    await expect(rail.locator('a.ap-nav-item')).toHaveCount(3);
    await expect(rail.getByRole('link', { name: 'Notifications' })).toHaveCount(0);
    await expect(rail.getByRole('link', { name: 'Past events' })).toHaveCount(0);

    // The same rail on the public pages, so the frame holds from page to page.
    await rail.getByRole('link', { name: 'Events', exact: true }).click();
    await expect(page).toHaveURL(/\/$/);
    await expect(rail.getByRole('link', { name: 'Events', exact: true })).toHaveAttribute('aria-current', 'page');

    await Promise.all([page.waitForNavigation(), rail.getByRole('link', { name: 'Dashboard' }).click()]);
    await expect(page).toHaveURL(/\/dashboard$/);

    await Promise.all([page.waitForNavigation(), rail.getByRole('link', { name: 'Profile' }).click()]);
    await expect(page).toHaveURL(/\/profile$/);
    await expect(rail.getByRole('link', { name: 'Profile' })).toHaveAttribute('aria-current', 'page');
    await expect(page.getByRole('heading', { name: 'Your company' })).toBeVisible();
    expect(errors).toEqual([]);
});

test('on a phone the menu is a drawer: opened by the button, closed by Escape, a tap outside or its own button', async ({ page }) => {
    await page.setViewportSize(PHONE);
    const errors = watchErrors(page);
    await signInAs(page, 'E2E-TENUN');

    const menu = page.getByRole('button', { name: 'Open menu' });
    const drawer = page.locator('#apSidebar');

    // Closed: not shown, and its links cannot be reached.
    await expect(menu).toHaveAttribute('aria-expanded', 'false');
    await expect(drawer.getByRole('link', { name: 'Profile' })).toBeHidden();

    await menu.click();
    await expect(menu).toHaveAttribute('aria-expanded', 'true');
    await expect(drawer.getByRole('link', { name: 'Profile' })).toBeVisible();
    await expect(drawer.getByRole('button', { name: 'Sign Out' })).toBeVisible();
    await expect(drawer.getByRole('link', { name: 'Dashboard' })).toBeFocused();

    // Escape closes it and focus goes back to the menu button.
    await page.keyboard.press('Escape');
    await expect(drawer.getByRole('link', { name: 'Profile' })).toBeHidden();
    await expect(menu).toBeFocused();

    await menu.click();
    await page.locator('#apOverlay').click({ position: { x: PHONE.width - 40, y: 400 } });
    await expect(drawer.getByRole('link', { name: 'Profile' })).toBeHidden();
    await menu.click();
    await drawer.getByRole('button', { name: 'Close menu' }).click();
    await expect(drawer.getByRole('link', { name: 'Profile' })).toBeHidden();
    expect(errors).toEqual([]);
});

test('signed out on a desk there is no rail: the nav sits in the bar', async ({ page }) => {
    await page.setViewportSize(DESK);
    await page.goto('/');

    await expect(page.getByRole('button', { name: 'Open menu' })).toBeHidden();
    await expect(page.locator('#apSidebar').getByRole('link', { name: 'Events', exact: true })).toBeHidden();
    await expect(page.locator('.ap-topnav').getByRole('link', { name: 'Events' })).toBeVisible();
    expect((await page.locator('#main-content').boundingBox()).x).toBe(0);
});
