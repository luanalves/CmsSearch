/**
 * CMS Search tab bar: roving tabindex and keyboard support for role=tablist (WAI-ARIA tabs pattern). Tabs are links,
 * so Enter keeps its native behaviour; Space activates the focused tab; Arrow keys, Home and End move the focus.
 * Without this module every tab stays a normal focusable link.
 */
define([], function () {
    'use strict';

    return function (config, element) {
        var tabs = Array.prototype.slice.call(element.querySelectorAll('[role="tab"]')),
            current = 0,
            i;

        if (!tabs.length) {
            return;
        }
        for (i = 0; i < tabs.length; i++) {
            if (tabs[i].getAttribute('aria-selected') === 'true') {
                current = i;
            }
        }
        for (i = 0; i < tabs.length; i++) {
            tabs[i].setAttribute('tabindex', i === current ? '0' : '-1');
        }

        element.addEventListener('keydown', function (event) {
            var index = tabs.indexOf(document.activeElement),
                next;

            if (index < 0) {
                return;
            }
            switch (event.key) {
                case 'ArrowRight':
                case 'ArrowDown':
                    next = (index + 1) % tabs.length;
                    break;
                case 'ArrowLeft':
                case 'ArrowUp':
                    next = (index - 1 + tabs.length) % tabs.length;
                    break;
                case 'Home':
                    next = 0;
                    break;
                case 'End':
                    next = tabs.length - 1;
                    break;
                case ' ':
                case 'Spacebar':
                    event.preventDefault();
                    tabs[index].click();
                    return;
                default:
                    return;
            }
            event.preventDefault();
            tabs[index].setAttribute('tabindex', '-1');
            tabs[next].setAttribute('tabindex', '0');
            tabs[next].focus();
        });
    };
});
