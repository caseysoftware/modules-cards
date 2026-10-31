"""Checks the consistency rules (modules-cards #3) through web2project's own
forms and the cron sweep (queuescanner.php -> CCard::hook_cron).

Run after api_test.py, against the web2project demo data:

    W2P_DIR=/path/to/web2project python3 tests/sweep_test.py

It moves a task (with a subtask) to another project and deletes a task
using the normal task forms, checks "validate links on read" before the
sweep, runs the sweep, and checks the cards, relationships and activity.
Finally it deletes the second project and checks its cards go too.
"""
import os
import subprocess
import sys

sys.path.insert(0, os.path.dirname(__file__))
from api_test import Client, check, failures, sql, card, PROJECT  # noqa: E402

# Default: this module installed as web2project/modules/cards.
W2P_DIR = os.environ.get('W2P_DIR', os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..')))
sys.path.insert(0, os.path.join(W2P_DIR, 'dev', 'demo-data'))
from w2p_client import W2P  # noqa: E402


def sweep():
    subprocess.check_call(['php', 'queuescanner.php'], cwd=W2P_DIR, stdout=subprocess.DEVNULL)


def ids(board):
    return {c['id'] for c in board['cards']}


def main():
    ui = W2P().login()
    admin = Client('admin', os.environ.get('W2P_ADMIN_PASS', 'passw0rd'))
    statuses = {s['name']: s['id'] for s in admin.call('board', {'project_id': PROJECT})[1]['data']['board']['statuses']}
    todo = statuses['To Do']

    print('== setup through the web2project forms')
    company = sql('select project_company from projects where project_id = %d' % PROJECT)[0][0]
    ui.submit('m=projects&a=addedit', dict(
        project_name='Garage addition', project_short_name='Garage', project_company=company,
        project_status='3', project_start_date='20261101', start_date='01/Nov/2026',
        project_end_date='20270301', end_date='01/Mar/2027', project_color_identifier='AA6633'))
    other = int(sql("select project_id from projects where project_short_name = 'Garage'")[0][0])
    moving_task = int(sql("select task_id from tasks where task_project = %d and task_name like 'Rough-in electrical%%'" % PROJECT)[0][0])
    ui.submit('m=tasks&a=addedit&task_project=%d' % PROJECT, dict(
        task_name='Panel upgrade', task_project=PROJECT, new_task_project=PROJECT, task_parent=moving_task,
        task_access='0', task_status='0', task_start_date='202611020800', task_end_date='202611031700',
        start_hour='08', start_minute='00', end_hour='17', end_minute='00', task_duration='2',
        task_duration_type='24', task_dynamic='0', task_notify='0'))
    subtask = int(sql("select task_id from tasks where task_name = 'Panel upgrade'")[0][0])
    staying_task = int(sql("select task_id from tasks where task_project = %d and task_name like 'Rough-in plumbing%%'" % PROJECT)[0][0])
    doomed_task = int(sql("select task_id from tasks where task_project = %d and task_name like 'Siding%%'" % PROJECT)[0][0])

    def create(name, task=0, parent=0):
        code, body = admin.call('create', {'project_id': PROJECT, 'parent': parent, 'fields': {'name': name, 'status': todo, 'task': task}})
        assert code == 200, body
        return body['data']['created']

    x = create('Pull permit for panel', task=moving_task)
    y = create('Call the utility', parent=x)            # subcard, no task of its own
    z = create('Buy breaker', task=subtask)              # on the subtask
    w = create('Plumber walkthrough', task=staying_task)  # stays behind
    admin.call('dependency_add', {'card_id': x, 'requires': w})
    v = create('Order siding', task=doomed_task)
    v2 = create('Pick siding color', parent=v)
    u = create('Schedule dumpster')
    admin.call('dependency_add', {'card_id': u, 'requires': v})
    x_key_before = card(admin.call('board', {'project_id': PROJECT})[1]['data']['board'], x)['key']

    print('== move the task (and its subtask) to the other project, delete another task')
    ui.submit('m=tasks&a=addedit&task_id=%d' % moving_task, {'new_task_project': other})
    check(int(sql('select task_project from tasks where task_id = %d' % subtask)[0][0]) == other, 'core moved the subtask too')
    ui.submit('m=tasks&a=view&task_id=%d' % doomed_task, {'del': '1', 'task_id': doomed_task, 'dosql': 'do_task_aed'}, form_name='frmDelete')
    if sql('select task_id from tasks where task_id = %d' % doomed_task):
        sql('delete from tasks where task_id = %d' % doomed_task)  # fall back if the delete form differs
    check(not sql('select task_id from tasks where task_id = %d' % doomed_task), 'task deleted')

    print('== validate links on read (before the sweep)')
    b1 = admin.call('board', {'project_id': PROJECT})[1]['data']['board']
    b2 = admin.call('board', {'project_id': other})[1]['data']['board']
    check(x not in ids(b1) and z not in ids(b1), 'cards on the moved tasks no longer show in the old project')
    check({x, z} <= ids(b2), 'they show under the task\'s new project (task wins)')
    check(v not in ids(b1), 'card on the deleted task is hidden')

    print('== sweep')
    sweep()
    rows = {int(r[0]): int(r[1]) for r in sql('select card_id, card_project from cards where card_id in (%d,%d,%d,%d)' % (x, y, z, w))}
    check(rows.get(x) == other and rows.get(y) == other and rows.get(z) == other, 'card, its subcard and the subtask\'s card moved')
    check(rows.get(w) == PROJECT, 'unrelated card stayed')
    check(not sql('select 1 from card_dependencies where dependency_card = %d and dependency_requires = %d' % (x, w)), 'cross-project dependency removed')
    xa = admin.call('card', {'card_id': x})[1]['data']['card']
    moved = [a for a in xa['activity'] if a['type'] == 'moved_project']
    check(moved and x_key_before in moved[0]['old'] and ('Garage-%d' % x) in moved[0]['new'], 'move logged with old and new key (%s -> %s)' % (moved[0]['old'], moved[0]['new']) if moved else 'move logged')
    check(any(a['type'] == 'dependency_removed' and 'another project' in a['new'] for a in xa['activity']), 'removed dependency logged on the moved card')
    wa = admin.call('card', {'card_id': w})[1]['data']['card']
    check(any(a['type'] == 'dependency_removed' and a['user'] == 0 for a in wa['activity']), 'and on the card left behind, as System')
    check(xa['key'] == 'Garage-%d' % x, 'key prefix follows the project, id unchanged')
    check(not sql('select 1 from cards where card_id in (%d, %d)' % (v, v2)), 'cards on the deleted task deleted, with their subcard')
    ua = admin.call('card', {'card_id': u})[1]['data']['card']
    check(any(a['type'] == 'dependency_removed' for a in ua['activity']), 'surviving card logs the removed dependency')

    print('== sweep again: nothing left to do')
    before = sql('select count(*) from card_activity')[0][0]
    sweep()
    check(sql('select count(*) from card_activity')[0][0] == before, 'second sweep changes nothing (idempotent)')

    print('== delete the other project')
    ui.submit('m=projects&a=view&project_id=%d' % other, {'del': '1', 'project_id': other, 'dosql': 'do_project_aed'}, form_name='frmDelete')
    if sql('select project_id from projects where project_id = %d' % other):
        sql('delete from projects where project_id = %d' % other)
    sweep()
    check(not sql('select 1 from cards where card_project = %d' % other), 'the deleted project\'s cards are gone')

    print()
    print('%d failure(s)' % len(failures) if failures else 'all sweep checks passed')
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
