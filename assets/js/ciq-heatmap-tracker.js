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

    // Don't track inside page builders, admin, or any iframe
    if (
        typeof window.elementor !== 'undefined' ||           // Elementor editor global
        window.self !== window.top ||                        // inside any iframe (cross-origin safe)
        window.location.pathname.indexOf('/wp-admin') !== -1 ||
        window.location.pathname.indexOf('/wp-login.php') !== -1 ||
        window.location.search.indexOf('elementor-preview') !== -1 ||
        window.location.search.indexOf('preview_id=') !== -1 ||
        window.location.search.indexOf('fl_builder') !== -1 ||
        window.location.search.indexOf('et_fb=') !== -1 ||
        window.location.search.indexOf('et_pb_preview') !== -1 ||
        window.location.search.indexOf('preview=true') !== -1
    ) { return; }

    // Normalise page URL — strip volatile/private query params so visits
    // to the same page always produce a single record.
    // Kept: UTM params (useful for segmentation), page / p / cat etc.
    // Stripped: Elementor versioning, WP nonces, preview ids, Beaver Builder.
    var _stripParams = ['ver', 'elementor-preview', 'elementor_library',
        'preview_id', 'preview_nonce', 'preview', 'et_pb_preview',
        'fl_builder', 'reauth', 'redirect_to', '_wpnonce',
        // Ad-network click IDs — unique per click, must be stripped or every
        // ad visitor creates a separate page URL and fragments heatmap data.
        'fbclid', 'gclid', 'msclkid', 'ttclid', 'li_fat_id', 'igshid', 'mc_cid', 'mc_eid'];
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

    // ── Page load timing & traffic source ────────────────────────────────
    var pageLoadTime = Date.now();
    var trafficSource = (function () {
        try {
            var u = new URL(pageUrl);
            return {
                referrer:     (document.referrer || '').slice(0, 500),
                utm_source:   u.searchParams.get('utm_source')   || '',
                utm_medium:   u.searchParams.get('utm_medium')   || '',
                utm_campaign: u.searchParams.get('utm_campaign') || ''
            };
        } catch (e) {
            return { referrer: '', utm_source: '', utm_medium: '', utm_campaign: '' };
        }
    }());

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

    // ── Device / browser detection ────────────────────────────────────────
    // Detected once at page load; sent with every batch so the server can
    // build mobile vs. desktop split reports without per-event overhead.
    var deviceInfo = (function () {
        var ua = navigator.userAgent;
        var isTablet = /iPad|(?:Android(?!.*Mobile))/i.test(ua);
        var isMobile = !isTablet && /Mobi|Android|iPhone|iPod/i.test(ua);
        return {
            device_type:  isTablet ? 'tablet' : (isMobile ? 'mobile' : 'desktop'),
            browser:      /Firefox\//.test(ua)   ? 'firefox'  :
                          /Edg\//.test(ua)        ? 'edge'     :
                          /Chrome\//.test(ua)     ? 'chrome'   :
                          /Safari\//.test(ua)     ? 'safari'   :
                          /MSIE|Trident/.test(ua) ? 'ie'       : 'other',
            screen_w:     screen.width,
            screen_h:     screen.height,
            pixel_ratio:  Math.round((window.devicePixelRatio || 1) * 10) / 10
        };
    }());

    // ── Real User Metrics (Core Web Vitals) ───────────────────────────────
    // Collected via browser PerformanceObserver — no server-side outbound
    // network call needed.  Final values are sent on pagehide.
    var rumCwv = { lcp_ms: null, cls: null, fcp_ms: null, ttfb_ms: null, inp_ms: null };

    // CLS — accumulate all layout-shift entries (session window value)
    (function () {
        if (!('PerformanceObserver' in window)) { return; }
        try {
            var clsValue = 0;
            new PerformanceObserver(function (list) {
                list.getEntries().forEach(function (e) {
                    if (!e.hadRecentInput) { clsValue += e.value; }
                });
                rumCwv.cls = Math.round(clsValue * 1000) / 1000;
            }).observe({ type: 'layout-shift', buffered: true });
        } catch (e) {}
    }());

    // LCP — last reported entry is the final LCP value
    (function () {
        if (!('PerformanceObserver' in window)) { return; }
        try {
            new PerformanceObserver(function (list) {
                var entries = list.getEntries();
                if (entries.length) {
                    rumCwv.lcp_ms = Math.round(entries[entries.length - 1].startTime);
                }
            }).observe({ type: 'largest-contentful-paint', buffered: true });
        } catch (e) {}
    }());

    // FCP — first-contentful-paint
    (function () {
        if (!('PerformanceObserver' in window)) { return; }
        try {
            new PerformanceObserver(function (list) {
                list.getEntries().forEach(function (e) {
                    if (e.name === 'first-contentful-paint') {
                        rumCwv.fcp_ms = Math.round(e.startTime);
                    }
                });
            }).observe({ type: 'paint', buffered: true });
        } catch (e) {}
    }());

    // TTFB — Navigation Timing API (synchronous, available immediately)
    (function () {
        try {
            var nav = performance.getEntriesByType('navigation')[0];
            if (nav) { rumCwv.ttfb_ms = Math.round(nav.responseStart - nav.requestStart); }
        } catch (e) {}
    }());

    // INP — interaction to next paint (Chrome 96+)
    (function () {
        if (!('PerformanceObserver' in window)) { return; }
        try {
            new PerformanceObserver(function (list) {
                list.getEntries().forEach(function (e) {
                    if (rumCwv.inp_ms === null || e.duration > rumCwv.inp_ms) {
                        rumCwv.inp_ms = Math.round(e.duration);
                    }
                });
            }).observe({ type: 'event', buffered: true, durationThreshold: 16 });
        } catch (e) {}
    }());

    // ── Above-the-fold snapshot ────────────────────────────────────────────
    // Measures once after first paint whether key elements are visible
    // without scrolling.  Sent with the first event batch, then never again.
    var aboveFoldData = null;
    var aboveFoldSent = false;

    function measureAboveFold() {
        var vh = window.innerHeight;
        var checks = [
            { type: 'h1',          query: 'h1' },
            { type: 'cta',         query: '.btn,.cta,button[type="submit"],[class*="btn"],[class*="cta"],a[href][class*="button"]' },
            { type: 'hero_image',  query: '[class*="hero"] img,[class*="banner"] img' },
            { type: 'form',        query: 'form' },
            // CRO-checklist-specific element types
            { type: 'nav_cta',     query: 'nav a[class*="btn"],nav a[class*="cta"],nav button,header a[class*="btn"],header button' },
            { type: 'trust_badge', query: '[class*="trust"] img,[class*="badge"] img,[class*="cert"] img,[class*="award"] img,[class*="accredit"] img' },
            { type: 'testimonial', query: '[class*="testimonial"],[class*="review"],[class*="social-proof"],[class*="feedback"]' },
            { type: 'pricing',     query: '[class*="pricing"],[class*="plan"],[class*="package"],[class*="tier"]' },
            { type: 'progress',    query: '[class*="progress"],[class*="stepper"],[class*="wizard"],[class*="step-indicator"],[class*="breadcrumb"]' }
        ];
        var elements = [];
        checks.forEach(function (c) {
            try {
                var nodes = document.querySelectorAll(c.query);
                for (var i = 0; i < Math.min(nodes.length, 3); i++) {
                    var rect = nodes[i].getBoundingClientRect();
                    if (rect.width > 0 && rect.height > 0) {
                        elements.push({
                            type:       c.type,
                            y_top:      Math.round(rect.top + (window.pageYOffset || 0)),
                            above_fold: rect.top < vh
                        });
                    }
                }
            } catch (e) { /* ignore unsupported selectors */ }
        });
        aboveFoldData = { viewport_height: vh, elements: elements };
    }
    // Defer until after first paint so layout is stable
    if (typeof requestAnimationFrame !== 'undefined') {
        requestAnimationFrame(function () { setTimeout(measureAboveFold, 0); });
    } else {
        setTimeout(measureAboveFold, 150);
    }

    // ── Form analytics ────────────────────────────────────────────────────
    // Tracks starts, completions, and drop-off field per form per session.
    var formStates = {};

    function getFormId(form) {
        var id = (form.id || form.getAttribute('name') || '').slice(0, 80);
        if (!id) {
            var all = document.querySelectorAll('form');
            for (var i = 0; i < all.length; i++) {
                if (all[i] === form) { id = 'form_' + i; break; }
            }
        }
        return id || 'form_unknown';
    }

    // Record which field the user last touched (used as drop-off field on abandon)
    document.addEventListener('focusin', function (e) {
        var el = e.target;
        if (!el || !el.form) { return; }
        var tag = (el.tagName || '').toLowerCase();
        if (tag !== 'input' && tag !== 'textarea' && tag !== 'select') { return; }
        var fid = getFormId(el.form);
        if (!formStates[fid]) {
            formStates[fid] = { id: fid, starts: 1, completions: 0, start_time: Date.now(), drop_off_field: null, time_sec: null };
        }
        var fieldName = (el.getAttribute('name') || el.getAttribute('id') || el.getAttribute('type') || 'field').slice(0, 60);
        formStates[fid].drop_off_field = fieldName;
    }, true);

    // Mark completion and clear the drop-off field on submit
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || (form.tagName || '').toLowerCase() !== 'form') { return; }
        var fid = getFormId(form);
        if (!formStates[fid]) {
            formStates[fid] = { id: fid, starts: 1, completions: 0, start_time: Date.now(), drop_off_field: null, time_sec: null };
        }
        formStates[fid].completions = 1;
        formStates[fid].drop_off_field = null;
        if (formStates[fid].start_time) {
            formStates[fid].time_sec = Math.round((Date.now() - formStates[fid].start_time) / 1000);
        }
    }, true);

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
        var hasUnsentAtf = !aboveFoldSent && aboveFoldData;
        // Don't bail on empty queue if we still have above-fold data to send
        if (queue.length === 0 && !hasUnsentAtf) { return; }
        var events = queue.splice(0, queue.length);
        var batch = {
            page_url:       pageUrl,
            session_id:     sessionId,
            events:         events,
            device_info:    deviceInfo,
            traffic_source: trafficSource
        };
        // Attach above-fold snapshot once per page load (first flush only)
        if (!aboveFoldSent && aboveFoldData) {
            batch.above_fold = aboveFoldData;
            aboveFoldSent = true;
        }
        // Attach RUM CWV if any values are available (server updates session row each time)
        if (rumCwv.lcp_ms !== null || rumCwv.fcp_ms !== null || rumCwv.ttfb_ms !== null) {
            batch.cwv = rumCwv;
        }
        var payload = JSON.stringify(batch);
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

    // ── Flush on page unload ──────────────────────────────────────────────

    window.addEventListener('pagehide', function () {
        // Flush queued click/scroll events (also sends above_fold if still pending)
        flush();
        // Send form analytics + final RUM CWV + any still-unsent above-fold data
        // in one pagehide beacon. CWV observers may still update values until the
        // very end of the page lifetime, so pagehide is the most accurate capture point.
        var formList = [];
        for (var k in formStates) {
            if (Object.prototype.hasOwnProperty.call(formStates, k)) {
                formList.push(formStates[k]);
            }
        }
        var hasCwv = rumCwv.lcp_ms !== null || rumCwv.fcp_ms !== null || rumCwv.ttfb_ms !== null;
        // Final safety net: include above-fold if flush() couldn't send it
        // (e.g. aboveFoldData wasn't ready when the last event batch fired)
        var hasAtf = !aboveFoldSent && aboveFoldData;
        // Always send the pagehide beacon so time_on_page_sec is captured for
        // every session, even when there are no forms, CWV, or above-fold data.
        if (navigator.sendBeacon) {
            if (hasAtf) { aboveFoldSent = true; }
            var faBatch = JSON.stringify({
                page_url:         pageUrl,
                session_id:       sessionId,
                events:           [],
                device_info:      deviceInfo,
                form_analytics:   formList,
                cwv:              hasCwv ? rumCwv : undefined,
                above_fold:       hasAtf ? aboveFoldData : undefined,
                time_on_page_sec: Math.min(7200, Math.round((Date.now() - pageLoadTime) / 1000)),
                traffic_source:   trafficSource
            });
            navigator.sendBeacon(endpoint, new Blob([faBatch], { type: 'application/json' }));
        }
    });
    window.addEventListener('beforeunload', flush);

    // Safety net: flush every 30 s so we don't lose data on long sessions
    setInterval(flush, 30000);

}());
