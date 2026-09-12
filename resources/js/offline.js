/**
 * KG Attendance — Offline Attendance client (Phase 2).
 *
 * Scope: this module only runs on the Live Attendance screen. It is not a
 * general offline framework for the app — every other page stays a plain
 * server-rendered classic-navigation page, unchanged.
 *
 * Storage: IndexedDB, one database, versioned. Only the currently
 * provisioned session's assignment/department snapshot is kept; the event
 * queue is never purged except for events the server has confirmed
 * `accepted`/`duplicate` (a durable server-side ledger — synced_events —
 * already keeps the permanent record of those).
 *
 * Server stays authoritative: this module never decides an attendance
 * outcome, it only queues an intent and reports whatever the server later
 * says happened.
 */

const DB_NAME = 'kg-attendance';
const DB_VERSION = 1;
const SYNC_BATCH_SIZE = 25;
const HEARTBEAT_URL = '/up';
const SYNC_URL = '/sync/attendance-events';
const RETRY_DELAYS_MS = [5000, 15000, 30000, 60000];

let dbPromise = null;

function openDb() {
    if (dbPromise) return dbPromise;

    dbPromise = new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, DB_VERSION);

        req.onupgradeneeded = () => {
            const db = req.result;
            if (!db.objectStoreNames.contains('provisioned_session')) {
                db.createObjectStore('provisioned_session', { keyPath: 'session_id' });
            }
            if (!db.objectStoreNames.contains('provisioned_assignments')) {
                const store = db.createObjectStore('provisioned_assignments', { keyPath: 'assignment_id' });
                store.createIndex('session_id', 'session_id');
                store.createIndex('its_id', 'its_id');
            }
            if (!db.objectStoreNames.contains('provisioned_departments')) {
                db.createObjectStore('provisioned_departments', { keyPath: 'department_id' });
            }
            if (!db.objectStoreNames.contains('local_attendance_state')) {
                db.createObjectStore('local_attendance_state', { keyPath: 'assignment_id' });
            }
            if (!db.objectStoreNames.contains('event_queue')) {
                const store = db.createObjectStore('event_queue', { keyPath: 'event_id' });
                store.createIndex('session_id', 'session_id');
                store.createIndex('status', 'status');
            }
            if (!db.objectStoreNames.contains('device')) {
                db.createObjectStore('device', { keyPath: 'key' });
            }
        };

        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });

    return dbPromise;
}

function tx(db, stores, mode = 'readonly') {
    return db.transaction(stores, mode);
}

function reqToPromise(req) {
    return new Promise((resolve, reject) => {
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

function uuid() {
    if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
    // Fallback for older WebViews without crypto.randomUUID.
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        const v = c === 'x' ? r : (r & 0x3) | 0x8;
        return v.toString(16);
    });
}

async function getDeviceId() {
    const db = await openDb();
    const t = tx(db, ['device'], 'readwrite');
    const store = t.objectStore('device');
    const existing = await reqToPromise(store.get('device_id'));
    if (existing) return existing.value;

    const id = uuid();
    await reqToPromise(store.put({ key: 'device_id', value: id }));
    return id;
}

async function nextLocalSequence() {
    const db = await openDb();
    const t = tx(db, ['device'], 'readwrite');
    const store = t.objectStore('device');
    const current = await reqToPromise(store.get('local_sequence'));
    const next = (current ? current.value : 0) + 1;
    await reqToPromise(store.put({ key: 'local_sequence', value: next }));
    return next;
}

class OfflineAttendance {
    constructor({ sessionId, userId, csrfToken }) {
        this.sessionId = sessionId;
        this.userId = userId;
        this.csrfToken = csrfToken;
        this.listeners = new Set();
        this.retryAttempt = 0;
        this.syncTimer = null;
    }

    onChange(fn) {
        this.listeners.add(fn);
        return () => this.listeners.delete(fn);
    }

    notify() {
        this.listeners.forEach((fn) => fn(this.getStatus()));
    }

    getStatus() {
        return {
            online: navigator.onLine,
            serverReachable: this._serverReachable !== false,
        };
    }

    /**
     * Downloads the current session's assignments/departments and stores
     * them locally. Safe to call repeatedly — replaces the prior snapshot
     * for THIS session only; queued-but-unsynced events (for this or any
     * other session) are never touched by provisioning.
     */
    async provision() {
        const res = await fetch(`/sessions/${this.sessionId}/offline/provision`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!res.ok) {
            throw new Error('provisioning_failed_' + res.status);
        }

        const data = await res.json();
        const db = await openDb();
        const t = tx(db, ['provisioned_session', 'provisioned_assignments', 'provisioned_departments', 'local_attendance_state'], 'readwrite');

        t.objectStore('provisioned_session').put({
            session_id: data.session.id,
            ...data.session,
            package_version: data.package_version,
            provisioned_at: data.provisioned_at,
        });

        // Clear this session's prior snapshot before writing the fresh one.
        const assignmentsStore = t.objectStore('provisioned_assignments');
        const idx = assignmentsStore.index('session_id');
        const existingKeys = await reqToPromise(idx.getAllKeys(IDBKeyRange.only(data.session.id)));
        existingKeys.forEach((k) => assignmentsStore.delete(k));

        const stateStore = t.objectStore('local_attendance_state');

        data.assignments.forEach((a) => {
            assignmentsStore.put({ ...a, session_id: data.session.id });
            stateStore.put({ assignment_id: a.assignment_id, status: a.current_status, source: 'provisioned' });
        });

        const deptStore = t.objectStore('provisioned_departments');
        data.departments.forEach((d) => deptStore.put(d));

        await new Promise((resolve, reject) => {
            t.oncomplete = resolve;
            t.onerror = reject;
        });

        // A reload right after provisioning must not show stale statuses for
        // assignments that already have queued-but-unsynced events from an
        // earlier visit — replay the queue on top of the fresh snapshot.
        await this._reapplyQueueToLocalState();

        return data;
    }

    async _reapplyQueueToLocalState() {
        const db = await openDb();
        const events = await reqToPromise(tx(db, ['event_queue']).objectStore('event_queue').index('session_id').getAll(IDBKeyRange.only(this.sessionId)));
        const t = tx(db, ['local_attendance_state'], 'readwrite');
        const store = t.objectStore('local_attendance_state');

        events
            .filter((e) => e.status !== 'accepted' && e.status !== 'duplicate')
            .forEach((e) => {
                if (!e.assignment_id) return;
                const uiStatus = e.action === 'present' ? 'present' : e.action === 'absent' ? 'absent' : null;
                if (!uiStatus) return;
                store.put({ assignment_id: e.assignment_id, status: uiStatus, source: 'queued', queue_status: e.status });
            });
    }

    /** Search provisioned assignments by ITS (exact) or name (substring) — instant, works offline. */
    async search({ its, name }) {
        const db = await openDb();
        const all = await reqToPromise(tx(db, ['provisioned_assignments']).objectStore('provisioned_assignments').index('session_id').getAll(IDBKeyRange.only(this.sessionId)));
        const state = await this._loadState(db);

        let matches;
        if (its) {
            matches = all.filter((a) => a.its_id === its);
        } else if (name) {
            const q = name.toLowerCase();
            matches = all.filter((a) => a.full_name.toLowerCase().includes(q));
        } else {
            matches = [];
        }

        return matches.map((a) => ({ ...a, current_status: state[a.assignment_id] || a.current_status }));
    }

    async _loadState(db) {
        const rows = await reqToPromise(tx(db, ['local_attendance_state']).objectStore('local_attendance_state').getAll());
        const map = {};
        rows.forEach((r) => { map[r.assignment_id] = r.status; });
        return map;
    }

    /**
     * Queue a present/absent action. Local validation only (assignment must
     * be in the provisioned snapshot) — server re-validates everything on
     * sync, this is purely for a fast, honest local UI.
     */
    async markAttendance(assignmentId, action, remark) {
        const db = await openDb();
        const assignment = await reqToPromise(tx(db, ['provisioned_assignments']).objectStore('provisioned_assignments').get(assignmentId));
        if (!assignment) {
            throw new Error('assignment_not_provisioned');
        }

        const event = {
            event_id: uuid(),
            session_id: this.sessionId,
            assignment_id: assignmentId,
            khidmatguzar_id: assignment.khidmatguzar_id,
            operator_user_id: this.userId,
            device_id: await getDeviceId(),
            action,
            context: 'individual',
            payload: remark ? { remark } : null,
            local_sequence: await nextLocalSequence(),
            local_timestamp: new Date().toISOString(),
            status: 'queued',
            server_result: null,
            synced_at: null,
        };

        await this._enqueue(event);
        await this._setLocalState(assignmentId, action);
        this.notify();
        this._scheduleSyncSoon();

        return event;
    }

    async markExtraPresent({ its, fullName, gender, departmentId, remark }) {
        const event = {
            event_id: uuid(),
            session_id: this.sessionId,
            assignment_id: null,
            khidmatguzar_id: null,
            operator_user_id: this.userId,
            device_id: await getDeviceId(),
            action: 'extra_present',
            context: 'individual',
            payload: { its, full_name: fullName, gender, department_id: departmentId, remark },
            local_sequence: await nextLocalSequence(),
            local_timestamp: new Date().toISOString(),
            status: 'queued',
            server_result: null,
            synced_at: null,
        };

        await this._enqueue(event);
        this.notify();
        this._scheduleSyncSoon();

        return event;
    }

    async _enqueue(event) {
        const db = await openDb();
        await reqToPromise(tx(db, ['event_queue'], 'readwrite').objectStore('event_queue').put(event));
    }

    async _setLocalState(assignmentId, action) {
        const db = await openDb();
        const status = action === 'present' ? 'present' : 'absent';
        await reqToPromise(tx(db, ['local_attendance_state'], 'readwrite').objectStore('local_attendance_state').put({ assignment_id: assignmentId, status, source: 'queued', queue_status: 'queued' }));
    }

    async queueSummary() {
        const db = await openDb();
        const all = await reqToPromise(tx(db, ['event_queue']).objectStore('event_queue').getAll());
        const summary = { queued: 0, syncing: 0, conflict: 0, rejected: 0, operator_mismatch: 0, blocked: 0 };
        all.forEach((e) => {
            if (e.status === 'queued') summary.queued++;
            else if (e.status === 'syncing') summary.syncing++;
            else if (e.status === 'conflict') summary.conflict++;
            else if (e.status === 'rejected') summary.rejected++;
            else if (e.status === 'operator_mismatch') summary.operator_mismatch++;
            else if (e.status === 'blocked_no_owner' || e.status === 'blocked_wrong_operator') summary.blocked++;
        });
        return summary;
    }

    _scheduleSyncSoon() {
        if (this.syncTimer) return;
        this.syncTimer = setTimeout(() => {
            this.syncTimer = null;
            this.sync();
        }, 300);
    }

    /** navigator.onLine is a necessary but not sufficient signal — confirm the server actually answers before trusting "online". */
    async pingServer() {
        if (!navigator.onLine) {
            this._serverReachable = false;
            return false;
        }
        try {
            const ctrl = new AbortController();
            const timeout = setTimeout(() => ctrl.abort(), 4000);
            const res = await fetch(HEARTBEAT_URL, { method: 'GET', cache: 'no-store', signal: ctrl.signal });
            clearTimeout(timeout);
            this._serverReachable = res.ok;
            return res.ok;
        } catch (e) {
            this._serverReachable = false;
            return false;
        }
    }

    /**
     * Sync every queued/retryable event whose claimed operator matches the
     * currently logged-in user. Events queued by a different user on this
     * device are never sent — they stay visible as "blocked" until that
     * user logs back in, exactly per the operator-identity requirement.
     */
    async sync({ manual = false } = {}) {
        const reachable = await this.pingServer();
        if (!reachable) {
            this.notify();
            return { synced: false, reason: 'offline' };
        }

        const db = await openDb();
        const all = await reqToPromise(tx(db, ['event_queue']).objectStore('event_queue').getAll());

        // An event's operator_user_id is set once, at creation, by whichever
        // module created it (this module always stamps this.userId; the
        // static offline-fallback shell never creates attendance events at
        // all — see public/offline.html). It is never reassigned here. An
        // event with no owner, or an owner other than the currently
        // logged-in user, is never sent — it stays visibly blocked until the
        // correct operator is the one signed in on this device.
        const ownEvents = all.filter((e) => e.status === 'queued' && e.operator_user_id === this.userId);
        const blockedEvents = all.filter((e) => e.status === 'queued' && e.operator_user_id !== this.userId);
        if (blockedEvents.length) {
            const t = tx(db, ['event_queue'], 'readwrite');
            blockedEvents.forEach((e) => t.objectStore('event_queue').put({ ...e, status: e.operator_user_id === null ? 'blocked_no_owner' : 'blocked_wrong_operator' }));
        }

        const batch = ownEvents.slice(0, SYNC_BATCH_SIZE);
        if (batch.length === 0) {
            this.retryAttempt = 0;
            this.notify();
            return { synced: true, count: 0 };
        }

        const markSyncing = tx(db, ['event_queue'], 'readwrite');
        batch.forEach((e) => markSyncing.objectStore('event_queue').put({ ...e, status: 'syncing' }));
        this.notify();

        let json;
        try {
            const res = await fetch(SYNC_URL, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                },
                body: JSON.stringify({ events: batch }),
            });

            if (res.status === 401 || res.status === 419) {
                // Session expired. Nothing is discarded — revert to queued
                // and stop retrying until the operator logs back in.
                await this._revertToQueued(db, batch);
                this._authExpired = true;
                this.notify();
                return { synced: false, reason: 'auth_expired' };
            }

            if (!res.ok) {
                await this._revertToQueued(db, batch);
                this._scheduleRetry();
                this.notify();
                return { synced: false, reason: 'http_' + res.status };
            }

            json = await res.json();
        } catch (e) {
            await this._revertToQueued(db, batch);
            this._scheduleRetry();
            this.notify();
            return { synced: false, reason: 'network_error' };
        }

        const results = json.results || {};
        const t = tx(db, ['event_queue', 'local_attendance_state'], 'readwrite');
        const eventStore = t.objectStore('event_queue');
        const stateStore = t.objectStore('local_attendance_state');

        batch.forEach((e) => {
            const result = results[e.event_id];
            if (!result) {
                // Server didn't return a result for this one — treat as
                // retryable, never assume success or failure silently.
                eventStore.put({ ...e, status: 'queued' });
                return;
            }

            const status = result.status === 'accepted' || result.status === 'duplicate' ? 'synced'
                : result.status === 'retryable_failure' ? 'queued'
                    : result.status; // conflict | rejected | operator_mismatch

            eventStore.put({ ...e, status, server_result: result.detail || result.status, synced_at: status === 'synced' ? new Date().toISOString() : null });

            if (e.assignment_id && (status === 'conflict' || status === 'rejected')) {
                stateStore.put({ assignment_id: e.assignment_id, status: e.action === 'present' ? 'present' : 'absent', source: 'queued', queue_status: status });
            }
        });

        await new Promise((resolve, reject) => { t.oncomplete = resolve; t.onerror = reject; });

        this.retryAttempt = 0;
        this.notify();

        // More queued events may remain (batch cap) — keep going.
        const remaining = await reqToPromise(tx(db, ['event_queue']).objectStore('event_queue').index('status').getAll(IDBKeyRange.only('queued')));
        if (remaining.length > 0) {
            this._scheduleSyncSoon();
        }

        return { synced: true, count: batch.length };
    }

    async _revertToQueued(db, batch) {
        const t = tx(db, ['event_queue'], 'readwrite');
        batch.forEach((e) => t.objectStore('event_queue').put({ ...e, status: 'queued' }));
        await new Promise((resolve, reject) => { t.oncomplete = resolve; t.onerror = reject; });
    }

    _scheduleRetry() {
        const delay = RETRY_DELAYS_MS[Math.min(this.retryAttempt, RETRY_DELAYS_MS.length - 1)];
        this.retryAttempt++;
        setTimeout(() => this.sync(), delay);
    }

    startAutoSync() {
        window.addEventListener('online', () => this.sync());
        setInterval(() => {
            if (navigator.onLine) this.sync();
        }, 30000);
    }
}

window.KGOffline = { OfflineAttendance };
