"""End-to-end checks of the Cards JSON API against a running web2project.

Uses the web2project demo data (dev/demo-data/setup.sh in web2project): the
"Build Family Home" project (id 1), its tasks, the admin user and the
"dwhitfield" project worker.

    W2P_DIR=/path/to/web2project python3 tests/api_test.py

Needs: requests, the `mysql` client with access to the w2p database, and
the Cards module installed and active.
"""
import html
import json
import os
import re
import subprocess
import sys

import requests

BASE = os.environ.get('W2P_URL', 'http://127.0.0.1:8080')
INDEX = BASE + '/index.php'
DB = os.environ.get('W2P_DB', 'w2p')
PROJECT = int(os.environ.get('W2P_PROJECT', '1'))

failures = []


def check(ok, message):
    print(('  ok   ' if ok else '  FAIL ') + message)
    if not ok:
        failures.append(message)


def sql(query):
    out = subprocess.check_output(['mysql', DB, '-N', '-B', '-e', query]).decode()
    return [line.split('\t') for line in out.strip().splitlines() if line]


class Client:
    def __init__(self, username, password):
        self.s = requests.Session()
        self.s.get(INDEX)
        r = self.s.post(INDEX, data=dict(login='login', username=username, password=password, redirect='', lostpass='0'))
        assert 'logout' in r.text.lower(), 'login failed for ' + username
        page = self.s.get(INDEX + '?m=cards&project_id=%d' % PROJECT).text
        m = re.search(r'class="w2p-cards" data-options="([^"]*)"', page)
        self.options = json.loads(html.unescape(m.group(1))) if m else None

    def call(self, op, payload=None, nonce=True):
        data = {'op': op, 'payload': json.dumps(payload or {})}
        if nonce:
            data['__nonce'] = self.options['nonce']
        r = self.s.post(INDEX + '?m=cards&a=do_card_json&suppressHeaders=1', data=data, allow_redirects=False)
        try:
            body = r.json()
        except ValueError:
            body = None
        return r.status_code, body


def card(board, card_id):
    for c in board['cards']:
        if c['id'] == card_id:
            return c
    return None


def main():
    print('== admin')
    admin = Client('admin', os.environ.get('W2P_ADMIN_PASS', 'passw0rd'))
    check(admin.options is not None, 'board page mounts with options')
    code, body = admin.call('board', {'project_id': PROJECT})
    check(code == 200 and body['ok'], 'board loads')
    board = body['data']['board']
    statuses = {s['name']: s['id'] for s in board['statuses']}
    check(list(statuses) == ['Backlog', 'To Do', 'In Progress', 'Review', 'Done'], 'default global statuses')
    tasks = board['tasks']
    check(len(tasks) > 3, 'visible tasks listed for linking (%d)' % len(tasks))
    short = board['project']['short_name']

    print('== create')
    created = {}
    for name, status in (('Order windows', 'To Do'), ('Frame walls', 'In Progress'), ('Pour slab', 'Done'), ('Pick paint', 'Backlog')):
        code, body = admin.call('create', {'project_id': PROJECT, 'fields': {'name': name, 'status': statuses[status]}})
        check(code == 200 and body['data'].get('created'), 'create "%s"' % name)
        created[name] = body['data']['created']
    a, b, c, d = created['Order windows'], created['Frame walls'], created['Pour slab'], created['Pick paint']
    board = body['data']['board']
    check(card(board, a)['key'] == '%s-%d' % (short, a), 'key is short name + card id (%s)' % card(board, a)['key'])
    code, body = admin.call('create', {'project_id': PROJECT, 'fields': {'name': ''}})
    check(code == 400 and 'name' in body['error'].get('fields', {}), 'empty name rejected')
    code, body = admin.call('create', {'project_id': PROJECT, 'fields': {'name': 'x', 'status': 999}})
    check(code == 400, 'unknown status rejected')
    code, body = admin.call('create', {'project_id': PROJECT, 'parent': a, 'fields': {'name': 'Measure openings', 'status': statuses['To Do']}})
    sub = body['data']['created']
    check(card(body['data']['board'], sub)['parent'] == a, 'subcard created under its parent')
    check(card(body['data']['board'], a)['subcards'] == 1, 'parent counts its subcard')

    print('== update and conflicts')
    v = card(body['data']['board'], a)['version']
    task_id = tasks[0]['id']
    code, body = admin.call('update', {'card_id': a, 'version': v, 'fields': {'priority': 1, 'due': '2026-12-01', 'task': task_id, 'description': 'Triple pane'}, 'open_card': a})
    check(code == 200, 'update fields')
    ca = card(body['data']['board'], a)
    check(ca['priority'] == 1 and ca['due'] == '2026-12-01' and ca['task'] == task_id, 'fields saved')
    check(body['data']['card']['description'] == 'Triple pane', 'open card returned with the change')
    code, body = admin.call('update', {'card_id': a, 'version': v, 'fields': {'name': 'stale'}})
    check(code == 409 and body['error']['code'] == 'conflict' and 'board' in body.get('data', {}), 'stale version gives 409 with a fresh board')
    code, body = admin.call('update', {'card_id': a, 'version': ca['version'], 'fields': {'due': ''}})
    check(code == 200 and card(body['data']['board'], a)['due'] == '', 'due date cleared back to none')
    code, body = admin.call('update', {'card_id': a, 'version': card(body['data']['board'], a)['version'], 'fields': {'due': '2026-02-30'}})
    check(code == 400, 'impossible date rejected')
    other_task = sql('select task_id from tasks where task_project <> %d limit 1' % PROJECT)
    if other_task:
        code, body = admin.call('update', {'card_id': a, 'version': ca['version'] + 1, 'fields': {'task': int(other_task[0][0])}})
        check(code == 400, 'task from another project rejected')

    print('== move and order')
    code, body = admin.call('board', {'project_id': PROJECT})
    board = body['data']['board']
    cb = card(board, b)
    # Move b to To Do, between a and sub (both To Do).
    code, body = admin.call('move', {'card_id': b, 'version': cb['version'], 'group': 'status', 'value': statuses['To Do'], 'before': a, 'after': sub})
    check(code == 200, 'move into another column')
    board = body['data']['board']
    todo = [x['id'] for x in board['cards'] if x['status'] == statuses['To Do']]
    check(todo.index(a) < todo.index(b) < todo.index(sub), 'card lands between its neighbours (%s)' % todo)
    # Reorder within the column: b to the top.
    code, body = admin.call('move', {'card_id': b, 'version': card(board, b)['version'], 'group': 'status', 'value': None, 'before': 0, 'after': todo[0]})
    board = body['data']['board']
    todo = [x['id'] for x in board['cards'] if x['status'] == statuses['To Do']]
    check(todo[0] == b, 'reorder within a column')
    code, body = admin.call('move', {'card_id': d, 'version': card(board, d)['version'], 'group': 'priority', 'value': -1, 'before': 0, 'after': 0})
    check(card(body['data']['board'], d)['priority'] == -1, 'move by priority grouping sets priority')

    print('== dependencies and loops')
    code, body = admin.call('dependency_add', {'card_id': a, 'requires': b})
    check(code == 200, 'a waits on b')
    check([w['id'] for w in card(body['data']['board'], a)['waits_on']] == [b], 'waits-on badge lists b')
    code, body = admin.call('dependency_add', {'card_id': b, 'requires': d})
    check(code == 200, 'b waits on d')
    code, body = admin.call('dependency_add', {'card_id': d, 'requires': a})
    check(code == 400 and 'loop' in body['error']['message'], 'd waits on a refused: loop through b (%s)' % body['error']['message'])
    code, body = admin.call('dependency_add', {'card_id': a, 'requires': a})
    check(code == 400, 'card can\'t wait on itself')
    code, body = admin.call('dependency_add', {'card_id': c, 'requires': d})
    board = body['data']['board']
    check(card(board, c)['waits_on'] and card(board, c)['waits_on'][0]['id'] == d, 'closed card can wait on an open one')

    print('== subcards and parent loops')
    code, body = admin.call('parent', {'card_id': a, 'parent': sub})
    check(code == 400, 'card can\'t go under its own subcard')
    code, body = admin.call('parent', {'card_id': d, 'parent': a})
    check(code == 200 and card(body['data']['board'], d)['parent'] == a, 're-parent d under a')
    code, body = admin.call('parent', {'card_id': d, 'parent': 0})
    check(card(body['data']['board'], d)['parent'] == 0, 'detach d')

    print('== close parent with open subcards')
    board = body['data']['board']
    code, body = admin.call('move', {'card_id': a, 'version': card(board, a)['version'], 'group': 'status', 'value': statuses['Done'], 'before': 0, 'after': 0, 'close_subcards': 1})
    board = body['data']['board']
    check(card(board, a)['status'] == statuses['Done'] and card(board, sub)['status'] == statuses['Done'], 'closing parent with "and subcards" closes the subcard')
    check(card(board, a)['subcards_done'] == 1, 'parent shows its subcard done')

    print('== tags')
    code, body = admin.call('tag_add', {'card_id': b, 'name': 'Framing'})
    code, body = admin.call('tag_add', {'card_id': c, 'name': '  framing '})
    board = body['data']['board']
    framing = [t for t in board['tags'] if t['name'].lower() == 'framing']
    check(len(framing) == 1 and card(board, c)['tags'] == [framing[0]['id']], 'tags unique by slug, first spelling kept (%s)' % framing)
    code, body = admin.call('tag_add', {'card_id': c, 'name': 'Concrete'})
    concrete = [t['id'] for t in body['data']['board']['tags'] if t['name'] == 'Concrete'][0]
    board = body['data']['board']
    code, body = admin.call('move', {'card_id': c, 'version': card(board, c)['version'], 'group': 'tag', 'value': concrete, 'from_tag': framing[0]['id'], 'before': 0, 'after': 0})
    check(card(body['data']['board'], c)['tags'] == [concrete], 'moving between tag columns swaps the tag')
    code, body = admin.call('tag_remove', {'card_id': c, 'tag': concrete})
    check(card(body['data']['board'], c)['tags'] == [], 'tag removed')

    print('== comments and activity')
    code, body = admin.call('comment', {'card_id': b, 'text': 'Lumber arrives Tuesday <b>bold?</b>', 'open_card': b})
    acts = body['data']['card']['activity']
    check(acts[0]['type'] == 'comment' and '<b>' in acts[0]['new'], 'comment stored as typed (escaped on output)')
    types = {x['type'] for x in acts}
    check({'created', 'changed', 'dependency_added', 'tag_added'} <= types, 'activity has created/changed/dependency/tag entries')
    moves = [x for x in acts if x['type'] == 'changed' and x['field'] == 'status']
    check(moves and moves[-1]['old'] == 'In Progress' and moves[-1]['new'] == 'To Do', 'status change logged with names')

    print('== board settings')
    settings = {'columns': {str(statuses['In Progress']): {'wip': 2, 'shown': True}, str(statuses['Backlog']): {'wip': 0, 'shown': False}}, 'fields': {'due': False}}
    code, body = admin.call('settings', {'project_id': PROJECT, 'settings': settings})
    s = body['data']['board']['settings']
    check(s['columns'][str(statuses['In Progress'])]['wip'] == 2 and s['columns'][str(statuses['Backlog'])]['shown'] is False and s['fields']['due'] is False and s['fields']['owner'] is True, 'settings saved and normalized')

    print('== delete')
    code, body = admin.call('delete', {'card_id': a, 'open_card': b})
    check(code == 200 and sorted(body['data']['deleted']) == sorted([a, sub]), 'deleting a card deletes its subcards')
    check(card(body['data']['board'], a) is None, 'deleted card gone from board')
    acts = body['data']['card']['activity']
    check(any(x['type'] == 'dependency_removed' and x['field'] == 'blocks' for x in acts), 'surviving card logs the removed dependency')
    check(int(sql('select count(*) from card_activity where activity_card = %d' % a)[0][0]) == 0, 'deleted card\'s activity removed')

    print('== CSRF and methods')
    code, body = admin.call('board', {'project_id': PROJECT}, nonce=False)
    check(body is None and code in (302, 303), 'request without the form token is refused (%s)' % code)
    r = admin.s.get(INDEX + '?m=cards&a=do_card_json&suppressHeaders=1&op=board', allow_redirects=False)
    check(r.status_code != 200 or 'ok' not in r.text, 'GET is not served')

    print('== permissions')
    private_task = int(sql('select task_id from tasks where task_project = %d order by task_id desc limit 1' % PROJECT)[0][0])
    admin_id = sql("select user_id from users where user_username = 'admin'")[0][0]
    sql('update tasks set task_access = 3, task_owner = %s where task_id = %d' % (admin_id, private_task))
    code, body = admin.call('create', {'project_id': PROJECT, 'fields': {'name': 'Secret card', 'status': statuses['To Do'], 'task': private_task}})
    secret = body['data']['created']
    worker = Client('dwhitfield', os.environ.get('W2P_WORKER_PASS', 'buildit2026'))
    check(worker.options is not None, 'project worker can open the board')
    code, body = worker.call('board', {'project_id': PROJECT})
    check(code == 200, 'worker loads the board')
    if code == 200:
        wboard = body['data']['board']
        check(card(wboard, secret) is None, 'card on a private task is hidden from others')
        check(card(wboard, b) is not None, 'other cards visible to the worker')
        check(private_task not in [t['id'] for t in wboard['tasks']], 'private task not offered for linking')
    code, body = worker.call('card', {'card_id': secret})
    check(code == 404, 'hidden card can\'t be opened directly')
    code, body = worker.call('update', {'card_id': secret, 'version': 1, 'fields': {'name': 'pwned'}})
    check(code in (403, 404), 'hidden card can\'t be changed')
    code, body = worker.call('settings', {'project_id': PROJECT, 'settings': {}})
    check(code == 403 or (code == 200), 'board settings need project edit rights (got %s)' % code)

    print()
    print('%d failure(s)' % len(failures) if failures else 'all API checks passed')
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
