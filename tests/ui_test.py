"""Browser checks of the board (Playwright + Chromium) against the
web2project demo data. Run after a fresh demo-data install with the module
installed and active:

    python3 tests/ui_test.py [--shots DIR]

Drags cards with the mouse, uses the drawer and dialogs, checks WIP colors,
the Project and Task tabs, keyboard access, that card text can't inject
markup, and that the browser console stays free of errors.
"""
import argparse
import os
import sys

from playwright.sync_api import sync_playwright

sys.path.insert(0, os.path.dirname(__file__))
from api_test import Client, check, failures, sql, card, PROJECT, BASE  # noqa: E402

PASSWORD = os.environ.get('W2P_ADMIN_PASS', 'passw0rd')


def drag(page, source, target):
    """Drags with real mouse steps (Playwright's drag_to skips the events
    SortableJS listens for). Scrolls the card into view and drops it into
    the target list at about the same height."""
    source.scroll_into_view_if_needed()
    src = source.bounding_box()
    dst = target.bounding_box()
    x0, y0 = src['x'] + 20, src['y'] + 12
    x1 = dst['x'] + 40
    y1 = min(max(y0, dst['y'] + 8), dst['y'] + dst['height'] - 8)
    page.mouse.move(x0, y0)
    page.mouse.down()
    for i in range(1, 21):
        page.mouse.move(x0 + (x1 - x0) * i / 20, y0 + (y1 - y0) * i / 20)
        page.wait_for_timeout(25)
    page.mouse.up()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--shots', default='')
    args = parser.parse_args()
    shots = args.shots
    if shots:
        os.makedirs(shots, exist_ok=True)

    api = Client('admin', PASSWORD)
    board = api.call('board', {'project_id': PROJECT})[1]['data']['board']
    statuses = {s['name']: s['id'] for s in board['statuses']}
    tasks = board['tasks']

    # Sample cards through the API, so the browser has something to work with.
    def create(name, status, **fields):
        fields.update({'name': name, 'status': statuses[status]})
        return api.call('create', {'project_id': PROJECT, 'fields': fields})[1]['data']['created']
    c1 = create('Confirm truss delivery', 'To Do', priority=1, due='2026-11-12', task=tasks[5]['id'])
    c2 = create('Order window flashing', 'To Do', task=tasks[7]['id'])
    c3 = create('Frame interior walls', 'In Progress', priority=1, task=tasks[5]['id'])
    c4 = create('Moisture test on slab', 'Review', task=tasks[4]['id'])
    c5 = create('Pour footings', 'Done', task=tasks[3]['id'])
    evil = create('<img src=x onerror="window.__xss=1">Evil', 'Backlog')
    api.call('create', {'project_id': PROJECT, 'parent': c3, 'fields': {'name': 'Mark stud layout', 'status': statuses['In Progress']}})
    api.call('dependency_add', {'card_id': c2, 'requires': c4})
    api.call('tag_add', {'card_id': c1, 'name': 'Framing'})
    api.call('tag_add', {'card_id': c3, 'name': 'Framing'})

    with sync_playwright() as p:
        browser = p.chromium.launch()
        page = browser.new_page(viewport={'width': 1600, 'height': 2000})
        errors = []
        page.on('console', lambda m: errors.append(m.text) if m.type == 'error' else None)
        page.on('pageerror', lambda e: errors.append(str(e)))
        page.on('dialog', lambda d: d.dismiss())

        page.goto(BASE + '/index.php')
        page.fill('input[name=username]', 'admin')
        page.fill('input[name=password]', PASSWORD)
        page.click('input[type=submit], button[type=submit]')
        page.wait_for_load_state('networkidle')

        print('== board')
        page.goto(BASE + '/index.php?m=cards&project_id=%d' % PROJECT)
        page.wait_for_selector('.w2p-cards-column')
        headers = page.locator('.w2p-cards-column-header h3').all_inner_texts()
        check(headers == ['Backlog', 'To Do', 'In Progress', 'Review', 'Done'], 'columns from the global status list')
        check(page.locator('.w2p-cards-card[data-id="%d"] .w2p-cards-blocked' % c2).inner_text().startswith('Waiting on'), 'waiting-on badge shown')
        evil_text = page.locator('.w2p-cards-card[data-id="%d"] .w2p-cards-name' % evil).inner_text()
        check('<img' in evil_text and page.evaluate('window.__xss') is None, 'card text is shown as text, not markup')
        if shots:
            page.screenshot(path=os.path.join(shots, '1-board.png'), full_page=True)

        print('== inline add')
        todo = page.locator('.w2p-cards-column', has=page.locator('h3', has_text='To Do'))
        todo.locator('.w2p-cards-add').click()
        lift = 'Rent a scissor lift %d' % c1
        page.fill('.w2p-cards-inline', lift)
        page.keyboard.press('Enter')
        page.wait_for_selector('.w2p-cards-card:has-text("%s")' % lift)
        check(True, 'card added inline in its column')

        print('== drag and drop')
        src = page.locator('.w2p-cards-card[data-id="%d"]' % c1)
        target_list = page.locator('.w2p-cards-column', has=page.locator('h3', has_text='In Progress')).locator('.w2p-cards-list')
        drag(page, src, target_list)
        page.wait_for_timeout(800)
        board = api.call('board', {'project_id': PROJECT})[1]['data']['board']
        check(card(board, c1)['status'] == statuses['In Progress'], 'dragging to another column changes the status on the server')
        in_progress = [c['id'] for c in board['cards'] if c['status'] == statuses['In Progress']]
        shown = [int(x) for x in target_list.locator('.w2p-cards-card').evaluate_all('els => els.map(e => e.dataset.id)')]
        check(in_progress == shown, 'and keeps it where it was dropped (screen order matches the server)')

        print('== dependency warning')
        src = page.locator('.w2p-cards-card[data-id="%d"]' % c2)
        done_list = page.locator('.w2p-cards-column', has=page.locator('h3', has_text='Done')).locator('.w2p-cards-list')
        drag(page, src, done_list)
        page.wait_for_selector('.w2p-cards-dialog')
        check('Still waiting on' in page.locator('.w2p-cards-dialog').inner_text(), 'closing a card that waits on another asks first')
        if shots:
            page.screenshot(path=os.path.join(shots, '2-dependency-warning.png'))
        page.click('.w2p-cards-dialog button:has-text("Cancel")')
        page.wait_for_timeout(300)
        check(page.locator('.w2p-cards-column', has=page.locator('h3', has_text='To Do')).locator('.w2p-cards-card[data-id="%d"]' % c2).count() == 1, 'cancel puts it back')
        board = api.call('board', {'project_id': PROJECT})[1]['data']['board']
        check(card(board, c2)['status'] == statuses['To Do'], 'and nothing changed on the server')

        print('== parent with open subcards')
        src = page.locator('.w2p-cards-card[data-id="%d"]' % c3)
        drag(page, src, done_list)
        page.wait_for_selector('.w2p-cards-dialog')
        check('subcards are still open' in page.locator('.w2p-cards-dialog').inner_text(), 'closing a parent asks about its open subcards')
        page.check('.w2p-cards-dialog input[value=all]')
        page.click('.w2p-cards-dialog button:has-text("Move")')
        page.wait_for_timeout(800)
        board = api.call('board', {'project_id': PROJECT})[1]['data']['board']
        subs = [c for c in board['cards'] if c['parent'] == c3]
        check(card(board, c3)['status'] == statuses['Done'] and subs and subs[0]['status'] == statuses['Done'], 'parent and open subcard closed together')

        print('== drawer')
        page.click('.w2p-cards-card[data-id="%d"]' % c4)
        page.wait_for_selector('.w2p-cards-drawer')
        page.select_option('.w2p-cards-drawer .w2p-cards-field:has-text("Priority") select', '1')
        page.wait_for_timeout(600)
        page.fill('.w2p-cards-drawer textarea[aria-label=Comment]', 'Reading was 3.1 lbs. Good to go.')
        page.click('.w2p-cards-drawer button:has-text("Comment")')
        page.wait_for_selector('.w2p-cards-activity.is-comment')
        text = page.locator('.w2p-cards-drawer').inner_text()
        check('Reading was 3.1 lbs' in text and 'changed the priority from "Normal" to "High"' in text, 'drawer edits show up in the activity')
        page.fill('.w2p-cards-drawer input[aria-label="Add tag"]', 'Inspection')
        page.keyboard.press('Enter')
        page.wait_for_selector('.w2p-cards-drawer .w2p-cards-tag:has-text("Inspection")')
        check(True, 'tag added from the drawer')
        if shots:
            page.screenshot(path=os.path.join(shots, '3-drawer.png'))
        page.keyboard.press('Escape')
        page.wait_for_timeout(200)
        check(page.locator('.w2p-cards-drawer').count() == 0, 'Escape closes the drawer')

        print('== keyboard')
        page.focus('.w2p-cards-card[data-id="%d"]' % c5)
        page.keyboard.press('Enter')
        page.wait_for_selector('.w2p-cards-drawer')
        check(True, 'Enter on a focused card opens it')
        page.select_option('.w2p-cards-drawer .w2p-cards-field:has-text("Status") select', str(statuses['Review']))
        page.wait_for_timeout(800)
        board = api.call('board', {'project_id': PROJECT})[1]['data']['board']
        check(card(board, c5)['status'] == statuses['Review'], 'status menu moves the card without dragging')
        page.keyboard.press('Escape')

        print('== WIP limits')
        board = api.call('board', {'project_id': PROJECT})[1]['data']['board']
        count = lambda name: len([c for c in board['cards'] if c['status'] == statuses[name]])
        page.click('button:has-text("Board settings")')
        page.fill('.w2p-cards-dialog input[aria-label="WIP limit for To Do"]', str(count('To Do') + 1))
        page.fill('.w2p-cards-dialog input[aria-label="WIP limit for In Progress"]', str(count('In Progress')))
        page.fill('.w2p-cards-dialog input[aria-label="WIP limit for Review"]', str(max(1, count('Review') - 1)))
        page.click('.w2p-cards-dialog button:has-text("Save")')
        page.wait_for_selector('.w2p-cards-toast:has-text("Board settings saved")')
        check(page.locator('.w2p-cards-wip.is-over').count() == 1 and page.locator('.w2p-cards-wip.is-at').count() == 1
              and page.locator('.w2p-cards-wip.is-under').count() == 1, 'WIP badges: under, at limit, over')
        if shots:
            page.screenshot(path=os.path.join(shots, '4-wip.png'), full_page=True)

        print('== group by')
        page.select_option('.w2p-cards-toolbar select[aria-label="Group by"]', 'tag')
        page.wait_for_selector('.w2p-cards-column h3:has-text("Framing")')
        check(page.locator('.w2p-cards-column h3').first.inner_text() == 'No tag', 'grouping by tag')
        page.select_option('.w2p-cards-toolbar select[aria-label="Group by"]', 'status')

        print('== project tab')
        page.goto(BASE + '/index.php?m=projects&a=view&project_id=%d' % PROJECT)
        page.locator('a[id^="toptab_"]', has_text='Cards').click()
        page.wait_for_selector('.w2p-cards.is-compact .w2p-cards-column')
        check(page.locator('.w2p-cards.is-compact .w2p-cards-card').count() >= 6, 'Cards tab on the project view shows the board')
        if shots:
            page.screenshot(path=os.path.join(shots, '5-project-tab.png'), full_page=True)

        print('== task tab')
        page.goto(BASE + '/index.php?m=tasks&a=view&task_id=%d' % tasks[5]['id'])
        page.locator('a[id^="toptab_"]', has_text='Cards').click()
        page.wait_for_selector('.w2p-cards-tasktab')
        rows = page.locator('.w2p-cards-tasktable tbody tr').all_inner_texts()
        check(any('Confirm truss delivery' in r for r in rows) and any('Frame interior walls' in r for r in rows), 'Cards tab on the task view lists its cards')
        if shots:
            page.screenshot(path=os.path.join(shots, '6-task-tab.png'), full_page=True)

        print('== console')
        check(not errors, 'no browser errors (%s)' % errors[:3])
        browser.close()

    print()
    print('%d failure(s)' % len(failures) if failures else 'all UI checks passed')
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
