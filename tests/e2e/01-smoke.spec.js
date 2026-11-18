const { test, expect } = require('@playwright/test');
const { signInAs, strayAlerts, watchErrors } = require('./helpers');

// Every screen opens, for the role that may open it, with nothing thrown in the
// browser. The cheapest test there is, and the one that catches a broken route()
// in a layout — which in `daftar` took down every admin screen at once.

test.afterEach(async ({ page }) => {
    expect(strayAlerts(page), 'an alert was accepted where a confirm was expected').toEqual([]);
});

const ADMIN_PAGES = [
    '/admin/dashboard',
    '/admin/events',
    '/admin/events/create',
    '/admin/events/calendar',
    '/admin/polling',
    '/admin/polling/create',
    '/admin/companies',
    '/admin/companies/create',
    '/admin/admins/hq',
    '/admin/admins/state',
    '/admin/admins/create',
    '/admin/settings/lists',
    '/admin/settings/kraf-codes',
    '/admin/settings/kraf-hierarchy',
];

test('every admin screen opens for a super admin, with no browser errors', async ({ page }) => {
    await signInAs(page, 'e2e-super');

    for (const url of ADMIN_PAGES) {
        const errors = watchErrors(page);
        const response = await page.goto(url);

        expect(response.status(), url).toBe(200);
        expect(errors, `browser errors on ${url}`).toEqual([]);
        page.removeAllListeners('pageerror');
        page.removeAllListeners('console');
    }
});

test('a state admin sees no national screens in the sidebar and is refused them by URL', async ({ page }) => {
    await signInAs(page, 'e2e-admin');

    // By text, not by link role: a group is a button, so a link count of zero
    // would pass whether or not it showed. The first loop proves the
    // locator finds what IS there, so the second cannot pass vacuously.
    const sidebar = page.locator('#sidebar');
    for (const label of ['User', 'Companies', 'The Event', 'Events', 'Lot Draw']) {
        await expect(sidebar.getByText(label, { exact: true }), label).toHaveCount(1);
    }
    for (const label of ['Setting', 'Dropdown Lists', 'HQ Admins', 'State Admins']) {
        await expect(sidebar.getByText(label, { exact: true }), label).toHaveCount(0);
    }

    for (const url of ['/admin/settings/lists', '/admin/polling/create', '/admin/admins/hq', '/admin/admins/state']) {
        expect((await page.goto(url)).status(), url).toBe(403);
    }
});

test('a participant lands on their dashboard, with the draw listed', async ({ page }) => {
    const errors = watchErrors(page);
    await signInAs(page, 'E2E-TENUN');

    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(page.getByText('E2E Fair')).toBeVisible();
    expect(errors).toEqual([]);
});
