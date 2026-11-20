/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 *
 * Magelan_AiVisibility - notices a visitor who came from an AI assistant.
 *
 * Runs in the browser because the page may come from a cache that never asks PHP. Once per
 * session: if the referrer or utm_source is a known assistant, it remembers the source in a
 * first-party cookie for the attribution window (the order is credited from it) and counts the
 * visit with a beacon. Nothing else is sent; nothing is read from other sites.
 */
(function () {
    'use strict';

    var script = document.currentScript,
        config;

    try {
        config = JSON.parse(script.getAttribute('data-mgl-ai'));
    } catch (e) {
        return;
    }

    function detect() {
        var host = '',
            utm = (new URLSearchParams(location.search).get('utm_source') || '').toLowerCase();

        try {
            host = document.referrer ? new URL(document.referrer).hostname.toLowerCase() : '';
        } catch (e) {
            host = '';
        }

        for (var i = 0; i < config.sources.length; i++) {
            var source = config.sources[i];

            for (var h = 0; h < source[1].length; h++) {
                if (host === source[1][h] || host.slice(-source[1][h].length - 1) === '.' + source[1][h]) {
                    return source[0];
                }
            }

            if (utm && source[2].indexOf(utm) !== -1) {
                return source[0];
            }
        }

        return null;
    }

    var source = detect();

    if (!source) {
        return;
    }

    // Magento's cookie restriction mode: no consent cookie, no cookie of ours and no count.
    if (config.consent && !/(?:^|;\s*)user_allowed_save_cookie=/.test(document.cookie)) {
        return;
    }

    try {
        if (window.sessionStorage.getItem('mgl_ai_counted') === source) {
            return;
        }

        window.sessionStorage.setItem('mgl_ai_counted', source);
    } catch (e) {
        // Storage refused: count anyway, at worst twice.
    }

    document.cookie = 'mgl_ai_src=' + source + '|' + encodeURIComponent(location.pathname) +
        '; path=/; max-age=' + (config.days * 86400) + '; SameSite=Lax' +
        (location.protocol === 'https:' ? '; secure' : '');

    var body = new FormData();
    body.append('source', source);

    if (navigator.sendBeacon) {
        navigator.sendBeacon(config.url, body);
    } else {
        fetch(config.url, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true }).catch(function () {});
    }
})();
