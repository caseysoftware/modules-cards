"""Moving cards to another project ("Move to…" in the card panel), against
the web2project demo data:

    W2P_DIR=/path/to/web2project python3 tests/move_test.py

Adds a second project (and an inactive third one) through web2project's own
form if they aren't there.
"""
import os
import secrets
import sys

sys.path.insert(0, os.path.dirname(__file__))
from api_test import Client, check, failures, sql, card, PROJECT  # noqa: E402

W2P_DIR = os.environ.get('W2P_DIR', os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..')))
sys.path.insert(0, os.path.join(W2P_DIR, 'dev', 'demo-data'))
from w2p_client import W2P  # noqa: E402


def project(short, name, active=True):
    found = sql("select project_id from projects where project_short_name = '%s'" % short)
    if not found:
        company = sql('select project_company from projects where project_id = %d' % PROJECT)[0][0]
        fields = dict(project_name=name, project_short_name=short, project_company=company,
                      project_status='3', project_start_date='20261101', start_date='01/Nov/2026',
                      project_end_date='20270301', end_date='01/Mar/2027', project_color_identifier='5577AA')
        W2P().login().submit('m=projects&a=addedit', fields)
        found = sql("select project_id from projects where project_short_name = '%s'" % short)
    pid = int(found[0][0])
    sql('update projects set project_active = %d where project_id = %d' % (1 if active else 0, pid))
    return pid


def main():
    admin = Client('admin', os.environ.get('W2P_ADMIN_PASS', 'passw0rd'))
    worker = Client('dwhitfield', os.environ.get('W2P_WORKER_PASS', 'buildit2026'))
    shed = project('Shed', 'Backyard shed')
    fence = project('Fence', 'Garden fence', active=False)
    statuses = {s['name']: s['id'] for s in admin.call('board', {'project_id': PROJECT})[1]['data']['board']['statuses']}
    tasks = [int(r[0]) for r in sql('select task_id from tasks where task_project = %d and task_access = 0 order by task_id limit 2' % PROJECT)]
    tag = secrets.token_hex(3)

    def mk(name, parent=0, task=0):
        fields = {'name': '%s %s' % (name, tag), 'status': statuses['To Do']}
        if task:
            fields['task'] = task
        code, body = admin.call('create', {'project_id': PROJECT, 'parent': parent, 'fields': fields})
        assert code == 200, body
        return body['data']['created']

    def dep(a, b):
        """a waits on b"""
        code, body = admin.call('dependency_add', {'card_id': a, 'requires': b})
        assert code == 200, body

    def row(card_id):
        r = sql('select card_project, card_task, card_parent, card_rank from cards where card_id = %d' % card_id)[0]
        return int(r[0]), int(r[1]), int(r[2]), r[3]

    def deps(*ids):
        lst = ','.join(str(i) for i in ids)
        return {(int(a), int(b)) for a, b in sql('select dependency_card, dependency_requires from card_dependencies '
                                                  'where dependency_card in (%s) or dependency_requires in (%s)' % (lst, lst))}

    def version(card_id):
        return int(sql('select card_version from cards where card_id = %d' % card_id)[0][0])

    # The card, a subcard and a sub-subcard (two with tasks); X waits on the
    # subcard and has a subcard of its own; Z waits on X; the card waits on Y;
    # Q, a subcard of P, waits on the sub-subcard; U is unrelated.
    root = mk('Order windows', task=tasks[0])
    s1 = mk('Measure openings', root, task=tasks[1])
    s2 = mk('Confirm sizes', s1)
    x = mk('Install windows')
    x1 = mk('Flash window openings', x)
    z = mk('Trim windows')
    y = mk('Pick window supplier')
    p = mk('Exterior finishes')
    q = mk('Paint window trim', p)
    u = mk('Unrelated card')
    dep(x, s1)
    dep(z, x)
    dep(root, y)
    dep(q, s2)

    print('== preview')
    code, body = admin.call('move_project_preview', {'card_id': root})
    move = body['data']['move'] if code == 200 else {}
    check(code == 200 and move['project'] == PROJECT, 'the preview answers')
    targets = {pr['id'] for pr in move['projects']}
    check(shed in targets and PROJECT not in targets and fence not in targets, 'it offers other active projects only')
    plain, related = move['plain'], move['with_related']
    check(set(plain['ids']) == {root, s1, s2} and plain['ids'][0] == root, 'without connected cards: the card and its subcards (%s)' % plain['ids'])
    check(set(related['ids']) == {root, s1, s2, x, x1, z, y, q}, 'with them: every card linked by dependencies, through any number of links, with subcards')
    check(u not in related['ids'] and p not in related['ids'], 'unconnected cards and the parent of a connected subcard stay')
    check(plain['tasks'] == 2 and related['tasks'] == 2, 'it counts the task links that would be removed')
    check(len(plain['cut']) == 3 and len(related['cut']) == 0, 'without connected cards, their 3 dependencies would be cut; with them, none')
    flags = {c['id']: c for c in related['cards']}
    check(flags[x]['related'] and not flags[s1]['related'] and flags[q]['leaves_parent'].endswith('Exterior finishes %s' % tag),
          'cards say why they come along, and which one leaves its parent')
    check(flags[root]['task'] != '' and flags[s2]['task'] == '', 'and which ones lose a task')

    print('== refusals')
    base = {'card_id': root, 'version': version(root), 'related': False, 'expected': plain['ids']}
    code, body = admin.call('move_project', dict(base, project_id=PROJECT))
    check(code == 400, 'not to its own project')
    code, body = admin.call('move_project', dict(base, project_id=fence))
    check(code == 400, 'not to an inactive project')
    code, body = admin.call('move_project', dict(base, project_id=999999))
    check(code == 400, 'not to a project that doesn\'t exist')
    code, body = admin.call('move_project', dict(base, project_id=shed, version=base['version'] - 1))
    check(code == 409, 'not when the card changed since it was opened')
    code, body = admin.call('move_project', dict(base, project_id=shed, expected=[root, s1]))
    check(code == 409 and 'changed' in body['error']['message'], 'not when the cards that would move changed since the preview')
    check(row(root)[0] == PROJECT and row(s1)[0] == PROJECT, 'refused moves change nothing')

    print('== moving the card and its subcards')
    before = deps(root, s1, s2)
    code, body = admin.call('move_project', dict(base, project_id=shed, open_card=root))
    data = body['data'] if code == 200 else {}
    check(code == 200 and data['moved'] == 3 and data['key'].startswith('Shed-'), 'it moves 3 cards (%s)' % (data.get('key') if data else body))
    check(data['board']['project']['id'] == PROJECT and root not in {c['id'] for c in data['board']['cards']}, 'the board it came from no longer shows them')
    check(all(row(i)[0] == shed and row(i)[1] == 0 for i in (root, s1, s2)), 'all three are in the new project, without tasks')
    check(row(s1)[2] == root and row(s2)[2] == s1 and row(root)[2] == 0, 'they stay a family')
    check(deps(root, s1, s2) == set() and len(before) == 3, 'dependencies on cards that stayed are removed')
    shed_board = admin.call('board', {'project_id': shed})[1]['data']['board']
    order = [c['id'] for c in shed_board['cards']]
    check(order[-3:] == [root, s1, s2], 'they go to the end of the new board, in their order')
    log = admin.call('card', {'card_id': root})[1]['data']['card']['activity']
    types = [(a['type'], a['field']) for a in log[:3]]
    check(('moved_project', 'project') in types and ('changed', 'task') in types, 'the move and the lost task are logged (%s)' % types)
    moved = [a for a in log if a['type'] == 'moved_project'][0]
    check(moved['old'].startswith('Build Family Home') and moved['new'].startswith('Backyard shed (Shed-'), 'the log names both projects')
    sub_log = admin.call('card', {'card_id': s2})[1]['data']['card']['activity']
    check(sub_log[0]['type'] == 'moved_project' and ' with Shed-%d' % root in sub_log[0]['new'], 'subcards say what they moved with')
    x_log = admin.call('card', {'card_id': x})[1]['data']['card']['activity']
    check(x_log[0]['type'] == 'dependency_removed' and x_log[0]['new'] == 'moved to another project', 'cards left behind say why a dependency went')
    q_log = admin.call('card', {'card_id': q})[1]['data']['card']['activity']
    check(q_log[0]['type'] == 'dependency_removed', 'on both sides')
    history = sql("select count(*) from history where history_table = 'cards' and history_item = %d and history_project = %d" % (root, shed))
    check(int(history[0][0]) >= 1, 'the move is in the project history')

    print('== moving with connected cards')
    # x waits on nothing now; z waits on x; q waits on nothing; set up a new chain.
    a = mk('Pour footings')
    a1 = mk('Dig footings', a)
    b = mk('Frame walls')
    c = mk('Raise roof')
    d = mk('Landscaping')
    e = mk('Grade around footings', d)
    dep(b, a1)
    dep(c, b)
    dep(e, a)
    code, body = admin.call('move_project_preview', {'card_id': a})
    want = body['data']['move']['with_related']['ids']
    check(set(want) == {a, a1, b, c, e}, 'preview of the chain')
    dep(d, c)  # the parent of a connected subcard joins: the preview is out of date
    code, body = admin.call('move_project', {'card_id': a, 'version': version(a), 'project_id': shed, 'related': True, 'expected': want})
    check(code == 409, 'a dependency added since the preview stops the move')
    want = admin.call('move_project_preview', {'card_id': a})[1]['data']['move']['with_related']['ids']
    check(set(want) == {a, a1, b, c, d, e}, 'and the new preview has the extra card (with its subcard)')
    admin.call('dependency_remove', {'card_id': d, 'requires': c})
    want = admin.call('move_project_preview', {'card_id': a})[1]['data']['move']['with_related']['ids']
    code, body = admin.call('move_project', {'card_id': a, 'version': version(a), 'project_id': shed, 'related': True, 'expected': want})
    check(code == 200 and body['data']['moved'] == 5, 'moving the chain moves 5 cards')
    check(all(row(i)[0] == shed for i in (a, a1, b, c, e)) and row(d)[0] == PROJECT, 'the chain moved; the parent of one of them stayed')
    check(deps(a, a1, b, c, e) == {(b, a1), (c, b), (e, a)}, 'the dependencies between them came along')
    check(row(e)[2] == 0, 'the subcard whose parent stayed is now a top-level card')
    d_log = admin.call('card', {'card_id': d})[1]['data']['card']['activity']
    check(d_log[0]['type'] == 'subcard_removed' and d_log[0]['new'] == 'moved to Backyard shed', 'its old parent logs it')
    code, body = admin.call('board', {'project_id': -1})
    check(card(body['data']['board'], c)['project'] == shed, 'the all-projects board shows them under the new project')

    print('== rights')
    private = sql('select task_id from tasks where task_project = %d and task_access = 3 limit 1' % PROJECT)
    if not private:
        admin_id = int(sql("select user_id from users where user_username = 'admin'")[0][0])
        last = int(sql('select max(task_id) from tasks where task_project = %d' % PROJECT)[0][0])
        sql('update tasks set task_access = 3, task_owner = %d where task_id = %d' % (admin_id, last))
        private = [[last]]
    private_task = int(private[0][0])
    h = mk('Window warranty')
    mk('Warranty claim', h, task=private_task)
    code, body = worker.call('move_project_preview', {'card_id': h})
    hidden = body['data']['move']['plain']['hidden'] if code == 200 else None
    check(hidden == 1, 'the preview counts cards the user can\'t see without naming them (%s)' % hidden)
    code, body = worker.call('move_project', {'card_id': h, 'version': version(h), 'project_id': shed, 'related': False})
    check(code == 403 and row(h)[0] == PROJECT, 'nobody moves cards they can\'t see (tasks would be cleared, showing them to everyone)')
    code, body = worker.call('move_project', {'card_id': x, 'version': version(x), 'project_id': shed, 'related': True})
    check(code in (200, 400, 403), 'a project worker either may move cards or is refused (%s)' % code)

    print()
    print('%d failure(s)' % len(failures) if failures else 'all move checks passed')
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
