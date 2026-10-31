# Screenshots

The Cards module in web2project 5 with its demo data ("Build Family Home -
1420 Cedar Lane"), plus a second project, "Detached garage". Each image maps
to one of the module's wireframes.

| Wireframe | Screenshot |
|---|---|
| 1 · Card board, grouped by status | [01-board.png](01-board.png): one project's board, with soft WIP limits over (In Progress) and at the limit (Review) |
| 2 · My Work, swimlanes by project | [02-all-projects.png](02-all-projects.png): the all-projects board that took this wireframe's place (project pills, due dates on the title line)<br>[02b-all-projects-by-project.png](02b-all-projects-by-project.png): the same board grouped by project. Swimlanes aren't built yet. |
| 3 · Card detail drawer | [03-card-drawer.png](03-card-drawer.png): fields, tags, description, subcards, dependencies<br>[03b-card-drawer-activity.png](03b-card-drawer-activity.png): the rest of the drawer, with comments and the activity log |
| 4A · Plain move | [04a-drag.png](04a-drag.png): a card being dragged into In Progress, with its drop placeholder |
| 4B · Unmet dependency | [04b-unmet-dependency.png](04b-unmet-dependency.png): moving a card to Done while it still waits on another |
| 4C · Parent with open subcards | [04c1-parent-still-waiting.png](04c1-parent-still-waiting.png): this parent also waits on another card, so that question comes first<br>[04c2-parent-open-subcards.png](04c2-parent-open-subcards.png): move the card only, or with its open subcards |
| 4D · Not allowed | No image: a drop the board won't take (another project's column) simply doesn't show a placeholder, and the server refuses it. See 02b, where each project's column keeps its own cards. |
| 5 · Board settings | [05-board-settings.png](05-board-settings.png): WIP limits, shown columns, card fields |
| 6 · Project view, Cards tab | [06-project-tab.png](06-project-tab.png) |
| 7 · Task view, Cards tab | [07-task-tab.png](07-task-tab.png): the task's cards and its sub-task's cards, marked "via sub-task" |

Not in the wireframes:

- [08-default-settings.png](08-default-settings.png): the default board settings, from the all-projects board (administrators)
- [09-card-statuses.png](09-card-statuses.png): the global status list (administrators)
- [10-calendar.png](10-calendar.png): your open cards on the Calendar on their due dates
- [11-smart-search.png](11-smart-search.png): cards in Smart Search results

## Regenerating

`capture.py` adds the sample cards and takes every screenshot. Run it once on
a fresh web2project demo install (`dev/demo-data/setup.sh` in web2project)
with the Cards module installed and active:

```bash
W2P_DIR=/path/to/web2project python3 screenshots/capture.py
```

It needs Python 3 with `requests` and `playwright` (Chromium) and the
`mysql` client, and it reuses the helpers in `cards/tests/`. The viewport is
1800×1000, so all five status columns fit beside web2project's sidebar.
