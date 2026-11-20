/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 *
 * Page review: switches the store view, and while a review runs and the page is open, moves it on
 * in short steps and shows the progress (cron does the same at night).
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-mgl-air]');
    if (!root) {
        return;
    }

    var select = root.querySelector('[data-switch]');
    if (select) {
        select.addEventListener('change', function () {
            window.location.href = select.value;
        });
    }

    var runId = parseInt(root.getAttribute('data-run'), 10) || 0;
    if (!runId) {
        return;
    }
    var bar = root.querySelector('[data-bar]');
    var text = root.querySelector('[data-progress-text]');
    var failures = 0;

    function step() {
        var body = new FormData();
        body.append('form_key', root.getAttribute('data-form-key'));
        body.append('run', String(runId));
        body.append('isAjax', 'true');
        fetch(root.getAttribute('data-step-url'), {method: 'POST', body: body, credentials: 'same-origin'})
            .then(function (response) {
                return response.json();
            })
            .then(function (run) {
                failures = 0;
                if (bar && run.queued > 0) {
                    bar.style.width = Math.round(run.checked * 100 / run.queued) + '%';
                }
                if (text && run.text) {
                    text.textContent = run.text;
                }
                if (run.open) {
                    window.setTimeout(step, 500);
                } else {
                    window.location.reload();
                }
            })
            .catch(function () {
                failures++;
                if (failures < 5) {
                    window.setTimeout(step, 5000);
                }
            });
    }

    window.setTimeout(step, 300);
}());
