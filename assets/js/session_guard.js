// assets/js/session_guard.js
//
// One shared session guard for every protected page. This logic used to be
// copy-pasted inline into ~20 pages, which is how two of them (mao_reports and
// tech_reports) ended up never destroying the session on logout at all.
//
// What it covers:
//   1. The initial "are you signed in, and are you allowed on this page" gate.
//   2. Back/forward (bfcache) restores. Chrome re-shows a fully rendered page
//      from memory WITHOUT re-running a single script, so the inline gate never
//      fires - this is why Back after logout used to land back on the dashboard.
//      The page is hidden until the session has been re-verified.
//   3. The 30-minute idle timeout, destroying the server session the moment it
//      expires instead of waiting for the user to click OK on the dialog
//      (refreshing instead of clicking used to leave the session fully alive).
//   4. An activity heartbeat, so the server-side timeout in session_check.php
//      tracks real interaction rather than only page loads.
//
// Usage, in <head>, after i18n.js:
//   <script src="../../assets/js/session_guard.js?v=1"></script>
//   <script>
//     CropGuardSession.protect({
//       roles: ['admin'],                  // omit or [] = any signed-in role
//       onReady: function (data) { ... }   // runs once the page has loaded
//     });
//   </script>

(function () {
    'use strict';

    // Resolve URLs from this script's own src rather than a hard-coded '../../'
    // so the file works from any folder depth, under both XAMPP
    // (http://localhost/da_system/) and Railway (domain root).
    var SELF = document.currentScript ? document.currentScript.src : '';
    var MARKER = 'assets/js/session_guard.js';
    var ROOT = (SELF && SELF.indexOf(MARKER) !== -1)
        ? SELF.slice(0, SELF.indexOf(MARKER))
        : '../../';

    var URL_CHECK     = ROOT + 'api/auth/session_check.php';
    var URL_LOGOUT    = ROOT + 'api/auth/logout.php';
    var URL_HEARTBEAT = ROOT + 'api/auth/heartbeat.php';
    // The bare address: index.php shows the login page there, so the address
    // bar reads ".../" instead of ".../auth.html".
    var URL_AUTH      = ROOT;

    // Where each role belongs. Must stay in step with the same mapping in
    // api/auth/login.php and the one in auth.html.
    var ROLE_HOME = {
        admin:      'views/masterfile/dashboard.html',
        farmer:     'views/farmers/index.html',
        technician: 'views/technician/dashboard.html',
        mao:        'views/mao/index.html'
    };

    var IDLE_MS      = 30 * 60 * 1000; // keep in step with CG_IDLE_LIMIT_SECONDS
    var HEARTBEAT_MS = 60 * 1000;

    var allowedRoles = [];
    var idleTimer    = null;
    var lastBeat     = 0;
    var leaving      = false;

    // Circuit breaker for a redirect loop between here and auth.html (seen
    // live: session_check.php flip-flopping active/not-active between two
    // requests milliseconds apart sent both pages bouncing off each other,
    // hundreds of times a minute, forever). sessionStorage survives across
    // the full-page navigations a loop like this does - a plain JS variable
    // would just reset on every hop and never notice. auth.html carries an
    // equivalent copy of this same check, keyed to the same storage entry,
    // since the two pages are the two ends of the loop.
    var LOOP_KEY  = 'cg_redirect_burst';
    var LOOP_MAX  = 5;     // this many redirects...
    var LOOP_MS   = 8000;  // ...within this many milliseconds = a loop, not normal use

    function inRedirectLoop() {
        var now = Date.now(), hits;
        try { hits = JSON.parse(sessionStorage.getItem(LOOP_KEY) || '[]'); } catch (e) { hits = []; }
        hits = hits.filter(function (t) { return now - t < LOOP_MS; });
        hits.push(now);
        try { sessionStorage.setItem(LOOP_KEY, JSON.stringify(hits)); } catch (e) { /* storage unavailable - can't track, let it through */ }
        return hits.length > LOOP_MAX;
    }

    function clearRedirectLoopTracking() {
        try { sessionStorage.removeItem(LOOP_KEY); } catch (e) {}
    }

    // Stops the loop dead instead of bouncing forever: destroys whatever
    // session state exists and lands on a clean, static auth.html - no
    // further session_check.php call from this landing, so there is nothing
    // left to flip-flop.
    function breakRedirectLoop() {
        leaving = true;
        clearTimeout(idleTimer);
        clearRedirectLoopTracking();
        fetch(URL_LOGOUT, { cache: 'no-store', credentials: 'same-origin' }).catch(function () {}).then(function () {
            var msg = translate('sw_loop_text', 'Something kept signing you in and out too quickly, so we stopped it and signed you out completely. Please log in again - if this keeps happening, tell your administrator.');
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'error',
                    title: translate('sw_loop_title', 'Signed Out'),
                    text: msg,
                    confirmButtonColor: '#11542e',
                    allowOutsideClick: false
                }).then(function () { window.location.replace(URL_AUTH); });
            } else {
                alert(msg);
                window.location.replace(URL_AUTH);
            }
        });
    }

    // replace(), not href: the dead page must not stay in history where the
    // Back button can reach it.
    function leaveTo(url) {
        if (leaving) return;
        if (inRedirectLoop()) { breakRedirectLoop(); return; }
        leaving = true;
        clearTimeout(idleTimer);
        window.location.replace(url);
    }

    function leaveToAuth() {
        leaveTo(URL_AUTH);
    }

    // Called when the session check comes back but this page isn't allowed.
    // Someone who IS signed in - just not with a role this page accepts, e.g. a
    // farmer typing an admin URL - goes straight to their own dashboard. Routing
    // them via auth.html instead would make the login screen visibly flash on
    // the way past, since auth.html would only turn them around again.
    function denyAccess(data) {
        if (data && data.status === 'active') {
            var home = ROLE_HOME[String(data.role || '').toLowerCase()];
            if (home) { leaveTo(ROOT + home); return; }
        }
        leaveToAuth();
    }

    function ask() {
        return fetch(URL_CHECK, { cache: 'no-store', credentials: 'same-origin' })
            .then(function (r) { return r.json(); });
    }

    function isAllowed(data) {
        if (!data || data.status !== 'active') return false;
        if (!allowedRoles.length) return true; // page open to any signed-in role
        return allowedRoles.indexOf(String(data.role || '').toLowerCase()) !== -1;
    }

    // Pages set the body to display:none until the gate passes.
    function revealBody() {
        if (document.body) {
            document.body.style.display = '';
        } else {
            document.addEventListener('DOMContentLoaded', function () {
                document.body.style.display = '';
            });
        }
    }

    // The original inline guards used window 'load' so that onReady ran after
    // navbar_*.js had injected the sidebar (that's where adminNameSidebar and
    // friends live). Kept, but without the bug where a fetch resolving after
    // 'load' had already fired meant the callback never ran at all.
    function whenPageReady(fn) {
        if (document.readyState === 'complete') fn();
        else window.addEventListener('load', fn);
    }

    function translate(key, fallback) {
        try {
            if (typeof t !== 'function') return fallback;
            var value = t(key);
            // i18n.js returns the key itself when a page has no such string.
            return (value && value !== key) ? value : fallback;
        } catch (e) {
            return fallback;
        }
    }

    // ── Idle timeout ────────────────────────────────────────────────────────
    function restartIdleTimer() {
        clearTimeout(idleTimer);
        idleTimer = setTimeout(onIdleExpired, IDLE_MS);
    }

    function onIdleExpired() {
        // Kill the server session FIRST. Previously this happened inside the
        // dialog's .then(), so refreshing instead of clicking OK left the
        // session completely alive and the "expiry" was purely cosmetic.
        fetch(URL_LOGOUT, { cache: 'no-store', credentials: 'same-origin' })
            .catch(function () { /* redirect regardless */ })
            .then(function () {
                if (typeof Swal === 'undefined') { leaveToAuth(); return; }
                // No confirm button: the redirect to auth.html happens either way,
                // so an OK button only adds a click between the user and the login
                // screen. The message still shows - just long enough to read why
                // they were signed out - then it moves on by itself.
                Swal.fire({
                    icon: 'warning',
                    title: translate('sw_session_expired_title', 'Session Expired'),
                    text: translate('sw_session_expired_text',
                        'You have been logged out due to 30 minutes of inactivity.'),
                    showConfirmButton: false,
                    timer: 3000,
                    timerProgressBar: true,
                    allowOutsideClick: false
                }).then(leaveToAuth);
            });
    }

    function onActivity() {
        if (leaving) return;
        restartIdleTimer();

        var now = Date.now();
        if (now - lastBeat < HEARTBEAT_MS) return;
        lastBeat = now;
        fetch(URL_HEARTBEAT, { cache: 'no-store', credentials: 'same-origin' })
            .catch(function () { /* a dropped heartbeat is not fatal */ });
    }

    function watchActivity() {
        ['mousemove', 'keydown', 'click', 'scroll', 'touchstart', 'touchmove']
            .forEach(function (ev) {
                document.addEventListener(ev, onActivity, { passive: true });
            });

        // Taking a photo backgrounds the tab while the phone's camera app is
        // open, and no mouse/touch events fire during that time - so without
        // this, framing a shot counts as idle. Carried over from the farmer
        // dashboard, which is where that actually bites.
        //
        // Note this can only restart the *client* timer. If the camera was open
        // longer than the server's limit the session is already gone, and the
        // heartbeat below is what tells us so.
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState !== 'visible' || leaving) return;
            restartIdleTimer();
            lastBeat = Date.now();
            fetch(URL_HEARTBEAT, { cache: 'no-store', credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) { if (!d || d.status !== 'active') leaveToAuth(); })
                .catch(function () { /* offline - let the next real action decide */ });
        });

        restartIdleTimer();
    }

    // ── Back/forward cache ──────────────────────────────────────────────────
    function watchBackForward() {
        window.addEventListener('pageshow', function (e) {
            if (!e.persisted) return;

            // bfcache restores the whole JS heap exactly as it was when we
            // navigated away - `leaving` included. Logging out sets it to true,
            // so without this reset leaveToAuth() below hits its own `if
            // (leaving) return` guard, silently does nothing, and a logged-out
            // dashboard just sits there on Back. That navigation is long over;
            // this document is live again.
            leaving = false;

            // Nothing re-ran, so what is on screen is a stale snapshot rendered
            // before the user logged out. Hide it until the session has been
            // confirmed, so no data is shown even briefly.
            document.documentElement.style.visibility = 'hidden';

            ask().then(function (data) {
                if (!isAllowed(data)) { denyAccess(data); return; }
                clearRedirectLoopTracking();
                document.documentElement.style.visibility = '';
                restartIdleTimer();
            }).catch(function () {
                // Couldn't confirm the session (offline, server hiccup). Reload
                // rather than redirect: a reload re-runs every script from
                // scratch, so it can't be defeated by stale heap state the way
                // the redirect just was, and it never strands a hidden page.
                window.location.reload();
            });
        });
    }

    // ── Public API ──────────────────────────────────────────────────────────
    function protect(options) {
        options = options || {};
        allowedRoles = (options.roles || []).map(function (r) {
            return String(r).toLowerCase();
        });

        watchBackForward();

        ask().then(function (data) {
            if (!isAllowed(data)) { denyAccess(data); return; }
            clearRedirectLoopTracking();
            revealBody();
            watchActivity();
            if (typeof options.onReady === 'function') {
                whenPageReady(function () {
                    // A page's own onReady blowing up is a bug in that page - it must
                    // never reach the .catch() below, which treats any rejection as
                    // "no valid session" and bounces to auth.html. whenPageReady runs
                    // this synchronously inside the .then() whenever the document has
                    // already finished loading (the normal case on a remote host,
                    // where the session fetch resolves after load), so an uncaught
                    // error here did exactly that: the Admin dashboard threw on a
                    // long-deleted element, got sent to auth.html, auth.html saw a
                    // live session and sent it straight back - a permanent redirect
                    // loop, hundreds of page loads a minute, live in production.
                    try {
                        options.onReady(data);
                    } catch (err) {
                        console.error('onReady failed for this page:', err);
                    }
                });
            }
        }).catch(leaveToAuth);
    }

    // Destroys the server session, then leaves. Every page's confirmLogout()
    // should route through this rather than rolling its own fetch.
    function logout() {
        clearRedirectLoopTracking();
        return fetch(URL_LOGOUT, { cache: 'no-store', credentials: 'same-origin' })
            .catch(function () { /* leave regardless - the cookie is gone */ })
            .then(leaveToAuth);
    }

    window.CropGuardSession = {
        protect: protect,
        logout: logout,
        check: ask
    };
})();
