  (function () {
    var btn = document.getElementById('copy-mcp-link');
    var valueNode = document.getElementById('mcp-link-value');
    if (!btn || !valueNode) {
      return;
    }

    btn.addEventListener('click', function () {
      var text = valueNode.textContent || '';
      if (text.trim() === '') {
        return;
      }

      var done = function () {
        var oldTitle = btn.title;
        btn.title = 'Скопійовано';
        setTimeout(function () {
          btn.title = oldTitle;
        }, 1200);
      };

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done);
        return;
      }

      var ta = document.createElement('textarea');
      ta.value = text;
      document.body.appendChild(ta);
      ta.select();
      try {
        document.execCommand('copy');
        done();
      } catch (e) {
      }
      document.body.removeChild(ta);
    });
  })();