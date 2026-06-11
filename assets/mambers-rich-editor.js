(function (global) {
  'use strict';

  function toolbarHtml() {
    return (
      '<div class="mambers-rich-toolbar mambers-tiptap-bar" role="toolbar" aria-label="Formatting">' +
      '<button type="button" class="mambers-rich-btn" data-cmd="bold" title="Bold"><strong>B</strong></button>' +
      '<button type="button" class="mambers-rich-btn" data-cmd="italic" title="Italic"><em>I</em></button>' +
      '<button type="button" class="mambers-rich-btn" data-cmd="underline" title="Underline"><u>U</u></button>' +
      '<button type="button" class="mambers-rich-btn" data-cmd="link" title="Link">🔗</button>' +
      '<button type="button" class="mambers-rich-btn" data-emoji-open title="Emoji">😀</button>' +
      '</div>'
    );
  }

  function emojiPopHtml() {
    return '<div class="mambers-emoji-pop" data-emoji-pop hidden></div>';
  }

  /**
   * @param {Element} wrap
   * @param {{ maxPlain?: number, onSync?: Function, initialHtml?: string, initialText?: string }} opts
   */
  function init(wrap, opts) {
    opts = opts || {};
    var editor = wrap.querySelector('[data-rich-editor]');
    var plainField = wrap.querySelector('[data-body-plain]');
    var htmlField = wrap.querySelector('[data-body-html]');
    if (!editor) return null;

    function sync() {
      var max = opts.maxPlain || 5000;
      if (plainField) plainField.value = (editor.innerText || '').trim().slice(0, max);
      if (htmlField) htmlField.value = editor.innerHTML || '';
      if (typeof opts.onSync === 'function') opts.onSync();
    }

    if (opts.initialHtml) {
      editor.innerHTML = opts.initialHtml;
    } else if (opts.initialText) {
      editor.textContent = opts.initialText;
    }
    sync();

    wrap.querySelectorAll('[data-cmd]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        editor.focus();
        var cmd = btn.getAttribute('data-cmd');
        if (cmd === 'link') {
          var u = prompt('Link URL');
          if (u) document.execCommand('createLink', false, u);
        } else {
          document.execCommand(cmd, false, null);
        }
        sync();
      });
    });

    editor.addEventListener('input', sync);
    editor.addEventListener('paste', function () {
      setTimeout(sync, 50);
    });

    var emojiPop = wrap.querySelector('[data-emoji-pop]');
    var emojiBtn = wrap.querySelector('[data-emoji-open]');
    if (emojiBtn && emojiPop) {
      emojiBtn.addEventListener('click', function () {
        emojiPop.hidden = !emojiPop.hidden;
        if (!emojiPop.hidden && !emojiPop.querySelector('emoji-picker')) {
          var picker = document.createElement('emoji-picker');
          picker.addEventListener('emoji-click', function (ev) {
            editor.focus();
            document.execCommand('insertText', false, ev.detail.unicode);
            sync();
            emojiPop.hidden = true;
          });
          emojiPop.appendChild(picker);
        }
      });
    }

    return { sync: sync, editor: editor };
  }

  global.MambersRichEditor = {
    init: init,
    toolbarHtml: toolbarHtml,
    emojiPopHtml: emojiPopHtml,
  };
})(window);
