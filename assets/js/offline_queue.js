// assets/js/offline_queue.js
//
// Holds a farmer's damage report on the phone when there is no signal, and
// sends it by itself once there is. Scope is deliberately narrow: NEW reports
// only, for the farmer's report form. It does not make any page work offline -
// the dashboard still has to be loaded while online, because session_guard.js
// gates at page load and auth.html cannot load without a connection either.
//
// Why IndexedDB and not localStorage: photos. localStorage is ~5MB, strings
// only, and base64 inflates a photo by a third. One phone photo would blow it.
//
// Usage, after the page's own translations are defined:
//   CropGuardQueue.init({ onChange: renderBanner, onFlushed: loadDashboardData });
//
(function () {
    'use strict';

    var DB_NAME    = 'cropguard_offline';
    var DB_VERSION = 1;
    var STORE      = 'report_queue';

    // Resolved from this script's own src so it works under both XAMPP
    // (/da_system/) and Railway (domain root) - same trick session_guard.js uses.
    var SELF   = document.currentScript ? document.currentScript.src : '';
    var MARKER = 'assets/js/offline_queue.js';
    var ROOT   = (SELF && SELF.indexOf(MARKER) !== -1)
        ? SELF.slice(0, SELF.indexOf(MARKER))
        : '../../';

    var URL_SUBMIT = ROOT + 'api/dashboard/submit_report.php';
    var URL_CHECK  = ROOT + 'api/auth/session_check.php';

    var RETRY_MS    = 60 * 1000;  // safety net for when the 'online' event never fires
    var MAX_ATTEMPTS = 6;

    var opts        = {};
    var dbPromise   = null;
    var flushing    = false;
    var timer       = null;
    var knownUserId = null;   // who is signed in on this device right now

    // ── Translation, defensively ────────────────────────────────────────────
    // `translations` is page-local and this file is shared, so t() may not
    // exist, and i18n.js returns the key itself when a page has no such string.
    function translate(key, fallback) {
        try {
            if (typeof t !== 'function') return fallback;
            var value = t(key);
            return (value && value !== key) ? value : fallback;
        } catch (e) {
            return fallback;
        }
    }

    // ── IndexedDB ───────────────────────────────────────────────────────────
    function openDb() {
        if (dbPromise) return dbPromise;
        dbPromise = new Promise(function (resolve, reject) {
            if (!window.indexedDB) { reject(new Error('IndexedDB unavailable')); return; }
            var req = window.indexedDB.open(DB_NAME, DB_VERSION);
            req.onupgradeneeded = function () {
                var db = req.result;
                if (!db.objectStoreNames.contains(STORE)) {
                    db.createObjectStore(STORE, { keyPath: 'id' });
                }
            };
            req.onsuccess = function () { resolve(req.result); };
            req.onerror   = function () { reject(req.error); };
        });
        return dbPromise;
    }

    function tx(mode, fn) {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var t = db.transaction(STORE, mode);
                var store = t.objectStore(STORE);
                var out = fn(store);
                // Resolve on transaction completion, not on request success:
                // a write is only durable once the transaction commits, and
                // the farmer must never be told "saved" before that.
                t.oncomplete = function () { resolve(out && out.result !== undefined ? out.result : out); };
                t.onerror    = function () { reject(t.error); };
                t.onabort    = function () { reject(t.error); };
            });
        });
    }

    function putItem(item)  { return tx('readwrite', function (s) { return s.put(item); }); }
    function deleteItem(id) { return tx('readwrite', function (s) { return s.delete(id); }); }
    function allItems()     { return tx('readonly',  function (s) { return s.getAll(); }); }

    // ── Helpers ─────────────────────────────────────────────────────────────
    function newId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return 'cg-' + window.crypto.randomUUID();
        }
        return 'cg-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10);
    }

    // A rejected fetch means no HTTP response was obtained at all - DNS, TCP,
    // TLS, the connection dropping. ANY resolved Response, including a 500,
    // means we reached the server, so that is a real error and must surface as
    // one rather than being queued.
    function isNetworkError(err) {
        if (!err) return false;
        if (err.name === 'AbortError') return true;
        return err instanceof TypeError;
    }

    // navigator.onLine is only trustworthy in the negative. false really does
    // mean no interface. true is reported by captive portals and dead uplinks
    // alike, so it is never used to conclude the network works.
    function definitelyOffline() {
        return navigator.onLine === false;
    }

    // submit_report.php identifies an upload by its FILENAME EXTENSION
    // (pathinfo(...PATHINFO_EXTENSION)). Appending a stored Blob without a
    // filename makes the browser send it as "blob", the extension check fails,
    // and the photo is silently counted in $skipped_uploads. So every replayed
    // file must carry a name ending in an extension the server accepts.
    function safeName(file, index) {
        var name = String(file.name || '');
        if (/\.(jpe?g|png)$/i.test(name)) return name;
        var ext = (file.type === 'image/png') ? 'png' : 'jpg';
        return 'photo_' + (index + 1) + '.' + ext;
    }

    // ── The one FormData builder ────────────────────────────────────────────
    // Used by BOTH the first attempt and every replay, on purpose. If the live
    // path and the replay path were built separately they would drift, and the
    // drift would only show up on a queued report - the one nobody tests.
    function buildFormData(item) {
        var fd = new FormData();
        fd.append('id', '');                  // creates only; edits are never queued
        fd.append('client_token', item.id);

        Object.keys(item.fields).forEach(function (key) {
            fd.append(key, item.fields[key] === null || item.fields[key] === undefined ? '' : item.fields[key]);
        });

        item.files.forEach(function (f, i) {
            fd.append('images[]', f.blob, safeName(f, i));
            // Index must match the append order of images[]: the server reads
            // geo_lat_$i by its own loop position over $_FILES['images'].
            if (f.geo && f.geo.state === 'success') {
                fd.append('geo_lat_' + i, f.geo.lat);
                fd.append('geo_lng_' + i, f.geo.lng);
            }
        });

        return fd;
    }

    // ── Building a queue record ─────────────────────────────────────────────
    // Photos are COPIED into real Blobs rather than stored as the File handle
    // the input gave us. A File is a live reference to a path on disk, and on
    // Android the camera's temp file can be gone by the time we replay - which
    // would fail long after the farmer was told the report was saved.
    function buildItem(fields, files, geoList, userId) {
        var reads = files.map(function (file, i) {
            return file.arrayBuffer().then(function (buf) {
                return {
                    name: file.name,
                    type: file.type,
                    size: file.size,
                    blob: new Blob([buf], { type: file.type }),
                    geo:  (geoList && geoList[i]) || null
                };
            });
        });

        return Promise.all(reads).then(function (copies) {
            return {
                id:         newId(),
                user_id:    userId || null,
                created_at: Date.now(),
                status:     'pending',
                attempts:   0,
                last_error: '',
                fields:     fields,
                files:      copies
            };
        });
    }

    // ── Sending ─────────────────────────────────────────────────────────────
    // Deliberately NO timeout on the upload. Aborting a slow but progressing
    // upload on a weak connection is how you manufacture the exact lost-response
    // duplicate the client_token exists to prevent.
    function send(item) {
        return fetch(URL_SUBMIT, {
            method: 'POST',
            body: buildFormData(item),
            credentials: 'same-origin'
        });
    }

    // Who is signed in on this device right now. Asked once per flush, before
    // anything is uploaded: submit_report.php takes the farmer's identity from
    // $_SESSION alone and never looks at who actually queued the report, so on a
    // shared phone a report saved by one farmer would be filed under whoever
    // happened to log in next. Resolves to null when we cannot ask (offline), in
    // which case the upload itself decides, exactly as before.
    function currentSession() {
        return fetch(URL_CHECK, { cache: 'no-store', credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.status === 'active' && data.user) {
                    knownUserId = data.user.id;
                }
                return data;
            })
            .catch(function () { return null; });
    }

    // The queue is one shared box on one device, but a banner about a report is
    // private to the farmer who saved it. On a shared phone - which is the whole
    // reason the owner check exists - showing every queued report to whoever is
    // signed in tells Cleo that Jaylyn filed a report, and offers her a button to
    // send it. With five farmers on one handset nobody could tell whose reminder
    // they were looking at.
    function ownedByCurrentUser(item) {
        // Owner not established yet: show it rather than hide something real.
        if (knownUserId === null || knownUserId === undefined) return true;
        // Queued before owners were recorded - unattributable, so leave it visible.
        if (item.user_id === null || item.user_id === undefined || item.user_id === '') return true;
        return String(item.user_id) === String(knownUserId);
    }

    // Only blocks when we positively know it is someone else. An item queued
    // before this check existed has no user_id, and an unanswerable session
    // check proves nothing - neither is treated as a mismatch.
    function belongsToSomeoneElse(item, session) {
        if (!session || session.status !== 'active' || !session.user) return false;
        if (item.user_id === null || item.user_id === undefined || item.user_id === '') return false;
        return String(session.user.id) !== String(item.user_id);
    }

    function flushOne(item) {
        // Persisted before the POST so a second tab (or a reload mid-send)
        // does not start the same upload again.
        item.status = 'sending';
        return putItem(item)
            .then(function () { return send(item); })
            .then(function (res) {
                if (res.status === 401) {
                    item.status = 'blocked_auth';
                    return putItem(item).then(function () { return 'auth'; });
                }
                return res.json().then(function (data) {
                    if (data && data.status === 'success') {
                        return deleteItem(item.id).then(function () { return 'sent'; });
                    }
                    // Reached the server and it refused for a real reason.
                    item.attempts   = (item.attempts || 0) + 1;
                    item.last_error = (data && data.message) || 'Server refused the report.';
                    item.status     = item.attempts >= MAX_ATTEMPTS ? 'needs_attention' : 'pending';
                    return putItem(item).then(function () { return 'refused'; });
                }).catch(function () {
                    // Response wasn't JSON - treat as retryable, never drop.
                    item.status = 'pending';
                    return putItem(item).then(function () { return 'retry'; });
                });
            })
            .catch(function (err) {
                // Still offline, or the connection died mid-send. KEEP the item.
                item.status     = 'pending';
                item.last_error = isNetworkError(err) ? 'offline' : String(err && err.message || err);
                return putItem(item).then(function () { return 'offline'; });
            });
    }

    function flush() {
        if (flushing) return Promise.resolve();
        if (definitelyOffline()) return Promise.resolve();
        flushing = true;

        return Promise.all([allItems(), currentSession()]).then(function (results) {
            var items   = results[0];
            var session = results[1];

            var queue = (items || [])
                .filter(function (i) { return i.status !== 'needs_attention'; })
                .sort(function (a, b) { return a.created_at - b.created_at; });

            // One at a time, oldest first.
            return queue.reduce(function (chain, item) {
                return chain.then(function (stop) {
                    if (stop) return stop;

                    // Wrong farmer signed in on this phone. Hold the report -
                    // sending it now would file it under them, because the
                    // server reads the farmer from $_SESSION and nothing else.
                    if (belongsToSomeoneElse(item, session)) {
                        item.status = 'blocked_user';
                        return putItem(item).then(function () { return false; });
                    }

                    // Signed out. Don't spend the farmer's data on an upload
                    // that is certain to come back 401.
                    if (session && session.status !== 'active') {
                        item.status = 'blocked_auth';
                        return putItem(item).then(function () { return 'auth'; });
                    }

                    return flushOne(item).then(function (outcome) {
                        if (outcome === 'sent' && typeof opts.onFlushed === 'function') {
                            try { opts.onFlushed(); } catch (e) { console.error(e); }
                        }
                        // Offline or logged out - no point trying the rest now.
                        return (outcome === 'offline' || outcome === 'auth') ? outcome : false;
                    });
                });
            }, Promise.resolve(false));
        }).catch(function (err) {
            console.error('offline queue flush failed', err);
        }).then(function () {
            flushing = false;
            return notifyChange();
        });
    }

    // ── State for the page's banner ─────────────────────────────────────────
    function summary() {
        return allItems().then(function (items) {
            // Only this farmer's reports. Someone else's queued report is still
            // in the box and still protected - it is simply not their business.
            items = (items || []).filter(ownedByCurrentUser);
            return {
                total:          items.length,
                blockedAuth:    items.some(function (i) { return i.status === 'blocked_auth'; }),
                blockedUser:    items.some(function (i) { return i.status === 'blocked_user'; }),
                needsAttention: items.some(function (i) { return i.status === 'needs_attention'; })
            };
        }).catch(function () {
            return { total: 0, blockedAuth: false, blockedUser: false, needsAttention: false };
        });
    }

    function notifyChange() {
        return summary().then(function (s) {
            scheduleRetry(s.total > 0);
            if (typeof opts.onChange === 'function') {
                try { opts.onChange(s); } catch (e) { console.error(e); }
            }
            return s;
        });
    }

    // The interval only runs while something is actually waiting. The 'online'
    // event is the fast path; this is the safety net for Android, where moving
    // between wifi and cell often doesn't fire it.
    function scheduleRetry(shouldRun) {
        if (shouldRun && !timer) {
            timer = setInterval(function () { flush(); }, RETRY_MS);
        } else if (!shouldRun && timer) {
            clearInterval(timer);
            timer = null;
        }
    }

    // ── Public API ──────────────────────────────────────────────────────────
    function enqueue(fields, files, geoList, userId) {
        return buildItem(fields, files, geoList, userId)
            .then(putItem)
            .then(notifyChange);
    }

    function isAvailable() {
        return !!window.indexedDB;
    }

    function init(options) {
        opts = options || {};
        if (!isAvailable()) {
            console.warn('offline queue unavailable: no IndexedDB');
            return;
        }
        window.addEventListener('online', function () { flush(); });
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') flush();
        });
        // Establish who is signed in BEFORE the first banner render, otherwise
        // the very first paint after a login shows the previous farmer's queue.
        currentSession()
            .then(notifyChange)
            .then(function (s) { if (s.total > 0) flush(); });
    }

    window.CropGuardQueue = {
        init: init,
        enqueue: enqueue,
        flush: flush,
        summary: summary,
        isAvailable: isAvailable,
        isNetworkError: isNetworkError,
        definitelyOffline: definitelyOffline,
        translate: translate
    };
})();
