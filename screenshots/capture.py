"""Seeds sample cards and captures the screenshots in this folder.

Each screenshot matches one of the module's wireframes (see README.md here).

Needs a fresh web2project demo install (dev/demo-data/setup.sh in
web2project, "Build Family Home - 1420 Cedar Lane") with the Cards module
installed and active, plus Python 3 with requests and playwright (Chromium),
and the mysql client.

    W2P_DIR=/path/to/web2project python3 screenshots/capture.py

Run it once on a fresh install: it adds cards, a second project and a
sub-task, and it moves cards while capturing.
"""
import datetime
import json
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, '..', 'cards', 'tests'))
from api_test import Client, sql, card, BASE, INDEX  # noqa: E402

W2P_DIR = os.environ.get('W2P_DIR', '/var/www/web2project')
sys.path.insert(0, os.path.join(W2P_DIR, 'dev', 'demo-data'))
from w2p_client import W2P  # noqa: E402

from playwright.sync_api import sync_playwright  # noqa: E402

PROJECT = 1
ADMIN_PASS = os.environ.get('W2P_ADMIN_PASS', 'passw0rd')
WORKER_PASS = os.environ.get('W2P_WORKER_PASS', 'buildit2026')
TODAY = datetime.date.today()
# Wide enough for all five status columns beside web2project's sidebar.
VIEWPORT = {'width': 1800, 'height': 1000}


def day(n):
    return (TODAY + datetime.timedelta(days=n)).isoformat()


def uid(username):
    return int(sql("select user_id from users where user_username = '%s'" % username)[0][0])


def task_id(name):
    return int(sql("select task_id from tasks where task_name like '%s%%' order by task_id limit 1" % name.replace("'", "''"))[0][0])


# --------------------------------------------------------------------- seed

def seed():
    admin = Client('admin', ADMIN_PASS)
    dana = Client('dwhitfield', WORKER_PASS)
    marcus = Client('mlee', WORKER_PASS)
    ui = W2P().login()

    board = admin.call('board', {'project_id': PROJECT})[1]['data']['board']
    st = {s['name']: s['id'] for s in board['statuses']}
    users = {'jordan': uid('admin'), 'dana': uid('dwhitfield'), 'marcus': uid('mlee'),
             'rosa': uid('rdelgado'), 'priya': uid('pnair')}

    # A second project, so the all-projects board has something to show.
    company = sql('select project_company from projects where project_id = %d' % PROJECT)[0][0]
    ui.submit('m=projects&a=addedit', dict(
        project_name='Detached garage', project_short_name='Garage', project_company=company,
        project_owner=users['dana'], project_status='3', project_start_date=day(0).replace('-', ''),
        start_date='01/Nov/2026', project_end_date=day(120).replace('-', ''), end_date='01/Mar/2027',
        project_color_identifier='4F7BB5'))
    garage = int(sql("select project_id from projects where project_short_name = 'Garage'")[0][0])

    # A sub-task under "Rough-in electrical", for the Task tab.
    electrical = task_id('Rough-in electrical')
    ui.submit('m=tasks&a=addedit&task_project=%d' % PROJECT, dict(
        task_name='Service panel upgrade', task_project=PROJECT, new_task_project=PROJECT, task_parent=electrical,
        task_access='0', task_status='0', task_start_date=day(2).replace('-', '') + '0800',
        task_end_date=day(4).replace('-', '') + '1700', start_hour='08', start_minute='00',
        end_hour='17', end_minute='00', task_duration='3', task_duration_type='24',
        task_dynamic='0', task_notify='0', task_owner=users['rosa']))
    panel = task_id('Service panel upgrade')

    framing, windows, slab = task_id('Framing & sheathing'), task_id('Windows & exterior doors'), task_id('Foundation walls')
    roofing, plumbing, inspection = task_id('Roofing'), task_id('Rough-in plumbing'), task_id('Rough-in inspection')
    footings, siding = task_id('Excavation & footings'), task_id('Siding')

    def make(client, project, name, status, parent=0, **fields):
        fields.update({'name': name, 'status': st[status]})
        code, body = client.call('create', {'project_id': project, 'parent': parent, 'fields': fields})
        assert code == 200, body
        return body['data']['created']

    def tag(cid, *names):
        for n in names:
            admin.call('tag_add', {'card_id': cid, 'name': n})

    J, D, M, R, P = users['jordan'], users['dana'], users['marcus'], users['rosa'], users['priya']
    c = {}
    # Backlog
    c['paint'] = make(admin, PROJECT, 'Choose interior paint colors', 'Backlog', owner=J, priority=-1, task=task_id('Cabinets'))
    c['solar'] = make(admin, PROJECT, 'Get quotes for rooftop solar', 'Backlog', owner=J, due=day(9))
    c['mailbox'] = make(admin, PROJECT, 'Order mailbox and house numbers', 'Backlog', owner=P, priority=-1, task=siding)
    # To Do
    c['trusses'] = make(dana, PROJECT, 'Confirm roof truss delivery', 'To Do', owner=D, priority=1, due=day(3), task=roofing)
    c['flashing'] = make(dana, PROJECT, 'Order window flashing tape', 'To Do', owner=M, due=day(6), task=windows)
    c['dumpster'] = make(admin, PROJECT, 'Swap the dumpster', 'To Do', owner=J, due=day(1))
    c['panel'] = make(admin, PROJECT, 'Schedule utility disconnect', 'To Do', owner=R, due=day(5), task=panel)
    c['breaker'] = make(admin, PROJECT, 'Buy 200A main breaker', 'To Do', owner=R, task=panel)
    # In Progress
    c['walls'] = make(dana, PROJECT, 'Frame interior walls', 'In Progress', owner=D, priority=1, due=day(8), task=framing,
                      description='Second floor first, then the basement. Studs are 16" on center throughout.')
    c['layout'] = make(dana, PROJECT, 'Mark stud layout', 'In Progress', parent=c['walls'], owner=M, task=framing)
    c['headers'] = make(dana, PROJECT, 'Build window and door headers', 'To Do', parent=c['walls'], owner=M, task=framing)
    c['blocking'] = make(dana, PROJECT, 'Add blocking for cabinets and grab bars', 'Done', parent=c['walls'], owner=D, task=framing)
    c['sheathing'] = make(dana, PROJECT, 'Sheathe the east wall', 'In Progress', owner=M, due=day(2), task=framing)
    c['drains'] = make(admin, PROJECT, 'Run main drain lines', 'In Progress', owner=P, task=plumbing)
    c['circuits'] = make(admin, PROJECT, 'Pull kitchen circuits', 'In Progress', owner=R, task=electrical)
    # Review
    c['moisture'] = make(dana, PROJECT, 'Moisture test on slab', 'Review', owner=D, task=slab, due=day(0))
    c['checklist'] = make(admin, PROJECT, 'Rough-in inspection checklist', 'Review', owner=J, priority=1, task=inspection, due=day(12))
    # Done
    c['footings'] = make(admin, PROJECT, 'Pour footings', 'Done', owner=D, task=footings)
    c['survey'] = make(admin, PROJECT, 'Stake the lot corners', 'Done', owner=D, task=task_id('Site survey'))
    c['permit'] = make(admin, PROJECT, 'Pick up the building permit', 'Done', owner=J, task=task_id('Obtain building'))

    # Garage project
    g = {}
    g['slab'] = make(admin, garage, 'Pour garage slab', 'To Do', owner=D, priority=1, due=day(9))
    g['door'] = make(admin, garage, 'Pick a garage door', 'Backlog', owner=J)
    g['trench'] = make(admin, garage, 'Trench power from the house', 'In Progress', owner=R, due=day(4))
    g['permit'] = make(admin, garage, 'Garage permit drawings', 'Review', owner=J, due=day(7))
    g['site'] = make(admin, garage, 'Clear and level the pad', 'Done', owner=D)
    g['forms'] = make(admin, garage, 'Set slab forms', 'To Do', parent=g['slab'], owner=M)

    # Tags
    tag(c['trusses'], 'Framing', 'Ordering')
    tag(c['walls'], 'Framing')
    tag(c['sheathing'], 'Framing', 'Exterior')
    tag(c['flashing'], 'Ordering', 'Exterior')
    tag(c['moisture'], 'Inspection')
    tag(c['checklist'], 'Inspection')
    tag(c['circuits'], 'Electrical')
    tag(c['panel'], 'Electrical')
    tag(c['breaker'], 'Electrical', 'Ordering')
    tag(g['trench'], 'Electrical')
    tag(g['permit'], 'Inspection')

    # Dependencies
    admin.call('dependency_add', {'card_id': c['flashing'], 'requires': c['sheathing']})
    admin.call('dependency_add', {'card_id': c['checklist'], 'requires': c['circuits']})
    admin.call('dependency_add', {'card_id': c['checklist'], 'requires': c['drains']})
    admin.call('dependency_add', {'card_id': c['walls'], 'requires': c['moisture']})
    admin.call('dependency_add', {'card_id': g['slab'], 'requires': g['permit']})

    # Some history on the card shown in the drawer.
    b = dana.call('board', {'project_id': PROJECT})[1]['data']['board']
    dana.call('comment', {'card_id': c['walls'], 'text': 'Lumber for the second floor arrived this morning.'})
    marcus.call('comment', {'card_id': c['walls'], 'text': 'Layout is marked on the second floor. Starting headers tomorrow.'})
    b = admin.call('board', {'project_id': PROJECT})[1]['data']['board']
    admin.call('update', {'card_id': c['walls'], 'version': card(b, c['walls'])['version'], 'fields': {'due': day(10)}})

    # Board settings: soft WIP limits on two columns.
    admin.call('settings', {'project_id': PROJECT, 'settings': {'columns': {
        str(st['In Progress']): {'wip': 4, 'shown': True}, str(st['Review']): {'wip': 2, 'shown': True}}}})

    return {'cards': c, 'garage': garage, 'garage_cards': g, 'statuses': st, 'electrical': electrical}


# ------------------------------------------------------------------ capture

def main():
    out = HERE
    data = seed()
    c = data['cards']

    with sync_playwright() as p:
        browser = p.chromium.launch()
        page = browser.new_page(viewport=VIEWPORT, device_scale_factor=1)
        page.goto(BASE + '/index.php')
        page.fill('input[name=username]', 'admin')
        page.fill('input[name=password]', ADMIN_PASS)
        page.click('input[type=submit], button[type=submit]')
        page.wait_for_load_state('networkidle')

        def settle():
            page.wait_for_timeout(500)
            page.evaluate('document.activeElement && document.activeElement.blur && document.activeElement.blur()')
            page.mouse.move(5, VIEWPORT['height'] - 5)
            page.wait_for_timeout(400)  # let focus glows fade out

        def shot(name, full=False):
            settle()
            page.screenshot(path=os.path.join(out, name), full_page=full)
            print('  ', name)

        def to_tabs():
            # Scroll so the page's tab bar sits near the top of the shot.
            page.evaluate("""(() => {
                const tab = document.querySelector('a[id^="toptab_"]');
                window.scrollTo(0, tab.getBoundingClientRect().top + window.scrollY - 90);
            })()""")

        def board(project):
            page.goto(BASE + '/index.php?m=cards&project_id=%d' % project)
            page.wait_for_selector('.w2p-cards-column')

        # 1. Board, grouped by status (one project).
        board(PROJECT)
        shot('01-board.png')

        # 2. All projects (stands in for the "My Work" swimlanes wireframe).
        board(-1)
        shot('02-all-projects.png')
        page.select_option('.w2p-cards-toolbar select[aria-label="Group by"]', 'project')
        page.wait_for_selector('.w2p-cards-column h3:has-text("Detached garage")')
        shot('02b-all-projects-by-project.png')
        page.select_option('.w2p-cards-toolbar select[aria-label="Group by"]', 'status')

        # 3. Card detail drawer.
        board(PROJECT)
        page.click('.w2p-cards-card[data-id="%d"]' % c['walls'])
        page.wait_for_selector('.w2p-cards-drawer .w2p-cards-activity')
        shot('03-card-drawer.png')
        # The rest of the drawer: dependencies and the activity log.
        page.evaluate("document.querySelector('.w2p-cards-drawer').scrollTop = 100000")
        shot('03b-card-drawer-activity.png')
        page.keyboard.press('Escape')
        page.wait_for_selector('.w2p-cards-drawer', state='detached')

        # 4. Drag and drop.
        def grab(card_id, column=None):
            if column:
                # Bring the target column fully into view before picking the card up.
                page.locator('.w2p-cards-column', has=page.locator('h3', has_text=column)).scroll_into_view_if_needed()
                page.wait_for_timeout(200)
            src = page.locator('.w2p-cards-card[data-id="%d"]' % card_id)
            box = src.bounding_box()
            x, y = box['x'] + box['width'] / 2, box['y'] + 15
            page.mouse.move(x, y)
            page.mouse.down()
            return x, y

        def move_to(x0, y0, column, dy=60, steps=20):
            lst = page.locator('.w2p-cards-column', has=page.locator('h3', has_text=column)).locator('.w2p-cards-list')
            box = lst.bounding_box()
            x1, y1 = box['x'] + 60, box['y'] + dy
            for i in range(1, steps + 1):
                page.mouse.move(x0 + (x1 - x0) * i / steps, y0 + (y1 - y0) * i / steps)
                page.wait_for_timeout(20)

        # 4a: a card mid-drag between columns.
        x, y = grab(c['trusses'])
        move_to(x, y, 'In Progress', dy=150)
        page.wait_for_timeout(300)
        page.screenshot(path=os.path.join(out, '04a-drag.png'))
        print('   04a-drag.png')
        page.mouse.up()
        page.wait_for_timeout(800)

        # 4b: closing a card that still waits on another.
        x, y = grab(c['flashing'], 'Done')
        move_to(x, y, 'Done')
        page.mouse.up()
        page.wait_for_selector('.w2p-cards-dialog')
        shot('04b-unmet-dependency.png')
        page.click('.w2p-cards-dialog button:has-text("Cancel")')
        page.wait_for_timeout(500)

        # 4c: closing a parent card with open subcards.
        x, y = grab(c['walls'], 'Done')
        move_to(x, y, 'Done')
        page.mouse.up()
        page.wait_for_selector('.w2p-cards-dialog')
        page.wait_for_timeout(200)
        if page.locator('.w2p-cards-dialog:has-text("Still waiting")').count():
            # It also waits on another card: that question comes first.
            shot('04c1-parent-still-waiting.png')
            page.click('.w2p-cards-dialog button:has-text("Move anyway")')
            page.wait_for_selector('.w2p-cards-dialog:has-text("subcards are still open")')
        shot('04c2-parent-open-subcards.png')
        page.click('.w2p-cards-dialog button:has-text("Cancel")')
        page.wait_for_timeout(500)

        # 5. Board settings.
        page.click('.w2p-cards-toolbar button:has-text("Board settings")')
        page.wait_for_selector('.w2p-cards-dialog')
        shot('05-board-settings.png')
        page.click('.w2p-cards-dialog button:has-text("Cancel")')

        # 6. Project view, Cards tab.
        page.goto(BASE + '/index.php?m=projects&a=view&project_id=%d' % PROJECT)
        page.locator('a[id^="toptab_"]', has_text='Cards').click()
        page.wait_for_selector('.w2p-cards.is-compact .w2p-cards-column')
        to_tabs()
        shot('06-project-tab.png')

        # 7. Task view, Cards tab (with a sub-task's cards).
        page.goto(BASE + '/index.php?m=tasks&a=view&task_id=%d' % data['electrical'])
        page.locator('a[id^="toptab_"]', has_text='Cards').click()
        page.wait_for_selector('.w2p-cards-tasktab')
        shot('07-task-tab.png', full=True)

        # Extras: default settings, status list, calendar, search.
        board(-1)
        page.click('.w2p-cards-toolbar button:has-text("Default settings")')
        page.wait_for_selector('.w2p-cards-dialog')
        shot('08-default-settings.png')
        page.click('.w2p-cards-dialog button:has-text("Cancel")')

        page.goto(BASE + '/index.php?m=cards&a=statuses')
        page.wait_for_selector('#w2p-cards-status-rows')
        shot('09-card-statuses.png')

        # The month with Jordan's next due cards (tomorrow on).
        month = (TODAY + datetime.timedelta(days=1)).strftime('%Y%m01')
        page.goto(BASE + '/index.php?m=events&date=%s' % month)
        page.wait_for_selector('a[href^="?m=cards&card="]')
        shot('10-calendar.png')

        page.goto(BASE + '/index.php?m=smartsearch')
        page.fill('#keyword1', 'wall')
        page.locator('#keyword1').press('Enter')
        page.wait_for_selector('a[href*="m=cards&card="]')
        shot('11-smart-search.png')

        browser.close()


if __name__ == '__main__':
    main()
