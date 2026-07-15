        (function () {
            var segments = document.querySelectorAll('.dashboard-page .seg[data-pct]');
            var bars = document.querySelectorAll('.dashboard-page .bar-fill[data-pct]');

            requestAnimationFrame(function () {
                segments.forEach(function (el) {
                    var pct = Math.max(0, Math.min(100, Number(el.getAttribute('data-pct') || 0)));
                    el.style.width = pct + '%';
                });

                bars.forEach(function (el) {
                    var pct = Math.max(0, Math.min(100, Number(el.getAttribute('data-pct') || 0)));
                    el.style.width = pct + '%';
                });
            });
        })();