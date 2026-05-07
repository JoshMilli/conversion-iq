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

    // Don't track inside the Elementor editor / preview iframe
    if (
        typeof window.elementor !== 'undefined' ||
        window.location.search.indexOf('elementor-preview') !== -1 ||
        window.location.search.indexOf('et_fb=') !== -1 ||  // Divi front-end builder
        window.frameElement !== null                         // any nested iframe
    ) { return; }

    // Normalise page URL — strip volatile/private query params so visits
    // to the same page always produce a single record.
    // Kept: UTM params (useful for segmentation), page / p / cat etc.
    // Stripped: Elementor versioning, WP nonces, preview ids, Beaver Builder.
    var _stripParams = ['ver', 'elementor-preview', 'elementor_library',
        'preview_id', 'preview_nonce', 'preview', 'et_pb_preview',
        'fl_builder', 'reauth', 'redirect_to', '_wpnonce'];
    var pageUrl = (function () {
        try {
            var u = new URL(window.location.href);
            _stripParams.forEach(function (k) { u.searchParams.delete(k); });
            // Remove empty query string
            var qs = u.searchParams.toString();
            return u.origin + u.pathname + (qs ? '?' + qs : '') + (u.hash || '');
        } catch (e) {
            return window.location.href;
        }
    }());

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

    /**
     * Walk up from the clicked element to find the nearest "meaningful" ancestor
     * so we record the button/link/heading that was actually intended, not a
     * giant wrapper div whose textContent spans the whole section.
     *
     * Priority:
     *   1. Interactive: a, button, input, select, textarea, label, summary
     *   2. Semantic:    h1-h6, p, li, td, th, figcaption, span (with text)
     *   3. Any element with role="button" or role="link"
     * Stops after 5 levels to avoid climbing to <body>.
     */
    function getClickTarget(el) {
        var interactive = { a:1, button:1, input:1, select:1, textarea:1, label:1, summary:1 };
        var semantic    = { h1:1, h2:1, h3:1, h4:1, h5:1, h6:1, p:1, li:1, td:1, th:1, span:1, figcaption:1 };
        var current = el;
        var levels  = 0;

        while (current && current.tagName && current !== document.body && levels < 5) {
            var t = (current.tagName || '').toLowerCase();
            if (interactive[t]) { break; }
            if (semantic[t])    { break; }
            var role = current.getAttribute ? current.getAttribute('role') : '';
            if (role === 'button' || role === 'link' || role === 'menuitem') { break; }
            current = current.parentElement;
            levels++;
        }

        if (!current || !current.tagName || current === document.body) { current = el; }

        var tag = (current.tagName || '').toLowerCase().slice(0, 50);
        var t   = tag;
        var text = '';

        // For form inputs: use value / placeholder / aria-label
        if (t === 'input' || t === 'textarea') {
            text = current.value || current.getAttribute('placeholder') || current.getAttribute('aria-label') || '';
        }
        // For select: use selected option label, not the full options list
        else if (t === 'select') {
            var sel = current.options && current.selectedIndex >= 0
                ? current.options[current.selectedIndex].text : '';
            text = sel || current.getAttribute('aria-label') || current.getAttribute('name') || 'select';
        }
        // For images: use alt text
        else if (t === 'img') {
            text = current.alt || current.getAttribute('title') || '';
        }
        // For everything else: prefer aria-label/title, then direct text nodes only
        else {
            text = current.getAttribute('aria-label') ||
                   current.getAttribute('title') || '';

            if (!text) {
                // Only collect direct TEXT_NODE children — avoids including
                // the full subtree text of container elements like div/section
                var directText = '';
                for (var i = 0; i < current.childNodes.length; i++) {
                    if (current.childNodes[i].nodeType === 3) {
                        directText += current.childNodes[i].textContent || '';
                    }
                }
                text = directText.replace(/\s+/g, ' ').trim();
            }

            // Last resort for leaf elements: use textContent (still capped tightly)
            if (!text && !current.children.length) {
                text = (current.textContent || '').replace(/\s+/g, ' ').trim();
            }
        }

        // Enforce a tight 100-char cap — long text rarely groups usefully
        return { tag: tag, text: text.slice(0, 100) };
    }

    document.addEventListener('click', function (e) {
        var dims = getPageDimensions();
        if (!dims.w || !dims.h) { return; }

        // Position relative to the full document (accounts for scroll)
        var absX = e.clientX + (window.pageXOffset || 0);
        var absY = e.clientY + (window.pageYOffset || 0);

        var xPct = parseFloat(((absX / dims.w) * 100).toFixed(3));
        var yPct = parseFloat(((absY / dims.h) * 100).toFixed(3));

        if (xPct < 0 || xPct > 100 || yPct < 0 || yPct > 100) { return; }

        var info = getClickTarget(e.target);

        queue.push({
            type:         'click',
            x_pct:        xPct,
            y_pct:        yPct,
            element_tag:  info.tag,
            element_text: info.text,
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
