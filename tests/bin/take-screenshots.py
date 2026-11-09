import base64
import subprocess
import sys
import time
from pathlib import Path

from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By

BASE = 'http://localhost:8080'
USER, PW = 'admin', 'Admin1234!'
REPO = Path(__file__).resolve().parents[2]
COMPOSE = ['docker', 'compose', '-f', 'tests/docker-compose.yml', 'exec', '-T', 'db',
           'mariadb', '-u', 'humhub', '-phumhub', 'humhub', '-e']
CHROME = r'C:\Program Files\Google\Chrome\Application\chrome.exe'

TABS = ['settings', 'existing-files', 'activity']
LANGS = {
    'en': {'chrome': 'en-US', 'labels': ['Settings', 'Existing files', 'Activity']},
    'it': {'chrome': 'it-IT', 'labels': ['Impostazioni', 'File esistenti', 'Attività']},
}


def set_user_language(code):
    subprocess.run(COMPOSE + ["UPDATE user SET language='%s' WHERE id=1" % code],
                   cwd=REPO, check=True, capture_output=True)


def tab_labels(driver):
    return [el.text.strip() for el in driver.find_elements(
        By.CSS_SELECTOR, 'ul.nav-tabs.tab-menu a.nav-link[data-bs-toggle="tab"]')]


def shoot(driver, out):
    driver.execute_cdp_cmd('Emulation.setDeviceMetricsOverride', {
        'width': 1280, 'height': 900, 'deviceScaleFactor': 1, 'mobile': False,
    })
    time.sleep(0.5)
    shot_png = driver.execute_cdp_cmd('Page.captureScreenshot', {
        'format': 'png',
        'captureBeyondViewport': True,
    })
    with open(out, 'wb') as fh:
        fh.write(base64.b64decode(shot_png['data']))
    print(out)


def run(hh_lang, out_dir):
    cfg = LANGS[hh_lang]
    set_user_language(hh_lang)
    o = Options()
    o.binary_location = CHROME
    o.add_argument('--headless=new')
    o.add_argument('--lang=' + cfg['chrome'])
    o.add_argument('--window-size=1280,1400')
    o.add_argument('--hide-scrollbars')
    o.add_experimental_option('prefs', {'intl.accept_languages': cfg['chrome']})
    d = webdriver.Chrome(options=o)
    try:
        d.get(BASE + '/user/auth/login')
        time.sleep(1.5)
        d.find_element(By.ID, 'login_username').send_keys(USER)
        d.find_element(By.ID, 'continue-button').click()
        time.sleep(1.5)
        d.find_element(By.ID, 'login_password').send_keys(PW)
        d.find_element(By.ID, 'login-button').click()
        time.sleep(2.5)

        d.get(BASE + '/metadata-cleaner/admin')
        time.sleep(3)

        labels = tab_labels(d)
        print(hh_lang, 'tab labels:', labels)
        if labels != cfg['labels']:
            raise RuntimeError(
                f'Language check failed for {hh_lang}: got {labels}, '
                f'expected {cfg["labels"]}')

        links = d.find_elements(
            By.CSS_SELECTOR, 'ul.nav-tabs.tab-menu a.nav-link[data-bs-toggle="tab"]')
        for i, name in enumerate(TABS):
            links[i].click()
            time.sleep(1.5)
            shoot(d, str(out_dir / f'{name}.png'))

        d.get(BASE + '/metadata-cleaner/admin/view?id=707')
        time.sleep(3)
        shoot(d, str(out_dir / 'log-entry.png'))
    finally:
        d.quit()


if __name__ == '__main__':
    shots = REPO / 'assets' / 'screenshots'
    run('en', shots)
    run('it', shots / 'it')
