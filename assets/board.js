/*
 * Cards board for web2project.
 *
 * Mounts on every .w2p-cards element (see CCardView::mount). Talks to
 * do_card_json.php; every change answers with the refreshed board, which is
 * rendered again from scratch, so the page always shows the server's state.
 *
 * All card data goes into the page as text (textContent), never as HTML.
 */
(function () {
    'use strict';

    var PRIORITIES = [
        { value: 1, label: 'High' },
        { value: 0, label: 'Normal' },
        { value: -1, label: 'Low' }
    ];
    var CLOSED = 2;

    // ------------------------------------------------------------ helpers

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (key) {
            var value = attrs[key];
            if (value === null || value === undefined || value === false) {
                return;
            }
            if (key === 'text') {
                node.textContent = value;
            } else if (key === 'className') {
                node.className = value;
            } else if (key.indexOf('on') === 0 && typeof value === 'function') {
                node.addEventListener(key.substring(2).toLowerCase(), value);
            } else if (key === 'value') {
                node.value = value;
            } else if (key === 'checked' || key === 'disabled' || key === 'selected') {
                node[key] = !!value;
            } else {
                node.setAttribute(key, value === true ? '' : String(value));
            }
        });
        (children || []).forEach(function (child) {
            if (child === null || child === undefined || child === false) {
                return;
            }
            node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
        });
        return node;
    }

    function option(value, label, selected) {
        return el('option', { value: String(value), text: label, selected: !!selected });
    }

    function initials(name) {
        var parts = String(name || '').trim().split(/\s+/).filter(Boolean);
        if (!parts.length) {
            return '?';
        }
        return (parts[0][0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
    }

    function byId(list) {
        var map = {};
        (list || []).forEach(function (item) { map[item.id] = item; });
        return map;
    }

    // --------------------------------------------------------------- board

    function Board(root, options) {
        this.root = root;
        this.options = options;
        this.compact = options.mode === 'compact';
        // All projects: every visible card, default settings, no project changes.
        this.isAll = options.projectId === -1;
        this.viewKey = 'w2p-cards-view' + (this.isAll ? '-all' : '');
        this.data = null;
        this.group = 'status';
        this.filters = { owner: '', tag: '', q: '', subcards: true };
        this.openCardId = 0;
        this.cardData = null;
        this.sortables = [];
        this.busy = false;

        try {
            var saved = JSON.parse(window.localStorage.getItem(this.viewKey) || '{}');
            if (saved.group) { this.group = saved.group; }
            // Linked task only makes sense in one project; project only across them.
            if ((this.isAll && saved.group === 'task') || (!this.isAll && saved.group === 'project')) {
                this.group = 'status';
            }
            if (typeof saved.subcards === 'boolean') { this.filters.subcards = saved.subcards; }
        } catch (e) { /* storage unavailable: use the defaults */ }

        this.toastBox = el('div', { className: 'w2p-cards-toasts', role: 'status', 'aria-live': 'polite' });
        document.body.appendChild(this.toastBox);
        document.addEventListener('keydown', this.onKey.bind(this));

        var self = this;
        this.api('board', { project_id: options.projectId }).then(function (data) {
            self.update(data);
            if (options.openCard) {
                self.openCard(options.openCard);
            } else if (options.newCardTask) {
                self.newCardDialog({ task: options.newCardTask });
            }
        }).catch(function (err) {
            root.textContent = err.message;
        });
    }

    Board.prototype.saveView = function () {
        try {
            window.localStorage.setItem(this.viewKey, JSON.stringify({ group: this.group, subcards: this.filters.subcards }));
        } catch (e) { /* ignore */ }
    };

    /**
     * POSTs one request. Resolves with the response's data; rejects with an
     * Error whose message is fit to show, and whose .data holds any data the
     * error came with (a refreshed board after a conflict).
     */
    Board.prototype.api = function (op, payload) {
        var body = new FormData();
        body.append('__nonce', this.options.nonce);
        body.append('op', op);
        body.append('payload', JSON.stringify(payload || {}));
        return fetch(this.options.endpoint, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (response) {
                var type = response.headers.get('Content-Type') || '';
                if (type.indexOf('application/json') === -1) {
                    throw new Error('Your session may have expired. Reload the page and try again.');
                }
                return response.json();
            })
            .then(function (json) {
                if (!json.ok) {
                    var error = new Error((json.error && json.error.message) || 'Something went wrong.');
                    error.data = json.data;
                    error.code = json.error && json.error.code;
                    throw error;
                }
                return json.data;
            });
    };

    /** Runs a change, then shows the refreshed board (and card). */
    Board.prototype.change = function (op, payload) {
        var self = this;
        payload = payload || {};
        payload.board = this.options.projectId;
        if (this.openCardId) {
            payload.open_card = this.openCardId;
        }
        return this.api(op, payload).then(function (data) {
            self.update(data);
            return data;
        }).catch(function (err) {
            if (err.data) {
                self.update(err.data);
            } else {
                self.render();
            }
            if (err.code === 'conflict' && self.openCardId) {
                self.openCard(self.openCardId);
            }
            self.toast(err.message, true);
            throw err;
        });
    };

    Board.prototype.update = function (data) {
        if (data.board) {
            this.data = data.board;
            this.users = byId(this.data.users);
            this.tags = byId(this.data.tags);
            this.tasks = byId(this.data.tasks);
            this.statuses = byId(this.data.statuses);
            this.cards = byId(this.data.cards);
            this.render();
        }
        if (data.card) {
            this.cardData = data.card;
            this.renderDrawer();
        } else if (this.openCardId && data.board && !this.cards[this.openCardId]) {
            this.closeDrawer();
        }
    };

    Board.prototype.toast = function (message, isError) {
        var note = el('div', { className: 'w2p-cards-toast' + (isError ? ' is-error' : ''), text: message });
        this.toastBox.appendChild(note);
        setTimeout(function () { note.remove(); }, isError ? 7000 : 3500);
    };

    // -------------------------------------------------------------- render

    Board.prototype.render = function () {
        var self = this;
        this.sortables.forEach(function (s) { s.destroy(); });
        this.sortables = [];
        this.root.textContent = '';
        this.root.classList.toggle('is-compact', this.compact);

        this.root.appendChild(this.renderToolbar());

        var lanes = el('div', { className: 'w2p-cards-columns' });
        this.columns().forEach(function (column) {
            lanes.appendChild(self.renderColumn(column));
        });
        this.root.appendChild(lanes);
    };

    Board.prototype.renderToolbar = function () {
        var self = this;
        var bar = el('div', { className: 'w2p-cards-toolbar' });
        var groupSelect = el('select', {
            'aria-label': 'Group by',
            onChange: function () { self.group = this.value; self.saveView(); self.render(); }
        }, [
            option('status', 'Status', this.group === 'status'),
            option('priority', 'Priority', this.group === 'priority'),
            option('owner', 'Owner', this.group === 'owner'),
            this.isAll ? option('project', 'Project', this.group === 'project') : option('task', 'Linked task', this.group === 'task'),
            option('tag', 'Tag', this.group === 'tag')
        ]);
        bar.appendChild(el('label', {}, ['Group by ', groupSelect]));

        if (!this.compact) {
            var ownerSelect = el('select', {
                'aria-label': 'Owner',
                onChange: function () { self.filters.owner = this.value; self.render(); }
            }, [option('', 'Anyone', this.filters.owner === '')]
                .concat(this.data.users.map(function (u) { return option(u.id, u.name, String(u.id) === self.filters.owner); })));
            bar.appendChild(el('label', {}, ['Owner ', ownerSelect]));

            var tagSelect = el('select', {
                'aria-label': 'Tag',
                onChange: function () { self.filters.tag = this.value; self.render(); }
            }, [option('', 'Any', this.filters.tag === '')]
                .concat(this.data.tags.map(function (t) { return option(t.id, t.name, String(t.id) === self.filters.tag); })));
            bar.appendChild(el('label', {}, ['Tag ', tagSelect]));
        }

        bar.appendChild(el('label', { className: 'w2p-cards-check' }, [
            el('input', {
                type: 'checkbox', checked: this.filters.subcards,
                onChange: function () { self.filters.subcards = this.checked; self.saveView(); self.render(); }
            }),
            ' Subcards as cards'
        ]));

        var search = el('input', {
            type: 'search', placeholder: 'Filter cards', 'aria-label': 'Filter cards', value: this.filters.q,
            onInput: function () {
                self.filters.q = this.value;
                clearTimeout(self.searchTimer);
                self.searchTimer = setTimeout(function () {
                    self.render();
                    var box = self.root.querySelector('.w2p-cards-toolbar input[type=search]');
                    if (box) { box.focus(); box.setSelectionRange(box.value.length, box.value.length); }
                }, 200);
            }
        });
        bar.appendChild(el('span', { className: 'w2p-cards-spacer' }));
        bar.appendChild(search);

        if (this.compact) {
            bar.appendChild(el('a', { href: this.options.boardUrl + '&project_id=' + this.data.project.id, text: 'Open full board' }));
        } else if (this.data.can.configure) {
            bar.appendChild(el('button', {
                type: 'button', className: 'w2p-cards-button', text: this.isAll ? 'Default settings' : 'Board settings',
                onClick: function () { self.settingsDialog(); }
            }));
        }
        if (this.data.can.add) {
            bar.appendChild(el('button', {
                type: 'button', className: 'w2p-cards-button is-primary', text: '+ New card',
                onClick: function () { self.newCardDialog({}); }
            }));
        }
        return bar;
    };

    /** The columns for the current grouping. */
    Board.prototype.columns = function () {
        var self = this;
        var data = this.data;
        var present = function (field) {
            var seen = {};
            data.cards.forEach(function (c) { seen[c[field]] = true; });
            return seen;
        };
        switch (this.group) {
            case 'priority':
                return PRIORITIES.map(function (p) { return { field: 'priority', value: p.value, label: p.label }; });
            case 'owner':
                var owners = present('owner');
                var cols = [{ field: 'owner', value: 0, label: 'Unassigned' }];
                data.users.forEach(function (u) {
                    if (owners[u.id]) { cols.push({ field: 'owner', value: u.id, label: u.name }); }
                });
                return cols;
            case 'task':
                var linked = present('task');
                var taskCols = [{ field: 'task', value: 0, label: 'No task' }];
                data.tasks.forEach(function (t) {
                    if (linked[t.id]) { taskCols.push({ field: 'task', value: t.id, label: '#' + t.id + ' ' + t.name }); }
                });
                return taskCols;
            case 'project':
                var inProject = present('project');
                return data.projects.filter(function (pr) { return inProject[pr.id]; }).map(function (pr) {
                    return { field: 'project', value: pr.id, label: pr.name, locked: true };
                });
            case 'tag':
                var used = {};
                data.cards.forEach(function (c) { c.tags.forEach(function (id) { used[id] = true; }); });
                var tagCols = [{ field: 'tag', value: 0, label: 'No tag' }];
                data.tags.forEach(function (t) {
                    if (used[t.id]) { tagCols.push({ field: 'tag', value: t.id, label: t.name }); }
                });
                return tagCols;
            default:
                return data.statuses.filter(function (s) {
                    var col = data.settings.columns[s.id];
                    return !col || col.shown;
                }).map(function (s) {
                    var col = data.settings.columns[s.id] || {};
                    // WIP limits belong to one team's board; the all-projects view shows none.
                    return { field: 'status', value: s.id, label: s.name, wip: self.isAll ? 0 : (col.wip || 0), category: s.category };
                });
        }
    };

    Board.prototype.matchesFilters = function (card) {
        var f = this.filters;
        if (!f.subcards && card.parent) { return false; }
        if (f.owner !== '' && String(card.owner) !== f.owner) { return false; }
        if (f.tag !== '' && card.tags.indexOf(parseInt(f.tag, 10)) === -1) { return false; }
        if (f.q) {
            var q = f.q.toLowerCase();
            var text = (card.key + ' ' + card.name + ' ' + card.task_name).toLowerCase();
            if (text.indexOf(q) === -1) { return false; }
        }
        return true;
    };

    Board.prototype.inColumn = function (card, column) {
        if (column.field === 'tag') {
            return column.value === 0 ? card.tags.length === 0 : card.tags.indexOf(column.value) !== -1;
        }
        return card[column.field] === column.value;
    };

    Board.prototype.renderColumn = function (column) {
        var self = this;
        var all = this.data.cards.filter(function (c) { return self.inColumn(c, column); });
        var shown = all.filter(function (c) { return self.matchesFilters(c); });

        var header = el('div', { className: 'w2p-cards-column-header' }, [
            el('h3', { text: column.label }),
            el('span', { className: 'w2p-cards-count', text: String(all.length) })
        ]);
        if (column.wip) {
            var state = all.length < column.wip ? 'under' : (all.length === column.wip ? 'at' : 'over');
            var label = 'WIP ' + all.length + ' / ' + column.wip + (state === 'at' ? ' · at limit' : (state === 'over' ? ' · over' : ''));
            header.appendChild(el('span', { className: 'w2p-cards-wip is-' + state, text: label, title: 'Soft work-in-progress limit' }));
        }

        var list = el('div', {
            className: 'w2p-cards-list',
            'data-field': column.field,
            'data-value': String(column.value)
        });
        shown.forEach(function (card) { list.appendChild(self.renderCard(card)); });

        var section = el('section', { className: 'w2p-cards-column', 'aria-label': column.label }, [header, list]);
        if (this.data.can.add && !this.isAll) {
            section.appendChild(el('button', {
                type: 'button', className: 'w2p-cards-add', text: '+ Add card',
                onClick: function () { self.inlineAdd(section, column); }
            }));
        }

        if (this.data.can.edit && window.Sortable) {
            this.sortables.push(window.Sortable.create(list, {
                // A project column keeps its cards: reorder inside it, never move out.
                group: column.locked ? { name: 'w2p-cards-locked-' + column.value, pull: false, put: false } : 'w2p-cards',
                animation: 150,
                ghostClass: 'is-placeholder',
                dragClass: 'is-dragging',
                delay: 200,
                delayOnTouchOnly: true,
                onEnd: function (evt) { self.onDrop(evt); }
            }));
        }
        return section;
    };

    Board.prototype.renderCard = function (card) {
        var self = this;
        var fields = this.data.settings.fields;
        var parts = [];

        if (card.parent && this.cards[card.parent]) {
            var parent = this.cards[card.parent];
            parts.push(el('div', { className: 'w2p-cards-parent', text: '↳ subcard of ' + parent.key + ' ' + parent.name }));
        }
        if (fields.tags && card.tags.length) {
            parts.push(el('div', { className: 'w2p-cards-tags' }, card.tags.map(function (id) {
                return el('span', { className: 'w2p-cards-tag', text: self.tags[id] ? self.tags[id].name : '#' + id });
            })));
        }
        parts.push(el('div', { className: 'w2p-cards-name', text: card.name }));
        if (this.isAll && fields.project && this.group !== 'project') {
            parts.push(el('div', { className: 'w2p-cards-project', text: card.project_name }));
        }
        if (fields.task && card.task) {
            parts.push(el('div', { className: 'w2p-cards-chip', text: 'Task #' + card.task + ' ' + card.task_name }));
        }
        if (fields.dependencies && card.waits_on.length) {
            parts.push(el('div', {
                className: 'w2p-cards-blocked',
                text: 'Waiting on ' + card.waits_on.map(function (w) { return w.key; }).join(', ')
            }));
        }

        var meta = el('div', { className: 'w2p-cards-meta' });
        if (fields.key) { meta.appendChild(el('span', { text: card.key })); }
        if (fields.priority && card.priority === 1) { meta.appendChild(el('span', { className: 'w2p-cards-high', text: '▲ High' })); }
        if (fields.priority && card.priority === -1) { meta.appendChild(el('span', { text: '▼ Low' })); }
        if (fields.due && card.due) { meta.appendChild(el('span', { text: 'Due ' + card.due })); }
        if (fields.subcards && card.subcards) { meta.appendChild(el('span', { text: '☐ ' + card.subcards_done + '/' + card.subcards })); }
        if (fields.owner && card.owner) {
            var owner = this.users[card.owner];
            var name = owner ? owner.name : '#' + card.owner;
            meta.appendChild(el('span', { className: 'w2p-cards-avatar', title: name, text: initials(name) }));
        }
        parts.push(meta);

        return el('article', {
            className: 'w2p-cards-card' + (card.id === this.openCardId ? ' is-open' : ''),
            'data-id': String(card.id),
            tabindex: '0',
            role: 'button',
            'aria-label': card.key + ' ' + card.name,
            onClick: function () { self.openCard(card.id); },
            onKeydown: function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); self.openCard(card.id); }
            }
        }, parts);
    };

    // ---------------------------------------------------------------- drop

    Board.prototype.onDrop = function (evt) {
        var self = this;
        var cardId = parseInt(evt.item.getAttribute('data-id'), 10);
        var card = this.cards[cardId];
        var from = evt.from;
        var to = evt.to;
        if (!card || (from === to && evt.oldIndex === evt.newIndex)) {
            return;
        }
        var field = to.getAttribute('data-field');
        var value = parseInt(to.getAttribute('data-value'), 10);
        // Order is kept per project, so the neighbours that count are the
        // nearest cards of the same project (all of them, on a project board).
        var sameProject = function (node) {
            var other = node && self.cards[parseInt(node.getAttribute('data-id'), 10)];
            return !other || other.project === card.project;
        };
        var prev = evt.item.previousElementSibling;
        while (prev && !sameProject(prev)) { prev = prev.previousElementSibling; }
        var next = evt.item.nextElementSibling;
        while (next && !sameProject(next)) { next = next.nextElementSibling; }
        var payload = {
            card_id: cardId,
            version: card.version,
            group: field,
            value: from === to ? null : value,
            before: prev ? parseInt(prev.getAttribute('data-id'), 10) : 0,
            after: next ? parseInt(next.getAttribute('data-id'), 10) : 0
        };
        if (field === 'tag' && from !== to) {
            payload.from_tag = parseInt(from.getAttribute('data-value'), 10);
        }

        var checks = Promise.resolve(true);
        if (field === 'status' && from !== to) {
            var status = this.statuses[value];
            if (status && status.category === CLOSED) {
                if (card.waits_on.length) {
                    checks = checks.then(function (ok) {
                        return ok && self.confirmDialog(
                            card.key + ' ' + card.name,
                            'Still waiting on ' + card.waits_on.map(function (w) { return w.key; }).join(', ') + '.',
                            'Move anyway');
                    });
                }
                var open = card.subcards - card.subcards_done;
                if (open > 0) {
                    checks = checks.then(function (ok) {
                        return ok && self.subcardsDialog(card, open).then(function (choice) {
                            if (choice === 'all') { payload.close_subcards = 1; }
                            return choice !== null;
                        });
                    });
                }
            }
        }

        checks.then(function (ok) {
            if (!ok) {
                self.render();
                return;
            }
            self.change('move', payload).catch(function () { /* change() already reported it */ });
        });
    };

    // -------------------------------------------------------------- dialogs

    Board.prototype.dialog = function (title, body, buttons) {
        var self = this;
        return new Promise(function (resolve) {
            var previous = document.activeElement;
            var overlay = el('div', { className: 'w2p-cards-overlay' });
            var box = el('div', { className: 'w2p-cards-dialog', role: 'dialog', 'aria-modal': 'true', 'aria-label': title });
            var close = function (result) {
                overlay.remove();
                self.activeDialog = null;
                if (previous && previous.focus) { previous.focus(); }
                resolve(result);
            };
            box.appendChild(el('h3', { text: title }));
            body.forEach(function (node) { box.appendChild(node); });
            var row = el('div', { className: 'w2p-cards-dialog-buttons' });
            buttons.forEach(function (b) {
                row.appendChild(el('button', {
                    type: 'button',
                    className: 'w2p-cards-button' + (b.primary ? ' is-primary' : ''),
                    text: b.label,
                    onClick: function () {
                        var result = b.value;
                        if (typeof result === 'function') {
                            result = result();
                            if (result === false) { return; }
                        }
                        close(result);
                    }
                }));
            });
            box.appendChild(row);
            overlay.appendChild(box);
            overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(null); } });
            document.body.appendChild(overlay);
            self.activeDialog = { close: close };
            var first = box.querySelector('input, select, textarea, button.is-primary, button');
            if (first) { first.focus(); }
        });
    };

    Board.prototype.confirmDialog = function (title, message, okLabel) {
        return this.dialog(title, [el('p', { text: message })], [
            { label: okLabel, value: true, primary: true },
            { label: 'Cancel', value: false }
        ]).then(function (r) { return r === true; });
    };

    Board.prototype.subcardsDialog = function (card, open) {
        var parentOnly = el('input', { type: 'radio', name: 'w2p-cards-close', value: 'parent', checked: true });
        var all = el('input', { type: 'radio', name: 'w2p-cards-close', value: 'all' });
        return this.dialog(card.key + ' ' + card.name, [
            el('p', { text: open + ' of ' + card.subcards + ' subcards are still open.' }),
            el('label', { className: 'w2p-cards-radio' }, [parentOnly, ' Move this card only']),
            el('label', { className: 'w2p-cards-radio' }, [all, ' Move this card and its open subcards'])
        ], [
            { label: 'Move', primary: true, value: function () { return all.checked ? 'all' : 'parent'; } },
            { label: 'Cancel', value: null }
        ]);
    };

    Board.prototype.newCardDialog = function (preset) {
        var self = this;
        var name = el('input', { type: 'text', maxlength: '255', 'aria-label': 'Card name' });
        var status = el('select', { 'aria-label': 'Status' }, this.data.statuses.map(function (s) {
            return option(s.id, s.name, preset.status ? s.id === preset.status : false);
        }));
        var error = el('p', { className: 'w2p-cards-error' });
        var body = [
            el('label', { className: 'w2p-cards-field' }, ['Name', name]),
            el('label', { className: 'w2p-cards-field' }, ['Status', status])
        ];
        // On the all-projects board, pick the project (the task can be linked
        // from the card afterwards); on a project board, optionally a task.
        var project = null;
        var task = null;
        if (this.isAll) {
            project = el('select', { 'aria-label': 'Project' }, [option('', 'Pick a project', true)].concat(
                this.data.projects.map(function (pr) { return option(pr.id, pr.name); })));
            body.unshift(el('label', { className: 'w2p-cards-field' }, ['Project', project]));
        } else {
            task = el('select', { 'aria-label': 'Linked task' }, [option(0, 'No task', !preset.task)].concat(
                this.data.tasks.map(function (t) { return option(t.id, '#' + t.id + ' ' + t.name, t.id === preset.task); })));
            body.push(el('label', { className: 'w2p-cards-field' }, ['Linked task (optional)', task]));
        }
        body.push(error);
        this.dialog('New card', body, [
            {
                label: 'Create', primary: true, value: function () {
                    if (project && !project.value) { error.textContent = 'Pick a project.'; project.focus(); return false; }
                    if (!name.value.trim()) { error.textContent = 'Give the card a name.'; name.focus(); return false; }
                    var fields = { name: name.value, status: parseInt(status.value, 10) };
                    if (task) { fields.task = parseInt(task.value, 10); }
                    return { projectId: project ? parseInt(project.value, 10) : self.data.project.id, fields: fields };
                }
            },
            { label: 'Cancel', value: null }
        ]).then(function (result) {
            if (!result) { return; }
            // The new card shows on the board; it opens with another click.
            self.change('create', { project_id: result.projectId, fields: result.fields }).then(function (data) {
                if (data.created) { self.toast('Card created.'); }
            }).catch(function () {});
        });
    };

    Board.prototype.inlineAdd = function (section, column) {
        var self = this;
        var input = el('input', { type: 'text', className: 'w2p-cards-inline', placeholder: 'Card name, then Enter', maxlength: '255', 'aria-label': 'New card name' });
        var addButton = section.querySelector('.w2p-cards-add');
        addButton.replaceWith(input);
        input.focus();
        var done = false;
        var finish = function (save) {
            if (done) { return; }
            done = true;
            var name = input.value.trim();
            if (!save || !name) { self.render(); return; }
            var fields = { name: name };
            if (column.field === 'tag') {
                fields.status = self.data.statuses[0].id;
            } else {
                fields[column.field] = column.value;
            }
            self.change('create', { project_id: self.data.project.id, fields: fields }).then(function (data) {
                if (column.field === 'tag' && column.value && data.created) {
                    var tag = self.tags[column.value];
                    if (tag) { self.change('tag_add', { card_id: data.created, name: tag.name }).catch(function () {}); }
                }
            }).catch(function () {});
        };
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); finish(true); }
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); finish(false); }
        });
        input.addEventListener('blur', function () { finish(true); });
    };

    Board.prototype.settingsDialog = function () {
        var self = this;
        var settings = this.data.settings;
        var rows = this.data.statuses.map(function (s) {
            var col = settings.columns[s.id] || { wip: 0, shown: true };
            var shown = el('input', { type: 'checkbox', checked: col.shown, 'aria-label': 'Show ' + s.name });
            var wip = el('input', { type: 'number', min: '0', max: '999', value: col.wip ? String(col.wip) : '', placeholder: 'none', 'aria-label': 'WIP limit for ' + s.name });
            return { id: s.id, shown: shown, wip: wip, row: el('tr', {}, [el('td', { text: s.name }), el('td', {}, [wip]), el('td', {}, [shown])]) };
        });
        var labels = { key: 'Card key', owner: 'Owner', priority: 'Priority', due: 'Due date', tags: 'Tags', subcards: 'Subcard progress', dependencies: 'Dependency badge', task: 'Linked task', project: 'Project name (all-projects view)' };
        var fieldBoxes = Object.keys(labels).map(function (key) {
            var box = el('input', { type: 'checkbox', checked: settings.fields[key] });
            return { key: key, box: box, label: el('label', { className: 'w2p-cards-check' }, [box, ' ' + labels[key]]) };
        });
        var hint = this.isAll
            ? 'The default board settings: the all-projects view uses them, and so does every project that hasn\'t saved its own. WIP limits apply on project boards only.'
            : 'Statuses are one global list. WIP limits are soft: a column turns amber at its limit and red above it; moves are never blocked.';
        var body = [
            el('p', { className: 'w2p-cards-hint', text: hint }),
            el('table', { className: 'w2p-cards-settings' }, [
                el('thead', {}, [el('tr', {}, [el('th', { text: 'Column' }), el('th', { text: 'WIP limit' }), el('th', { text: 'Shown' })])]),
                el('tbody', {}, rows.map(function (r) { return r.row; }))
            ]),
            el('h4', { text: 'Show on cards' }),
            el('div', { className: 'w2p-cards-fieldlist' }, fieldBoxes.map(function (f) { return f.label; }))
        ];
        if (this.data.can.statuses) {
            body.push(el('p', {}, [el('a', { href: this.options.statusesUrl, text: 'Edit the global status list' })]));
        }
        this.dialog(this.isAll ? 'Default board settings' : 'Board settings', body, [
            {
                label: 'Save', primary: true, value: function () {
                    var out = { columns: {}, fields: {} };
                    rows.forEach(function (r) { out.columns[r.id] = { wip: parseInt(r.wip.value, 10) || 0, shown: r.shown.checked }; });
                    fieldBoxes.forEach(function (f) { out.fields[f.key] = f.box.checked; });
                    return out;
                }
            },
            { label: 'Cancel', value: null }
        ]).then(function (out) {
            if (out) {
                self.change('settings', { project_id: self.isAll ? 0 : self.data.project.id, settings: out }).then(function () {
                    self.toast('Board settings saved.');
                }).catch(function () {});
            }
        });
    };

    // -------------------------------------------------------------- drawer

    Board.prototype.openCard = function (cardId) {
        var self = this;
        this.openCardId = cardId;
        this.root.querySelectorAll('.w2p-cards-card.is-open').forEach(function (n) { n.classList.remove('is-open'); });
        var node = this.root.querySelector('.w2p-cards-card[data-id="' + cardId + '"]');
        if (node) { node.classList.add('is-open'); }
        this.api('card', { card_id: cardId }).then(function (data) {
            self.cardData = data.card;
            self.renderDrawer();
        }).catch(function (err) {
            self.openCardId = 0;
            self.toast(err.message, true);
        });
    };

    Board.prototype.closeDrawer = function () {
        this.openCardId = 0;
        this.cardData = null;
        if (this.drawer) {
            this.drawer.remove();
            this.drawer = null;
        }
        this.root.querySelectorAll('.w2p-cards-card.is-open').forEach(function (n) { n.classList.remove('is-open'); });
    };

    Board.prototype.onKey = function (e) {
        if (e.key !== 'Escape') { return; }
        if (this.activeDialog) {
            this.activeDialog.close(null);
        } else if (this.drawer && !e.defaultPrevented) {
            this.closeDrawer();
        }
    };

    Board.prototype.renderDrawer = function () {
        var self = this;
        var card = this.cardData;
        if (!card) { return; }
        var can = this.data.can;
        var edit = can.edit;
        var focusedName = document.activeElement && document.activeElement.getAttribute('data-focus');

        var setField = function (field, value) {
            var fields = {};
            fields[field] = value;
            return self.change('update', { card_id: card.id, version: card.version, fields: fields }).catch(function () {});
        };

        if (!this.drawer) {
            this.drawer = el('aside', { className: 'w2p-cards-drawer', 'aria-label': 'Card details' });
            document.body.appendChild(this.drawer);
        }
        var drawer = this.drawer;
        var scroll = drawer.scrollTop;
        drawer.textContent = '';

        // Header
        var name = el('input', {
            type: 'text', className: 'w2p-cards-title', value: card.name, maxlength: '255',
            'aria-label': 'Card name', disabled: !edit, 'data-focus': 'name'
        });
        name.addEventListener('change', function () {
            if (name.value.trim() && name.value !== card.name) { setField('name', name.value); }
        });
        drawer.appendChild(el('div', { className: 'w2p-cards-drawer-head' }, [
            el('div', { className: 'w2p-cards-drawer-key', text: card.project_name + ' › ' + card.key }),
            el('button', { type: 'button', className: 'w2p-cards-close', 'aria-label': 'Close', text: '×', onClick: function () { self.closeDrawer(); } })
        ]));
        drawer.appendChild(name);

        // Fields
        var grid = el('div', { className: 'w2p-cards-grid' });
        var addField = function (label, control) {
            grid.appendChild(el('label', { className: 'w2p-cards-field' }, [label, control]));
        };
        addField('Status', el('select', { disabled: !edit, onChange: function () { self.moveFromDrawer(card, parseInt(this.value, 10)); } },
            this.data.statuses.map(function (s) { return option(s.id, s.name, s.id === card.status); })));
        addField('Priority', el('select', { disabled: !edit, onChange: function () { setField('priority', parseInt(this.value, 10)); } },
            PRIORITIES.map(function (p) { return option(p.value, p.label, p.value === card.priority); })));
        addField('Owner', el('select', { disabled: !edit, onChange: function () { setField('owner', parseInt(this.value, 10)); } },
            [option(0, 'Unassigned', !card.owner)].concat(this.data.users.map(function (u) { return option(u.id, u.name, u.id === card.owner); }))));
        addField('Due date', el('input', { type: 'date', value: card.due, disabled: !edit, onChange: function () { setField('due', this.value); } }));
        var taskSelect = el('select', { disabled: !edit, onChange: function () { setField('task', parseInt(this.value, 10)); } },
            [option(0, 'No task', !card.task)].concat((card.tasks || []).map(function (t) { return option(t.id, '#' + t.id + ' ' + t.name, t.id === card.task); })));
        addField('Linked task', taskSelect);
        grid.appendChild(el('div', { className: 'w2p-cards-field' }, [
            'Project',
            el('span', { className: 'w2p-cards-static', text: card.project_name + ' (moves only with its linked task)' })
        ]));
        drawer.appendChild(grid);
        if (card.task) {
            drawer.appendChild(el('p', { className: 'w2p-cards-hint' }, [el('a', { href: this.options.taskUrl + card.task, text: 'Open task #' + card.task })]));
        }

        // Tags
        var tagInput = el('input', { type: 'text', list: 'w2p-cards-taglist', placeholder: 'Add tag', 'aria-label': 'Add tag', maxlength: '64', disabled: !edit });
        tagInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && tagInput.value.trim()) {
                e.preventDefault();
                self.change('tag_add', { card_id: card.id, name: tagInput.value }).catch(function () {});
            }
        });
        drawer.appendChild(this.section('Tags', [
            el('div', { className: 'w2p-cards-tags' }, card.tags.map(function (id) {
                var tagName = card.tag_names[id] || (self.tags[id] && self.tags[id].name) || '#' + id;
                return el('span', { className: 'w2p-cards-tag' }, [tagName, edit ? el('button', {
                    type: 'button', className: 'w2p-cards-x', 'aria-label': 'Remove tag ' + tagName, text: '×',
                    onClick: function () { self.change('tag_remove', { card_id: card.id, tag: id }).catch(function () {}); }
                }) : null]);
            }).concat([edit ? tagInput : null])),
            el('datalist', { id: 'w2p-cards-taglist' }, this.data.tags.map(function (t) { return option(t.name, t.name); }))
        ]));

        // Description
        var description = el('textarea', { rows: '4', 'aria-label': 'Description', disabled: !edit, 'data-focus': 'description' });
        description.value = card.description;
        description.addEventListener('change', function () {
            if (description.value !== card.description) { setField('description', description.value); }
        });
        drawer.appendChild(this.section('Description', [description]));

        // Parent and subcards
        var related = [];
        if (card.parent_card) {
            related.push(el('p', {}, [
                'Subcard of ',
                this.cardLink(card.parent_card),
                edit ? el('button', { type: 'button', className: 'w2p-cards-link', text: 'Detach', onClick: function () {
                    self.change('parent', { card_id: card.id, parent: 0 }).catch(function () {});
                } }) : null
            ]));
        }
        card.subcard_list.forEach(function (sub) {
            related.push(el('div', { className: 'w2p-cards-row' }, [
                self.cardLink(sub),
                el('span', { className: 'w2p-cards-muted', text: self.statuses[sub.status] ? self.statuses[sub.status].name : '' })
            ]));
        });
        if (can.add) {
            var subInput = el('input', { type: 'text', placeholder: '+ Add subcard', 'aria-label': 'Add subcard', maxlength: '255' });
            subInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && subInput.value.trim()) {
                    e.preventDefault();
                    self.change('create', { project_id: card.project, parent: card.id, fields: { name: subInput.value, status: card.status, task: card.task } }).catch(function () {});
                }
            });
            related.push(subInput);
        }
        if (edit) {
            related.push(this.cardPicker('Move under another card', card, function (id) {
                self.change('parent', { card_id: card.id, parent: id }).catch(function () {});
            }));
        }
        drawer.appendChild(this.section(card.subcards ? 'Subcards (' + card.subcards_done + ' of ' + card.subcards + ' done)' : 'Subcards', related));

        // Dependencies
        var deps = [];
        var depRow = function (other, requiresId, cardId) {
            return el('div', { className: 'w2p-cards-row' }, [
                self.cardLink(other),
                el('span', { className: 'w2p-cards-muted', text: self.statuses[other.status] ? self.statuses[other.status].name : '' }),
                edit ? el('button', { type: 'button', className: 'w2p-cards-x', 'aria-label': 'Remove dependency', text: '×', onClick: function () {
                    self.change('dependency_remove', { card_id: cardId, requires: requiresId }).catch(function () {});
                } }) : null
            ]);
        };
        deps.push(el('h4', { text: 'Waiting on' }));
        card.waits_on_list.forEach(function (other) { deps.push(depRow(other, other.id, card.id)); });
        if (!card.waits_on_list.length) { deps.push(el('p', { className: 'w2p-cards-muted', text: 'Nothing.' })); }
        deps.push(el('h4', { text: 'Blocks' }));
        card.blocks_list.forEach(function (other) { deps.push(depRow(other, card.id, other.id)); });
        if (!card.blocks_list.length) { deps.push(el('p', { className: 'w2p-cards-muted', text: 'Nothing.' })); }
        if (card.hidden_relations > 0) {
            deps.push(el('p', { className: 'w2p-cards-muted', text: card.hidden_relations + ' more on cards you can\'t see.' }));
        }
        if (edit) {
            deps.push(this.cardPicker('This card waits on…', card, function (id) {
                self.change('dependency_add', { card_id: card.id, requires: id }).catch(function () {});
            }));
        }
        drawer.appendChild(this.section('Dependencies', deps));

        // Activity
        var activity = [];
        if (edit) {
            var comment = el('textarea', { rows: '2', placeholder: 'Write a comment', 'aria-label': 'Comment', 'data-focus': 'comment' });
            activity.push(comment);
            activity.push(el('button', { type: 'button', className: 'w2p-cards-button', text: 'Comment', onClick: function () {
                if (comment.value.trim()) {
                    self.change('comment', { card_id: card.id, text: comment.value }).catch(function () {});
                }
            } }));
        }
        card.activity.forEach(function (a) {
            activity.push(el('div', { className: 'w2p-cards-activity' + (a.type === 'comment' ? ' is-comment' : '') }, [
                el('span', { className: 'w2p-cards-avatar', title: a.user_name, text: a.user ? initials(a.user_name) : 'S' }),
                el('div', {}, [
                    el('div', { className: 'w2p-cards-activity-text', text: a.user_name + ' ' + self.describe(a) }),
                    el('div', { className: 'w2p-cards-muted', text: a.date + ' UTC' })
                ])
            ]));
        });
        drawer.appendChild(this.section('Activity', activity));

        if (can['delete']) {
            drawer.appendChild(el('div', { className: 'w2p-cards-drawer-foot' }, [
                el('button', { type: 'button', className: 'w2p-cards-button is-danger', text: 'Delete card', onClick: function () {
                    var message = card.subcards ? 'This also deletes its ' + card.subcards + ' subcard(s).' : 'This can\'t be undone.';
                    self.confirmDialog('Delete ' + card.key + '?', message, 'Delete').then(function (ok) {
                        if (ok) {
                            self.change('delete', { card_id: card.id }).then(function () { self.closeDrawer(); }).catch(function () {});
                        }
                    });
                } })
            ]));
        }

        drawer.scrollTop = scroll;
        var refocus = focusedName && drawer.querySelector('[data-focus="' + focusedName + '"]');
        if (refocus) { refocus.focus(); }
    };

    /** Moving from the drawer's Status menu: same checks as a drag. */
    Board.prototype.moveFromDrawer = function (card, statusId) {
        var self = this;
        var boardCard = this.cards[card.id] || card;
        var status = this.statuses[statusId];
        var checks = Promise.resolve(true);
        var payload = { card_id: card.id, version: card.version, group: 'status', value: statusId, before: 0, after: 0 };
        // Keep its place: after the last card of the new column.
        var inColumn = this.data.cards.filter(function (c) { return c.status === statusId && c.id !== card.id && c.project === card.project; });
        if (inColumn.length) { payload.before = inColumn[inColumn.length - 1].id; }
        if (status && status.category === CLOSED) {
            if (boardCard.waits_on.length) {
                checks = checks.then(function (ok) {
                    return ok && self.confirmDialog(card.key + ' ' + card.name,
                        'Still waiting on ' + boardCard.waits_on.map(function (w) { return w.key; }).join(', ') + '.', 'Move anyway');
                });
            }
            var open = boardCard.subcards - boardCard.subcards_done;
            if (open > 0) {
                checks = checks.then(function (ok) {
                    return ok && self.subcardsDialog(boardCard, open).then(function (choice) {
                        if (choice === 'all') { payload.close_subcards = 1; }
                        return choice !== null;
                    });
                });
            }
        }
        checks.then(function (ok) {
            if (ok) {
                self.change('move', payload).catch(function () {});
            } else {
                self.renderDrawer();
            }
        });
    };

    Board.prototype.section = function (title, children) {
        return el('section', { className: 'w2p-cards-section' }, [el('h3', { text: title })].concat(children));
    };

    Board.prototype.cardLink = function (card) {
        var self = this;
        return el('button', {
            type: 'button', className: 'w2p-cards-link', text: card.key + ' ' + card.name,
            onClick: function () { self.openCard(card.id); }
        });
    };

    /** A select of the other cards in this project. */
    Board.prototype.cardPicker = function (label, card, onPick) {
        // Relationships stay inside one project.
        var others = this.data.cards.filter(function (c) { return c.id !== card.id && c.project === card.project; });
        var select = el('select', { 'aria-label': label }, [option('', label, true)].concat(
            others.map(function (c) { return option(c.id, c.key + ' ' + c.name); })));
        select.addEventListener('change', function () {
            if (select.value) { onPick(parseInt(select.value, 10)); }
        });
        return select;
    };

    var FIELD_LABELS = { name: 'the name', description: 'the description', status: 'the status', priority: 'the priority', owner: 'the owner', due: 'the due date', task: 'the linked task', parent: 'the parent card' };

    Board.prototype.describe = function (a) {
        var q = function (v) { return '"' + v + '"'; };
        switch (a.type) {
            case 'created': return 'created this card';
            case 'comment': return 'commented: ' + a['new'];
            case 'changed':
                if (a.field === 'description') { return 'changed the description'; }
                if (!a.old) { return 'set ' + (FIELD_LABELS[a.field] || a.field) + ' to ' + q(a['new']); }
                if (!a['new']) { return 'cleared ' + (FIELD_LABELS[a.field] || a.field) + ' (was ' + q(a.old) + ')'; }
                return 'changed ' + (FIELD_LABELS[a.field] || a.field) + ' from ' + q(a.old) + ' to ' + q(a['new']);
            case 'moved_project': return 'moved this card from ' + a.old + ' to ' + a['new'];
            case 'dependency_added': return a.field === 'blocks' ? 'made ' + a['new'] + ' wait on this card' : 'made this card wait on ' + a['new'];
            case 'dependency_removed':
                var why = a['new'] ? ' (' + a['new'] + ')' : '';
                return (a.field === 'blocks' ? a.old + ' no longer waits on this card' : 'this card no longer waits on ' + a.old) + why;
            case 'subcard_added': return 'added subcard ' + a['new'];
            case 'subcard_removed': return 'removed subcard ' + a.old + (a['new'] ? ' (' + a['new'] + ')' : '');
            case 'tag_added': return 'added tag ' + q(a['new']);
            case 'tag_removed': return 'removed tag ' + q(a.old);
            default: return a['new'];
        }
    };

    // --------------------------------------------------------------- start

    function start() {
        document.querySelectorAll('.w2p-cards').forEach(function (root) {
            if (root.getAttribute('data-mounted')) { return; }
            root.setAttribute('data-mounted', '1');
            var options = {};
            try { options = JSON.parse(root.getAttribute('data-options') || '{}'); } catch (e) { return; }
            new Board(root, options);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
