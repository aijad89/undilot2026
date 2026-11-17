const { test, expect } = require('@playwright/test');
const { signInAs, acceptDialog, strayAlerts, watchErrors } = require('./helpers');

// One whole draw through the screens, in order — each test hands its state to the
// next, so the file runs serially. E2eSeeder put three companies in "E2E Fair":
//   Tenun E2E  TTN 3x3   (has an IC)
//   Logam E2E  LEH 3x3   (no IC)
//   Kayu E2E   RKP 3x6

test.describe.configure({ mode: 'serial' });

test.afterEach(async ({ page }) => {
    expect(strayAlerts(page), 'an alert was accepted where a confirm was expected').toEqual([]);
});

let eventUrl;

test('the office builds a two-location plan with the plan builder', async ({ page }) => {
    const errors = watchErrors(page);
    await signInAs(page, 'e2e-super');

    await page.goto('/admin/polling/create');
    await page.selectOption('[name=event_id]', { label: 'E2E Fair' });
    await page.fill('#start_date_d', '2026-01-01');
    await page.fill('#deadline_d', '2030-12-31');
    await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: /Next: Locations/ }).click()]);

    // The reference card counts the draw list before a plot exists.
    await expect(page.locator('.coverage-chip')).toHaveCount(3);

    const addAllocation = async (row, category, code, size) => {
        await row.locator('.add-bidang-btn').click();
        const alloc = row.locator('.bidang-allocation-row').last();
        await alloc.locator('.main-category-select').selectOption(category);
        const value = await alloc.locator('.bidang-select option', { hasText: `[${code}]` }).first().getAttribute('value');
        await alloc.locator('.bidang-select').selectOption(value);
        await alloc.locator('.bidang-size').selectOption(size);
        // Filled from the chip: one company holds each code at this size.
        await expect(alloc.locator('.plots-count')).toHaveValue('1');
    };

    // Optional plots with no code: the open pool at that size.
    const addSpare = async (row, size, count) => {
        await row.locator('.add-spare-btn').click();
        const line = row.locator('.spare-line').last();
        await line.locator('.spare-size').selectOption(size);
        await line.locator('.spare-quantity').fill(count);
    };

    const hallA = page.locator('.plot-size-row').nth(0);
    await hallA.locator('.plot-location').fill('Dewan A');
    await addAllocation(hallA, 'TEKSTIL', 'TTN', '3x3');
    await addAllocation(hallA, 'HASIL LOGAM', 'LEH', '3x3');
    await addSpare(hallA, '3x3', '1');
    await expect(hallA.locator('.location-total')).toHaveText('3');

    // A new location goes on top, with the cursor in its name.
    await page.locator('#addSizeBtn').click();
    const hallB = page.locator('.plot-size-row').nth(0);
    await expect(hallB.locator('.plot-location')).toBeFocused();
    await expect(page.locator('.plot-size-row').nth(1).locator('.plot-location')).toHaveValue('Dewan A');
    await hallB.locator('.plot-location').fill('Dewan B');
    await addAllocation(hallB, 'HASIL RIMBA', 'RKP', '3x6');
    await addSpare(hallB, '3x6', '1');

    await expect(page.locator('#coverageSummary')).toHaveText('every code has plots');

    await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Create Lot Draw' }).click()]);
    await expect(page.locator('.alert-success')).toContainText('Lot draw created');
    eventUrl = page.url();

    await page.getByRole('tab', { name: 'Plots' }).click();
    await expect(page.locator('#tab-plots tbody tr')).toHaveCount(5);
    expect(errors).toEqual([]);
});

test('a preset across craft codes is asked about first, and kept on OK', async ({ page }) => {
    await signInAs(page, 'e2e-super');
    await page.goto(eventUrl);
    await page.getByRole('tab', { name: 'Setup' }).click();

    // Logam (LEH) onto a TTN lot: the form must ask.
    await page.locator('#registration_id').selectOption({ label: 'Logam E2E (LEH)' });
    const ttn = await page.locator('#plot_id option', { hasText: 'TTN' }).first().getAttribute('value');
    await page.locator('#plot_id').selectOption(ttn);

    const dialog = acceptDialog(page);
    await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Set aside' }).click()]);

    expect(dialog.type, `page said: ${dialog.message}`).toBe('confirm');
    expect(dialog.message).toContain('holds LEH');
    expect(dialog.message).toContain('set aside for TTN');
    await expect(page.locator('.alert-success')).toContainText('set aside for Logam E2E');
});

test('the preset is taken back, which the draw page says plainly', async ({ page }) => {
    await signInAs(page, 'e2e-super');
    await page.goto(eventUrl);
    await page.getByRole('tab', { name: 'Draw list' }).click();

    const dialog = acceptDialog(page);
    await Promise.all([page.waitForNavigation(), page.locator('#tab-draw tr', { hasText: 'Logam E2E' }).getByRole('button', { name: 'Take back' }).click()]);

    expect(dialog.type).toBe('confirm');
    expect(dialog.message).toContain('Remove the reservation');
    await expect(page.locator('.alert-success')).toContainText('Reservation on plot');
});

test('voting opens through its guard', async ({ page }) => {
    await signInAs(page, 'e2e-super');
    await page.goto(eventUrl);

    const dialog = acceptDialog(page);
    await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Open voting' }).click()]);

    expect(dialog.type).toBe('confirm');
    expect(dialog.message).toContain('Open voting?');
    await expect(page.locator('.draw-band [aria-current="step"]')).toHaveText(/Open/);
});

test('two participants confirm, and each sees a lot of their own code revealed', async ({ page }) => {
    for (const [who, code] of [['E2E-TENUN', 'TTN'], ['E2E-LOGAM', 'LEH']]) {
        await signInAs(page, who);
        // The draw card's own button; the to-do row above it goes to the same page.
        await page.getByRole('link', { name: 'Confirm Lot', exact: true }).click();

        await page.check('#agree');
        await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: /Confirm Lot/ }).click()]);

        await expect(page.locator('[data-lot-reveal]')).toBeVisible();

        // The reveal sits still for two seconds before anything rolls, so the
        // eye has time to arrive. Asserted because the hold leaves no mark of
        // its own: drop it and the lot simply appears, with every assertion
        // below still passing.
        await expect(page.locator('.lr-flap.is-rolling').first()).toBeHidden({ timeout: 1000 });
        await expect(page.locator('.lr-flap.is-rolling').first()).toBeVisible({ timeout: 6000 });
        await expect(page.locator('strong[data-lot-code]')).toContainText(`-${code}`);

        const slip = await page.request.get(await page.locator('a', { hasText: 'Print Slip' }).getAttribute('href'));
        expect(slip.headers()['content-type']).toBe('application/pdf');
    }
});

test('the office closes voting and assigns the rest, and the draw completes', async ({ page }) => {
    await signInAs(page, 'e2e-super');
    await page.goto(eventUrl);

    let dialog = acceptDialog(page);
    await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Close voting' }).click()]);
    expect(dialog.type).toBe('confirm');

    dialog = acceptDialog(page);
    await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Assign remaining lots' }).click()]);
    expect(dialog.type).toBe('confirm');

    await expect(page.locator('.alert-success')).toContainText('Every company in the draw now has a lot');
    await expect(page.locator('.draw-band [aria-current="step"]')).toHaveText(/End/);

    await signInAs(page, 'E2E-KAYU');
    await expect(page.getByText(/DEWAN B-RKP1/)).toBeVisible();
});
