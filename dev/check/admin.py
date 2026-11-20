# -*- coding: utf-8 -*-
"""
The admin pages of a test shop (MGL_BASE_URL) in a real browser: the overview and one page audit. Screenshots next to
this file. The user name and password come from the environment; the password is never printed.

    MGL_ADMIN_USER=... MGL_ADMIN_PASSWORD=... python dev/check/admin.py [url to audit]
"""
import os
import sys

from playwright.sync_api import sync_playwright

BASE = os.environ.get('MGL_BASE_URL', '').rstrip('/')
ADMIN = BASE + os.environ.get('MGL_ADMIN_PATH', '/admin')
if not BASE:
    sys.exit('MGL_BASE_URL is not set')
USER = os.environ.get('MGL_ADMIN_USER', '')
PASSWORD = os.environ.get('MGL_ADMIN_PASSWORD', '')
URL = sys.argv[1] if len(sys.argv) > 1 else BASE + '/'
HERE = os.path.dirname(os.path.abspath(__file__))
errors = []

if not USER:
    sys.exit('MGL_ADMIN_USER is not set')
if not PASSWORD:
    sys.exit('MGL_ADMIN_PASSWORD is not set')

with sync_playwright() as playwright:
    browser = playwright.chromium.launch()
    page = browser.new_page(viewport={'width': 1500, 'height': 1100})
    page.on('pageerror', lambda error: errors.append(str(error)))
    page.goto(ADMIN, wait_until='domcontentloaded')
    page.fill('#username', USER)
    page.fill('#login', PASSWORD)
    page.click('.action-login')
    page.wait_for_load_state('networkidle')

    page.goto(ADMIN + '/magelan_ai/dashboard/index/', wait_until='domcontentloaded')
    page.wait_for_selector('.mgl-ai__card')
    print('overview tables:', page.locator('.mgl-ai__table').count(),
          'crawler rows:', page.locator('.mgl-ai__card').first.locator('tbody tr').count())
    page.screenshot(path=os.path.join(HERE, 'admin-overview.png'), full_page=True)

    page.goto(ADMIN + '/magelan_ai/audit/index/', wait_until='domcontentloaded')
    page.fill('input[name=url]', URL)
    page.click('.mgl-ai__form button[type=submit]')
    page.wait_for_load_state('domcontentloaded')
    page.wait_for_selector('.mgl-ai__card', timeout=60000)

    if page.locator('.message-error').count():
        print('audit error:', page.locator('.message-error').first.inner_text())
    else:
        print('score:', page.locator('.mgl-ai__score-value').inner_text())
        for row in page.locator('.mgl-ai__score + table tbody tr').all():
            print('  ', ' | '.join(cell.inner_text().strip()[:90] for cell in row.locator('td').all()))

    print('readiness rows:', page.locator('.mgl-ai__meter').count())
    page.screenshot(path=os.path.join(HERE, 'admin-audit.png'), full_page=True)
    browser.close()

print('JS errors:', errors or 'none')
