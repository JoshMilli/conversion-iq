/**
 * Conversion IQ — Heatmap Tracker
 *
 * Records click and scroll-depth events on public-facing pages and sends
 * them in batches to the plugin's REST API. Designed to be async,
 * non-blocking, and < 3 KB unminified.
 *
 * Injected via wp_enqueue_script only when the license is active.
 * window.ciqTrackerConfig is set by wp_localize_script in conversion-iq.php.
 */
(function () {
    'use strict';

    var cfg = window.ciqTrackerConfig || {};
    var endpoint = cfg.endpoint || '';
    var pageUrl  = window.location.href;

    if (!endpoint || !pageUrl) { return; }

    // Stable session ID per browser tab (not stored in cookies — GDPR-friendlier)
    var sessionId = (function () {
        try {
            var k = 'ciq_sid';
            var v = sessionStorage.getItem(k);
            if (!v) {
                v = Math.random().toString(36).slice(2) + Date.now().toString(36);
                sessionStorage.setItem(k, v);
            }
            return v;
        } catch (e) {
            return Math.random().toString(36).slice(2);
        }
    }());

    var queue = [];
    var flushScheduled = false;

    function getPageDimensions() {
        return {
            w: Math.max(document.body ? document.body.scrollWidth : 0, window.innerWidth),
            h: Math.max(document.body ? document.body.scrollHeight : 0, window.innerHeight)
        };
    }

    function scheduleFlush() {
        if (flushScheduled) { return; }
        flushScheduled = true;
        // Use rIC when available so flushing never blocks user interaction
        if (typeof requestIdleCallback !== 'undefined') {
            requestIdleCallback(flush, { timeout: 2000 });
        } else {
            setTimeout(flush, 500);
        }
    }

    function flush() {
        flushScheduled = false;
        if (queue.length === 0) { return; }
        var events = queue.splice(0, queue.length);
        var payload = JSON.stringify({ page_url: pageUrl, events: events });
        if (navigator.sendBeacon) {
            navigator.sendBeacon(endpoint, new Blob([payload], { type: 'application/json' }));
        } else {
            // Fallback for older browsers: fire-and-forget XHR
            try {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', endpoint, true);
                xhr.setRequestHeader('Content-Type', 'application/json');
                xhr.send(payload);
            } catch (e) { /* silent fail */ }
        }
    }

    // ── Click tracking ────────────────────────────────────────────────────

    document.addEventListener('click', function (e) {
        var dims = getPageDimensions();
        if (!dims.w || !dims.h) { return; }

        // Position relative to the full document (accounts for scroll)
        var absX = e.clientX + (window.pageXOffset || 0);
        var absY = e.clientY + (window.pageYOffset || 0);

        var xPct = parseFloat(((absX / dims.w) * 100).toFixed(3));
        var yPct = parseFloat(((absY / dims.h) * 100).toFixed(3));

        if (xPct < 0 || xPct > 100 || yPct < 0 || yPct > 100) { return; }

        var target = e.target || {};
        var tag    = (target.tagName || '').toLowerCase().slice(0, 50);
        var text   = ((target.textContent || target.value || target.alt || '') + '')
                         .replace(/\s+/g, ' ').trim().slice(0, 255);

        queue.push({
            type:         'click',
            x_pct:        xPct,
            y_pct:        yPct,
            element_tag:  tag,
            element_text: text,
            session_id:   sessionId,
            viewport_w:   window.innerWidth,
            viewport_h:   window.innerHeight
        });

        // Flush immediately once we have enough events
        if (queue.length >= 10) {
            flush();
        } else {
            scheduleFlush();
        }
    }, true); // capture phase so we catch all clicks

    // ── Scroll-depth tracking ─────────────────────────────────────────────

    var maxScrollPct    = 0;
    var scrollRecorded  = {};
    var milestones      = [25, 50, 75, 90, 100];

    function checkScrollDepth() {
        var scrolled = (window.pageYOffset || 0) + window.innerHeight;
        var total    = Math.max(document.body ? document.body.scrollHeight : 1, 1);
        var pct      = Math.round((scrolled / total) * 100);
        if (pct <= maxScrollPct) { return; }
        maxScrollPct = pct;

        milestones.forEach(function (m) {
            if (pct >= m && !scrollRecorded[m]) {
                scrollRecorded[m] = true;
                queue.push({
                    type:         'scroll',
                    x_pct:        50,        // centred — not meaningful for scroll
                    y_pct:        parseFloat(m.toFixed(3)),
                    element_tag:  'scroll',
                    element_text: m + '%',
                    session_id:   sessionId,
                    viewport_w:   window.innerWidth,
                    viewport_h:   window.innerHeight
                });
                scheduleFlush();
            }
        });
    }

    var scrollTimer;
    window.addEventListener('scroll', function () {
        clearTimeout(scrollTimer);
        scrollTimer = setTimeout(checkScrollDepth, 150);
    }, { passive: true });

    // ── Flush on page unload ──────────────────────────────────────────────

    window.addEventListener('pagehide', flush);
    window.addEventListener('beforeunload', flush);

    // Safety net: flush every 30 s so we don't lose data on long sessions
    setInterval(flush, 30000);

}());
