# Cards for web2project

A Kanban board module for web2project 5: cards you drag between columns,
attached to a project and optionally to one of its tasks.

- **All projects board** (the default) with every card you may see, and a
  **board per project**. Columns come from a global status list. Drag cards
  between columns and within them; the order is kept per project.
- **Group by** status, priority, owner, tag, and linked task (project board)
  or project (all projects board). Dropping a card in another column sets
  that field.
- **Card drawer**: name, status, priority, owner, due date, linked task,
  tags, description, subcards, dependencies, and the card's activity log
  with comments.
- **Subcards and dependencies** between cards of the same project, with loop
  detection.
- **Soft WIP limits** per board: a column header turns amber at its limit
  and red above it. Moves are never blocked.
- **Cards tab** on the project view (a compact board) and on the task view
  (the task's cards, plus its sub-tasks' cards marked "via sub-task").
- **Activity log** (`card_activity`) of every change, kept with the card.
- **Smart Search** finds cards by name and description, and the **Calendar**
  (month, week and day views, and your calendar feed) shows your open cards
  on their due dates.

Cards are stored on their own; they don't change web2project tasks. See
modules-cards #1 for how cards relate to tasks.

## Requirements

- web2project 5 (built against the `dev-5.0.0` branch)
- PHP 8.1 or later
- MySQL or MariaDB (the module's own tables use InnoDB)

## Install

The module's folder must be named `cards`:

```bash
cd /path/to/web2project/modules
git clone https://github.com/caseysoftware/modules-cards.git cards
```

Then, as an administrator:

1. **System Admin → View Modules**: find **Cards**, click **install**, then
   switch it **active** (and visible in the menu).
2. Log out and back in. web2project caches the list of tabs per session, so
   the **Cards** tabs on project and task views appear after a new login.
3. Give roles access in **System Admin → Roles** if they don't get it
   through "non-admin modules" already.
4. Make sure `queuescanner.php` runs from cron (for example every 15
   minutes). The module's consistency sweep runs there; see below.

Removing the module from System Admin drops its tables and all cards.

## Permissions

A user sees a card when they
may view the **Cards** module, may view the card's **project**, and (if the
card is linked to a task) may see that **task**: the task permissions plus
the task's access level (public, protected, participant, private), the same
rules as the task view. If a user can't see a task, they don't see the cards
attached to it.

Adding, changing and deleting cards needs the **add**, **edit** and
**delete** rights on the Cards module. Board settings need edit rights on
the project. The default board settings and the global status list need
System edit rights.

The same rules apply everywhere: the boards, the tabs, Smart Search, the
calendar, and core lookups of single cards (History). Per-card denials set
in Roles also apply, but they can't grant a card whose project or task the
user can't see.

## Statuses, settings and keys

- **Statuses** are one global list (**Cards → edit card statuses**). Each
  status "counts as" not started, started or closed. A status that still has
  cards can't be removed.
- **Board settings** are per project: which status columns are shown, soft
  WIP limits, and which fields cards show.
- **Default board settings** (administrators, from the all projects board's
  **Default settings** button) are what the all projects board uses, and
  what every project uses until it saves settings of its own. Once a project
  saves its settings it keeps them. The all projects board shows no WIP
  limits: those belong to one team's board.
- **Card keys** look like `CedarLane-42`: the project's short name and the
  card's id. They are display only; everything links by id, so a key keeps
  working after the project's short name changes or the card follows its
  task to another project (the number never changes).

## The all projects board

**Cards** opens on **All projects**: every card you may see in an **active**
project, grouped by project within each column. Each card shows its title with the due date
on the right, and its project as a pill in the project's color with the
subcard count on the right; card numbers aren't shown there. You can drag
cards between statuses (and priorities, owners and tags), but never between
projects: grouped by project, a card can only be reordered within its own
project's column. **+ New card** asks which project the card is for. Pick a
project in the title bar for a single project's board; the picker lists
**All projects** and the active projects. An inactive project's board is
still reachable from its project's **Cards** tab or a link to one of its
cards, and then shows in the picker marked "(inactive)".

### Moving a card to another project

Cards don't move between projects on their own. A card changes project only
with its linked task: link the card to a task, then move that task to the
other project in its task form (web2project moves its sub-tasks too). The
card, its subcards and the cards on those sub-tasks follow, and keep their
ids, so links to them keep working. Any dependency or subcard link that would
then cross projects is removed and logged. Otherwise, create a new card in
the other project.

## Consistency with tasks and projects (modules-cards #3)

- A **project** is deleted → its cards are deleted.
- A **task** is deleted → the cards linked to it are deleted, with their
  subcards.
- A task is **moved to another project** (web2project moves its subtasks
  too) → the cards on those tasks move with them, with their subcards. Any
  subcard link or dependency that would now cross projects is removed and
  logged on both cards. Each move is logged.
- Cards can't otherwise change project, and relationships only connect
  cards in the same project.

These run in the cron sweep (`CCard::hook_cron`, from `queuescanner.php`).
Until the sweep runs, the board already shows the right thing ("validate
links on read"): cards on a deleted task or project are hidden, and cards
on a moved task show under the task's new project. When web2project can tell
modules about task and project changes (web2project/web2project#526), the
same handlers will run immediately; the sweep stays as a backstop.

## Development

```
cards.class.php           CCard: module main class; search, calendar and cron hooks
setup.php                 install, remove, upgrade
index.php                 the board: all projects or one project
statuses.php              global status list (admins)
do_card_json.php          JSON endpoint used by the board
do_statuses_aed.php       saves the status list
projects_tab.view.cards.php, tasks_tab.view.cards.php   the tabs
includes/                 CardStore (writes), CardBoard (reads), CardAccess
                          (permissions), CardMaintenance (sweep), CardRank,
                          CardActivity, CardTags, CardStatuses, CardSettings,
                          CardJson, CardView
assets/                   board.js, board.css, vendor/Sortable.min.js
tests/                    rank_test.php, api_test.py, allview_test.py,
                          ui_test.py, sweep_test.py
```

The board is plain JavaScript (no build step) plus SortableJS. It posts to
`do_card_json.php` with the session's form token; every change answers with
the refreshed board, which is rendered again. Card text is always inserted
as text, never as HTML. Changes carry the card's version, and a stale change
gets a 409 with the current board.

Card order uses fractional ranks (`includes/CardRank.php`): a drop writes
one row. Ranks are rebalanced per project when they grow long.

### Tests

The Python tests run against a web2project instance with its demo data
(`dev/demo-data/setup.sh` in web2project) and the module installed and
active. They need `requests`, `playwright` (with Chromium) and the `mysql`
client.

```bash
php tests/rank_test.php
python3 tests/api_test.py
W2P_DIR=/path/to/web2project python3 tests/allview_test.py   # after api_test.py
python3 tests/ui_test.py --shots /tmp/shots
W2P_DIR=/path/to/web2project python3 tests/sweep_test.py   # run last: it moves and deletes demo tasks
```

## Not in this version yet

- Swimlanes (wireframe 2).
- Real-time handling of task and project changes: waits on
  web2project/web2project#526 (the cron sweep covers it meanwhile).
- Tags are module-local (`card_tags`) until core tagging
  (web2project/web2project#529); the JSON helper is module-local until
  web2project/web2project#528.
- Markdown in descriptions and comments (modules-cards #2).
- Dragging with the keyboard; the drawer's Status menu moves a card without
  a mouse.
- Long Done columns are not paged.
- The calendar shows the cards you own; there's no option yet to show
  everyone's.

## Upgrading

From 0.1.0: copy the new files over, then **System Admin → View Modules →
upgrade** on Cards. 0.2.0 registers cards as permission items so Smart
Search and History can check single cards.

## Third-party

`assets/vendor/Sortable.min.js` is [SortableJS](https://github.com/SortableJS/Sortable)
1.15.7, MIT licensed (`assets/vendor/Sortable.LICENSE`).
