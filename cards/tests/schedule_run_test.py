"""End-to-end checks of scheduled copies (repeating cards) against a running
web2project with the demo data:

    W2P_DIR=/path/to/web2project python3 tests/schedule_run_test.py

Saves schedules through the JSON API, moves their dates in the database to
make them due, and runs the cron entry point (queuescanner.php) or loads a
board page to run them.
"""
import datetime
import os
import subprocess
import sys

sys.path.insert(0, os.path.dirname(__file__))
from api_test import Client, check, failures, sql, card, PROJECT, INDEX  # noqa: E402

W2P_DIR = os.environ.get('W2P_DIR', '.')


def cron(parallel=1):
    procs = [subprocess.Popen(['php', 'queuescanner.php'], cwd=W2P_DIR, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
             for _ in range(parallel)]
    for p in procs:
        p.wait()


def main():
    admin = Client('admin', os.environ.get('W2P_ADMIN_PASS', 'passw0rd'))
    board = admin.call('board', {'project_id': PROJECT})[1]['data']['board']
    statuses = {s['name']: s['id'] for s in board['statuses']}

    def mk(name, parent=0, status='Backlog', due=None):
        fields = {'name': name, 'status': statuses[status]}
        if due:
            fields['due'] = due
        return admin.call('create', {'project_id': PROJECT, 'parent': parent, 'fields': fields})[1]['data']['created']

    def schedule(card_id):
        rows = sql('select schedule_next, schedule_anchor, schedule_active, schedule_last_copy, schedule_note, schedule_user '
                   'from card_schedules where schedule_card = %d' % card_id)
        return rows[0] if rows else None

    def copies_of(card_id):
        return [int(r[0]) for r in sql("select activity_related from card_activity where activity_card = %d "
                                       "and activity_type = 'copied' and activity_field = 'to' order by activity_id" % card_id)]

    def save(card_id, **sched):
        return admin.call('schedule_save', {'card_id': card_id, 'schedule': sched, 'open_card': card_id})

    print('== saving a repeat')
    template = mk('Weekly checklist', due='2026-11-20')
    sub = mk('Check the site', template, due='2026-11-18')
    # Every day of the week starting today makes the first copy today (the server's today).
    code, body = save(template, rule='weekly', days=[1, 2, 3, 4, 5, 6, 7], name='{name} – {iso}',
                      status=statuses['To Do'], shift=True, skip_open=False)
    sched = body['data']['card']['schedule'] if code == 200 else None
    check(code == 200 and sched and sched['summary'] == 'Every day' and sched['active'], 'a repeat is saved (%s)' % (sched and sched['summary']))
    today = sched['next']
    check(sched['start'] == today, 'it starts today (%s)' % today)
    check(card(body['data']['board'], template)['repeats'] is True, 'the board marks the card as repeating')
    act = body['data']['card']['activity'][0]
    check(act['type'] == 'changed' and act['field'] == 'repeat' and act['new'] == 'Every day', 'saving logs the repeat')

    for bad, why in [({'rule': 'yearly'}, 'an unknown rule'), ({'rule': 'weekly', 'days': []}, 'no weekdays'),
                     ({'rule': 'monthly', 'days': 32}, 'day 32'), ({'rule': 'days', 'days': 0}, 'every 0 days'),
                     ({'rule': 'weekly', 'days': [1], 'start': '2026-02-30'}, 'a bad date'),
                     ({'rule': 'weekly', 'days': [1], 'status': 9999}, 'an unknown status')]:
        code, body = admin.call('schedule_save', {'card_id': template, 'schedule': bad})
        check(code == 400, '%s is refused' % why)
    check(schedule(template)[0] == today, 'a refused change leaves the repeat as it was')

    print('== running it')
    cron()
    made = copies_of(template)
    check(len(made) == 1, 'cron makes the copy that is due (%s)' % made)
    first = admin.call('card', {'card_id': made[0]})[1]['data']['card']
    check(first['name'] == 'Weekly checklist – ' + today, 'the copy is named from the pattern (%s)' % first['name'])
    check(first['status'] == statuses['To Do'] and first['due'] == '2026-11-20', 'it gets the chosen status; due dates unmoved on the first date')
    kids = [c for c in first['subcard_list']]
    check(len(kids) == 1 and kids[0]['name'] == 'Check the site' and kids[0]['due'] == '2026-11-18', 'its subcard is copied too')
    row = schedule(template)
    tomorrow = (datetime.date.fromisoformat(today) + datetime.timedelta(days=1)).isoformat()
    check(row[0] == tomorrow and int(row[3]) == made[0], 'the next date moves on and the last copy is kept')
    cron()
    check(len(copies_of(template)) == 1, 'running cron again makes nothing new')

    print('== a later date')
    week_ago = (datetime.date.fromisoformat(today) - datetime.timedelta(days=7)).isoformat()
    sql("update card_schedules set schedule_next = '%s', schedule_anchor = '%s' where schedule_card = %d" % (today, week_ago, template))
    cron()
    made = copies_of(template)
    later = admin.call('card', {'card_id': made[-1]})[1]['data']['card']
    check(len(made) == 2 and later['due'] == '2026-11-27' and later['subcard_list'][0]['due'] == '2026-11-25',
          'due dates move by the days since the first date (+7)')

    print('== skipping while the last copy is open')
    sql("update card_schedules set schedule_next = '%s', schedule_skip_open = 1 where schedule_card = %d" % (today, template))
    cron()
    row = schedule(template)
    check(len(copies_of(template)) == 2 and row[2] == '1' and 'still open' in row[4], 'no copy while the last one is open (%s)' % row[4])
    log = admin.call('card', {'card_id': template})[1]['data']['card']['activity'][0]
    check(log['type'] == 'system' and log['new'].startswith('skipped the '), 'the skip is logged on the card')
    sql("update cards set card_status = %d where card_id = %d" % (statuses['Done'], made[-1]))
    sql("update card_schedules set schedule_next = '%s' where schedule_card = %d" % (today, template))
    cron()
    check(len(copies_of(template)) == 3 and schedule(template)[4] == '', 'once the last copy is closed, the next one is made')

    print('== missed dates')
    three_weeks = (datetime.date.fromisoformat(today) - datetime.timedelta(days=21)).isoformat()
    sql("update card_schedules set schedule_next = '%s', schedule_skip_open = 0 where schedule_card = %d" % (three_weeks, template))
    cron()
    check(len(copies_of(template)) == 4 and schedule(template)[0] == tomorrow, 'missed dates make one copy, then the next date is after today')

    print('== two runs at once')
    sql("update card_schedules set schedule_next = '%s' where schedule_card = %d" % (today, template))
    cron(parallel=4)
    check(len(copies_of(template)) == 5, 'four cron runs at once make one copy (%d)' % (len(copies_of(template)) - 4))

    print('== without cron')
    sql("update card_schedules set schedule_next = '%s' where schedule_card = %d" % (today, template))
    admin.s.get(INDEX + '?m=cards&project_id=%d' % PROJECT)
    check(len(copies_of(template)) == 6, 'loading a board page runs a due repeat')

    print('== pausing')
    sql("update card_schedules set schedule_next = '%s', schedule_user = 99999 where schedule_card = %d" % (today, template))
    cron()
    row = schedule(template)
    check(len(copies_of(template)) == 6 and row[2] == '0' and row[4].startswith('Paused'), 'a repeat whose user lost access pauses (%s)' % row[4])
    detail = admin.call('card', {'card_id': template})[1]['data']['card']
    check(detail['schedule']['active'] is False and detail['activity'][0]['new'].startswith('paused the repeat'), 'the pause shows in the drawer and the log')
    board = admin.call('board', {'project_id': PROJECT})[1]['data']['board']
    check(card(board, template)['repeats'] is False, 'the board marks the repeat as paused')
    cron()
    check(len(copies_of(template)) == 6, 'a paused repeat makes no copies')
    code, body = save(template, rule='monthly', days=1, name='', status=0, shift=False)
    sched = body['data']['card']['schedule']
    admin_id = int(sql("select user_id from users where user_username = 'admin'")[0][0])
    check(sched['active'] and sched['user'] == admin_id and sched['summary'] == 'Monthly on the 1st' and sched['note'] == '',
          'saving again resumes it as the user who saved it')
    check(sched['next'][8:] == '01' and sched['next'] >= today, 'monthly on the 1st: next is a 1st (%s)' % sched['next'])

    print('== worker rights')
    worker = Client('dwhitfield', os.environ.get('W2P_WORKER_PASS', 'buildit2026'))
    code, body = worker.call('schedule_save', {'card_id': template, 'schedule': {'rule': 'weekly', 'days': [1]}})
    check(code in (200, 403), 'a project worker either may set repeats or gets 403 (got %s)' % code)
    private_task = int(sql('select task_id from tasks where task_project = %d order by task_id desc limit 1' % PROJECT)[0][0])
    sql('update tasks set task_access = 3, task_owner = %d where task_id = %d' % (admin_id, private_task))
    secret = admin.call('create', {'project_id': PROJECT, 'fields': {'name': 'Secret repeat', 'status': statuses['To Do'], 'task': private_task}})[1]['data']['created']
    code, body = worker.call('schedule_save', {'card_id': secret, 'schedule': {'rule': 'weekly', 'days': [1]}})
    check(code in (403, 404), 'nobody can set a repeat on a card they can\'t see (%s)' % code)

    print('== stopping')
    code, body = admin.call('schedule_remove', {'card_id': template, 'open_card': template})
    check(code == 200 and body['data']['card']['schedule'] is None and schedule(template) is None, 'Stop repeating removes the schedule')
    check(body['data']['card']['activity'][0]['field'] == 'repeat' and body['data']['card']['activity'][0]['new'] == '', 'stopping is logged')

    print('== deleting the card')
    other = mk('Monthly report')
    save(other, rule='monthly', days=15)
    check(schedule(other) is not None, 'second repeat saved')
    admin.call('delete', {'card_id': other})
    check(schedule(other) is None, 'deleting a card deletes its repeat')

    print()
    print('%d failure(s)' % len(failures) if failures else 'all schedule checks passed')
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
