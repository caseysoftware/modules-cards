"""Checks the all-projects board, the default board settings, Smart Search
and the calendar hooks, against the web2project demo data.

    W2P_DIR=/path/to/web2project python3 tests/allview_test.py

Run after api_test.py (it reuses the private task that test sets up). It
adds a second project through web2project's own form if there isn't one.
"""
import datetime
import os
import re
import secrets
import sys

sys.path.insert(0, os.path.dirname(__file__))
from api_test import Client, check, failures, sql, card, PROJECT, INDEX, BASE  # noqa: E402

W2P_DIR = os.environ.get('W2P_DIR', os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..')))
sys.path.insert(0, os.path.join(W2P_DIR, 'dev', 'demo-data'))
from w2p_client import W2P  # noqa: E402

ALL = -1


def ids(board):
    return {c['id'] for c in board['cards']}


def main():
    admin = Client('admin', os.environ.get('W2P_ADMIN_PASS', 'passw0rd'))
    worker = Client('dwhitfield', os.environ.get('W2P_WORKER_PASS', 'buildit2026'))
    statuses = {s['name']: s['id'] for s in admin.call('board', {'project_id': PROJECT})[1]['data']['board']['statuses']}

    print('== setup: a second project')
    other = sql("select project_id from projects where project_short_name = 'Shed'")
    if not other:
        company = sql('select project_company from projects where project_id = %d' % PROJECT)[0][0]
        W2P().login().submit('m=projects&a=addedit', dict(
            project_name='Backyard shed', project_short_name='Shed', project_company=company,
            project_status='3', project_start_date='20261101', start_date='01/Nov/2026',
            project_end_date='20270301', end_date='01/Mar/2027', project_color_identifier='5577AA'))
        other = sql("select project_id from projects where project_short_name = 'Shed'")
    other = int(other[0][0])
    tag = secrets.token_hex(3)
    today = datetime.date.today().isoformat()

    def create(project, name, status='To Do', **fields):
        fields.update({'name': name, 'status': statuses[status]})
        code, body = admin.call('create', {'project_id': project, 'fields': fields})
        assert code == 200, body
        return body['data']['created']

    home = create(PROJECT, 'Seal the deck %s' % tag, due=today, owner=1)
    worker_id = int(sql("select user_id from users where user_username = 'dwhitfield'")[0][0])
    shed = create(other, 'Pour shed pad %s' % tag, due=today, owner=worker_id)
    shed2 = create(other, 'Order shed kit %s' % tag)
    private_task = int(sql('select task_id from tasks where task_project = %d and task_access = 3 limit 1' % PROJECT)[0][0])
    secret = create(PROJECT, 'Secret inspection %s' % tag, task=private_task)

    print('== all-projects board')
    code, body = admin.call('board', {'project_id': ALL})
    check(code == 200 and body['data']['board']['all'] is True, 'all-projects board loads')
    board = body['data']['board']
    check({home, shed, shed2, secret} <= ids(board), 'shows cards from every project')
    check({p['id'] for p in board['projects']} >= {PROJECT, other}, 'lists the visible projects')
    check(card(board, shed)['project_name'] == 'Backyard shed' and card(board, shed)['key'] == 'Shed-%d' % shed, 'cards carry their project')
    order = [c['project_name'] for c in board['cards']]
    check(order == sorted(order, key=lambda n: n), 'cards grouped by project name within the board')
    check(board['tasks'] == [], 'no task list on the all-projects board')

    print('== permissions on the all-projects board')
    code, body = worker.call('board', {'project_id': ALL})
    wboard = body['data']['board']
    check(code == 200 and home in ids(wboard) and shed in ids(wboard), 'worker sees visible cards from every project')
    check(secret not in ids(wboard), 'card on a private task stays hidden')

    print('== waiting-on names')
    admin.call('dependency_add', {'card_id': home, 'requires': secret})
    b = admin.call('board', {'project_id': PROJECT})[1]['data']['board']
    check([w['name'] for w in card(b, home)['waits_on']] == ['Secret inspection %s' % tag], 'waiting-on lists the blocking card by name')
    wb = worker.call('board', {'project_id': PROJECT})[1]['data']['board']
    w = card(wb, home)['waits_on']
    check(len(w) == 1 and w[0].get('hidden') == 1 and not w[0]['name'] and not w[0]['id'], 'a blocker on a private task is counted, not named')
    check('Secret inspection' not in str(wb), 'its name appears nowhere in the worker\'s board')
    admin.call('dependency_remove', {'card_id': home, 'requires': secret})

    print('== moves on the all-projects board')
    c = card(board, shed)
    code, body = admin.call('move', {'board': ALL, 'card_id': shed, 'version': c['version'], 'group': 'status',
                                      'value': statuses['In Progress'], 'before': 0, 'after': 0})
    check(code == 200 and body['data']['board']['all'] is True, 'a change answers with the all-projects board')
    moved = card(body['data']['board'], shed)
    check(moved['status'] == statuses['In Progress'] and moved['project'] == other, 'status changes, project stays')
    code, body = admin.call('move', {'board': ALL, 'card_id': shed, 'version': moved['version'], 'group': 'project',
                                      'value': PROJECT, 'before': 0, 'after': 0})
    check(code == 400 and 'between projects' in body['error']['message'], 'moving to another project is refused')
    check(int(sql('select card_project from cards where card_id = %d' % shed)[0][0]) == other, 'and the card stayed put')
    code, body = admin.call('dependency_add', {'board': ALL, 'card_id': shed, 'requires': home})
    check(code == 400 and 'same project' in body['error']['message'], 'no relationships across projects')
    code, body = admin.call('create', {'board': ALL, 'project_id': other, 'fields': {'name': 'Shed door %s' % tag, 'status': statuses['Backlog']}})
    check(code == 200 and body['data']['board']['all'] is True and body['data']['created'] in ids(body['data']['board']), 'create from the all-projects board')
    check('card' not in body['data'], 'creating doesn\'t open the new card')

    print('== default settings')
    code, body = worker.call('settings', {'project_id': 0, 'settings': {'fields': {'owner': False}}})
    check(code == 403, 'only administrators change the defaults')
    code, body = admin.call('settings', {'board': ALL, 'project_id': 0, 'settings': {'fields': {'owner': False, 'project': True}, 'columns': {str(statuses['Review']): {'wip': 3, 'shown': False}}}})
    check(code == 200 and body['data']['board']['settings']['fields']['owner'] is False, 'administrator saves the defaults')
    check(body['data']['board']['settings']['columns'][str(statuses['Review'])]['shown'] is False, 'defaults hide a column on the all-projects board')
    s = admin.call('board', {'project_id': other})[1]['data']['board']['settings']
    check(s['fields']['owner'] is False and s['columns'][str(statuses['Review'])]['wip'] == 3, 'a project without its own settings uses the defaults')
    sql('delete from card_settings where settings_project = %d' % PROJECT)
    admin.call('settings', {'project_id': PROJECT, 'settings': {'fields': {'owner': True}}})
    s = admin.call('board', {'project_id': PROJECT})[1]['data']['board']['settings']
    check(s['fields']['owner'] is True and s['columns'][str(statuses['Review'])]['shown'] is False, 'a project\'s own value wins; the rest comes from the defaults')
    admin.call('settings', {'project_id': 0, 'settings': {}})
    sql('delete from card_settings where settings_project = %d' % PROJECT)

    print('== Smart Search')
    def search(client, word):
        page = client.s.get(INDEX + '?m=smartsearch').text
        nonce = re.search(r'name="__nonce" value="([0-9a-f]+)"', page)
        data = {'keyword1': word}
        if nonce:
            data['__nonce'] = nonce.group(1)
        return client.s.post(INDEX + '?m=smartsearch', data=data).text
    page = search(admin, tag)
    links = set(int(x) for x in re.findall(r'm=cards&amp;card=(\d+)', page))
    check({home, shed, secret} <= links, 'admin finds the cards by name (%d results)' % len(links))
    page = search(worker, tag)
    links = set(int(x) for x in re.findall(r'm=cards&amp;card=(\d+)', page))
    check(home in links and secret not in links, 'worker\'s results leave out the card on a private task')
    page = search(admin, 'no-such-card-%s' % tag)
    check('m=cards&amp;card=' not in page, 'no false hits')

    print('== calendar')
    token = secrets.token_hex(16)
    sql("delete from user_feeds where feed_user = 1")
    sql("insert into user_feeds (feed_user, feed_token) values (1, '%s')" % token)
    ical = admin.s.get(BASE + '/calendar.php?token=%s' % token).text
    check('UID:cards_%d' % home in ical and 'Seal the deck %s' % tag in ical, 'feed has the user\'s card due today')
    check('UID:cards_%d' % shed not in ical, 'feed leaves out cards owned by someone else')
    month = admin.s.get(INDEX + '?m=events').text
    check('m=cards&card=%d"' % home in month and 'Seal the deck %s' % tag in month, 'month view shows the card on its due date')
    check('Pour shed pad %s' % tag not in month, 'only the user\'s own cards')
    page = worker.s.get(INDEX + '?m=events').text
    check('Pour shed pad %s' % tag in page and 'Seal the deck %s' % tag not in page, 'each user sees their own cards on the calendar')
    sql("update cards set card_name = '<b>Bold %s</b>' where card_id = %d" % (tag, home))
    month = admin.s.get(INDEX + '?m=events').text
    check('&lt;b&gt;Bold %s&lt;/b&gt;' % tag in month and '<b>Bold %s</b>' % tag not in month, 'card names are escaped on the calendar')
    done = card(admin.call('board', {'project_id': PROJECT})[1]['data']['board'], home)
    admin.call('move', {'card_id': home, 'version': done['version'], 'group': 'status', 'value': statuses['Done'], 'before': 0, 'after': 0})
    month = admin.s.get(INDEX + '?m=events').text
    check('m=cards&card=%d"' % home not in month, 'closed cards drop off the calendar')

    print()
    print('%d failure(s)' % len(failures) if failures else 'all all-projects, search and calendar checks passed')
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
