(function () {
  'use strict';

  var lightbox = null;

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function fmtDate(iso) {
    if (!iso) return '';
    try {
      var d = new Date(iso);
      return d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
    } catch (e) {
      return iso;
    }
  }

  var REACTION_EMOJIS = ['👍', '❤️', '😂', '😮', '😢', '😠'];
  var previewTimer = null;

  function formatBytes(n) {
    n = Number(n) || 0;
    if (n < 1024) return n + ' B';
    if (n < 1048576) return (n / 1024).toFixed(n < 10240 ? 1 : 0) + ' KB';
    return (n / 1048576).toFixed(n < 10485760 ? 1 : 0) + ' MB';
  }

  function mediaLimitsFrom(el) {
    el = el || document.body;
    var compress = el.getAttribute('data-image-compress') === '1';
    var maxImageMb = parseInt(el.getAttribute('data-max-image-mb'), 10) || 5;
    var storedImageMb = parseInt(el.getAttribute('data-stored-image-mb'), 10) || maxImageMb;
    var phpUploadMb = parseInt(el.getAttribute('data-php-upload-mb'), 10) || 0;
    var phpPostMb = parseInt(el.getAttribute('data-php-post-mb'), 10) || 0;
    var maxVideoMb = parseInt(el.getAttribute('data-max-video-mb'), 10) || 10;
    var effectiveImageMb = maxImageMb;
    if (phpUploadMb > 0) {
      effectiveImageMb = Math.min(maxImageMb, phpUploadMb);
    }
    var effectiveVideoMb = maxVideoMb;
    if (phpUploadMb > 0) {
      effectiveVideoMb = Math.min(maxVideoMb, phpUploadMb);
    }
    return {
      compress: compress,
      maxImageMb: maxImageMb,
      storedImageMb: storedImageMb,
      effectiveImageMb: effectiveImageMb,
      phpUploadMb: phpUploadMb,
      phpPostMb: phpPostMb,
      maxVideoMb: maxVideoMb,
      effectiveVideoMb: effectiveVideoMb,
      maxImages: parseInt(el.getAttribute('data-max-images'), 10) || 4,
      maxVideos: parseInt(el.getAttribute('data-max-videos'), 10) || 1,
      maxImageBytes: effectiveImageMb * 1048576,
      maxVideoBytes: effectiveVideoMb * 1048576,
      phpPostBytes: phpPostMb > 0 ? phpPostMb * 1048576 : 0,
    };
  }

  function isVideoMime(type) {
    return /^video\/(mp4|webm)$/i.test(type || '');
  }

  function isImageMime(type) {
    return /^image\/(jpeg|png|webp|gif)$/i.test(type || '');
  }

  function validateMediaFiles(fileList, limits) {
    var files = Array.prototype.slice.call(fileList || []);
    var errors = [];
    var items = [];
    var images = 0;
    var videos = 0;

    var totalBytes = 0;

    files.forEach(function (file) {
      var isVid = isVideoMime(file.type);
      var isImg = isImageMime(file.type);
      if (!isVid && !isImg) {
        errors.push(file.name + ' — unsupported type (use JPEG, PNG, WebP, GIF, MP4, or WebM)');
        items.push({ file: file, status: 'bad-type' });
        return;
      }
      var max = isVid ? limits.maxVideoBytes : limits.maxImageBytes;
      var kind = isVid ? 'video' : 'image';
      var capMb = isVid ? limits.effectiveVideoMb : limits.effectiveImageMb;
      if (file.size > max) {
        var hint =
          !isVid && limits.phpUploadMb > 0 && limits.effectiveImageMb < limits.maxImageMb
            ? ' (PHP allows ' + limits.phpUploadMb + ' MB per file — restart dev server if you raised limits)'
            : '';
        errors.push(
          file.name +
            ' — too big (' +
            formatBytes(file.size) +
            ', max ' +
            capMb +
            ' MB per ' +
            kind +
            ')' +
            hint
        );
        items.push({ file: file, status: 'oversize' });
        return;
      }
      totalBytes += file.size;
      if (isVid) {
        if (videos >= limits.maxVideos) {
          errors.push(file.name + ' — only ' + limits.maxVideos + ' video per post');
          items.push({ file: file, status: 'limit' });
          return;
        }
        videos++;
      } else {
        if (images >= limits.maxImages) {
          errors.push(file.name + ' — only ' + limits.maxImages + ' images per post');
          items.push({ file: file, status: 'limit' });
          return;
        }
        images++;
      }
      items.push({ file: file, status: 'ok' });
    });

    if (limits.phpPostBytes > 0 && totalBytes > limits.phpPostBytes) {
      errors.push(
        'Total upload too large (' +
          formatBytes(totalBytes) +
          ', server max ~' +
          limits.phpPostMb +
          ' MB per request)'
      );
    }

    return {
      ok: errors.length === 0,
      errors: errors,
      items: items,
      hasValid: items.some(function (x) {
        return x.status === 'ok';
      }),
    };
  }

  function postFormData(url, fd, onProgress) {
    return new Promise(function (resolve, reject) {
      var xhr = new XMLHttpRequest();
      xhr.open('POST', url);
      xhr.withCredentials = true;
      xhr.upload.addEventListener('progress', function (ev) {
        if (!onProgress) return;
        if (ev.lengthComputable) {
          onProgress(Math.min(100, Math.round((ev.loaded / ev.total) * 100)), ev.loaded, ev.total);
        } else {
          onProgress(-1, ev.loaded, 0);
        }
      });
      xhr.addEventListener('load', function () {
        var data = null;
        try {
          data = JSON.parse(xhr.responseText);
        } catch (e) {
          reject(new Error(xhr.status === 413 ? 'Upload too large for server.' : 'Post failed — try again.'));
          return;
        }
        if (xhr.status >= 200 && xhr.status < 300 && data && data.ok) {
          resolve(data);
          return;
        }
        reject(new Error((data && data.error) || (xhr.status === 413 ? 'Upload too large.' : 'Post failed.')));
      });
      xhr.addEventListener('error', function () {
        reject(new Error('Network error while uploading.'));
      });
      xhr.addEventListener('abort', function () {
        reject(new Error('Upload cancelled.'));
      });
      xhr.send(fd);
    });
  }

  function extractFirstUrl(text) {
    if (!text) return '';
    var m = String(text).match(/https?:\/\/[^\s<>"']+/i);
    return m ? m[0].replace(/[.,);'\]"]+$/, '') : '';
  }

  function absoluteUrl(url) {
    if (!url) return window.location.href;
    url = String(url);
    if (/^https?:\/\//i.test(url)) return url;
    var origin = window.location.origin || '';
    if (url.charAt(0) === '/') return origin + url;
    return origin + '/' + url;
  }

  function linkHost(link) {
    if (!link) return '';
    if (link.site_name) return String(link.site_name);
    try {
      return new URL(link.url).hostname.replace(/^www\./i, '');
    } catch (e) {
      return '';
    }
  }

  function ensureLightbox() {
    if (lightbox) return lightbox;
    var el = document.createElement('div');
    el.className = 'mambers-lightbox';
    el.hidden = true;
    el.innerHTML =
      '<button type="button" class="mambers-lightbox-close" aria-label="Close">&times;</button>' +
      '<button type="button" class="mambers-lightbox-prev" aria-label="Previous">&#8249;</button>' +
      '<button type="button" class="mambers-lightbox-next" aria-label="Next">&#8250;</button>' +
      '<div class="mambers-lightbox-stage"><img alt=""></div>' +
      '<p class="mambers-lightbox-counter"></p>';
    document.body.appendChild(el);
    lightbox = {
      el: el,
      img: el.querySelector('img'),
      counter: el.querySelector('.mambers-lightbox-counter'),
      urls: [],
      index: 0,
    };
    el.querySelector('.mambers-lightbox-close').addEventListener('click', closeLightbox);
    el.querySelector('.mambers-lightbox-prev').addEventListener('click', function () {
      stepLightbox(-1);
    });
    el.querySelector('.mambers-lightbox-next').addEventListener('click', function () {
      stepLightbox(1);
    });
    el.addEventListener('click', function (e) {
      if (e.target === el) closeLightbox();
    });
    document.addEventListener('keydown', function (e) {
      if (!lightbox || lightbox.el.hidden) return;
      if (e.key === 'Escape') closeLightbox();
      if (e.key === 'ArrowLeft') stepLightbox(-1);
      if (e.key === 'ArrowRight') stepLightbox(1);
    });
    return lightbox;
  }

  function openLightbox(urls, index) {
    var lb = ensureLightbox();
    lb.urls = urls;
    lb.index = index;
    lb.el.hidden = false;
    document.body.classList.add('mambers-lightbox-open');
    showLightboxIndex();
  }

  function closeLightbox() {
    if (!lightbox) return;
    lightbox.el.hidden = true;
    document.body.classList.remove('mambers-lightbox-open');
  }

  function stepLightbox(delta) {
    if (!lightbox || !lightbox.urls.length) return;
    lightbox.index = (lightbox.index + delta + lightbox.urls.length) % lightbox.urls.length;
    showLightboxIndex();
  }

  function showLightboxIndex() {
    if (!lightbox) return;
    lightbox.img.src = lightbox.urls[lightbox.index];
    lightbox.counter.textContent = lightbox.urls.length > 1 ? lightbox.index + 1 + ' / ' + lightbox.urls.length : '';
    lightbox.el.querySelector('.mambers-lightbox-prev').hidden = lightbox.urls.length < 2;
    lightbox.el.querySelector('.mambers-lightbox-next').hidden = lightbox.urls.length < 2;
  }

  function renderLinkCard(link) {
    if (!link || !link.url) return '';
    var host = linkHost(link);
    var hasImg = !!(link.image && String(link.image).trim());
    var img = hasImg
      ? '<div class="mambers-activity-link-media"><img class="mambers-activity-link-img" src="' +
        esc(link.image) +
        '" alt="" loading="lazy"></div>'
      : '';
    return (
      '<a class="mambers-activity-link' +
      (hasImg ? ' mambers-activity-link--card' : ' mambers-activity-link--text') +
      '" href="' +
      esc(link.url) +
      '" rel="noopener noreferrer" target="_blank">' +
      img +
      '<span class="mambers-activity-link-text">' +
      '<strong class="mambers-activity-link-title">' +
      esc(link.title || link.url) +
      '</strong>' +
      (link.description ? '<span class="mambers-activity-link-desc">' + esc(link.description) + '</span>' : '') +
      (host ? '<span class="mambers-activity-link-host">' + esc(host) + '</span>' : '') +
      '</span></a>'
    );
  }

  function imageGridClass(count) {
    if (count <= 1) return 'mambers-media-grid--1';
    if (count === 2) return 'mambers-media-grid--2';
    if (count === 3) return 'mambers-media-grid--3';
    return 'mambers-media-grid--4';
  }

  function renderMedia(media, postId) {
    if (!media || !media.length) return '';
    var images = [];
    var videos = [];
    media.forEach(function (m) {
      if (!m || !m.url) return;
      if (m.type === 'video') videos.push(m);
      else if (m.type === 'image') images.push(m);
    });

    var html = '';
    if (images.length) {
      var extra = images.length > 4 ? images.length - 4 : 0;
      var show = images.slice(0, 4);
      html +=
        '<div class="mambers-media-grid ' +
        imageGridClass(show.length) +
        '" data-mambers-grid data-post="' +
        esc(postId) +
        '" data-urls="' +
        esc(JSON.stringify(images.map(function (m) { return m.url; }))) +
        '">';
      show.forEach(function (m, i) {
        var overlay = extra > 0 && i === 3 ? '<span class="mambers-media-more">+' + extra + '</span>' : '';
        html +=
          '<button type="button" class="mambers-media-cell" data-index="' +
          i +
          '" aria-label="View image">' +
          '<img src="' +
          esc(m.url) +
          '" alt="' +
          esc(m.alt || '') +
          '" loading="lazy">' +
          overlay +
          '</button>';
      });
      html += '</div>';
    }

    if (videos.length) {
      html += '<div class="mambers-activity-videos">';
      videos.forEach(function (m) {
        html +=
          '<video class="mambers-activity-video" controls preload="metadata" playsinline src="' +
          esc(m.url) +
          '"></video>';
      });
      html += '</div>';
    }

    return html;
  }

  function renderBody(post) {
    if (post.body_html) {
      return '<div class="mambers-activity-body mambers-activity-body--rich">' + post.body_html + '</div>';
    }
    if (!post.body) return '';
    return '<div class="mambers-activity-body">' + esc(post.body).replace(/\n/g, '<br>') + '</div>';
  }

  function renderReactions(post, opts) {
    opts = opts || {};
    if (!opts.reactionsEnabled || !post.id) return '';
    var rx = post.reactions || {};
    var counts = rx.counts || {};
    var mine = rx.mine || '';
    var html = '<div class="mambers-reactions" data-post-id="' + esc(post.id) + '">';
    if (rx.total > 0) {
      html += '<div class="mambers-reaction-summary">';
      REACTION_EMOJIS.forEach(function (em) {
        if (counts[em]) html += '<span class="mambers-reaction-pill">' + em + ' ' + counts[em] + '</span>';
      });
      html += '</div>';
    }
    if (opts.canReact && opts.nonce) {
      html += '<div class="mambers-reaction-bar">';
      REACTION_EMOJIS.forEach(function (em) {
        html +=
          '<button type="button" class="mambers-reaction-btn' +
          (mine === em ? ' is-active' : '') +
          '" data-react="' +
          esc(em) +
          '" title="React">' +
          em +
          '</button>';
      });
      html += '</div>';
    }
    html += '</div>';
    return html;
  }

  function renderPost(post, opts) {
    opts = opts || {};
    var head = '';
    if (opts.showAuthor && post.author_name) {
      head =
        '<header class="mambers-activity-post-head">' +
        (post.author_avatar
          ? '<img class="mambers-activity-avatar" src="' + esc(post.author_avatar) + '" alt="">'
          : '') +
        '<div><a href="' + esc(post.profile_url || '#') + '">' + esc(post.author_name) + '</a>' +
        '<time datetime="' + esc(post.created) + '">' + esc(fmtDate(post.created)) + '</time></div>' +
        '</header>';
    } else {
      head = '<time class="mambers-activity-time" datetime="' + esc(post.created) + '">' + esc(fmtDate(post.created)) + '</time>';
    }

    var foot = '<footer class="mambers-activity-actions">';
    if (post.post_url) {
      var shareUrl = absoluteUrl(post.post_url);
      foot +=
        '<button type="button" class="mambers-btn mambers-btn--ghost mambers-activity-share" data-share-url="' +
        esc(shareUrl) +
        '">Share</button>';
      foot += '<a class="mambers-link mambers-activity-permalink" href="' + esc(shareUrl) + '">Permalink</a>';
    }
    if (opts.canEdit && post.id) {
      foot +=
        '<button type="button" class="mambers-btn mambers-btn--ghost mambers-activity-edit" data-id="' +
        esc(post.id) +
        '">Edit</button>';
      foot += '<button type="button" class="mambers-btn mambers-btn--ghost mambers-activity-delete" data-id="' + esc(post.id) + '">Delete</button>';
    }
    foot += '</footer>';

    return (
      '<article class="mambers-activity-post" data-post-id="' + esc(post.id) + '" data-visibility="' + esc(post.visibility || 'public') + '">' +
      head +
      '<div class="mambers-post-content">' +
      renderBody(post) +
      renderMedia(post.media, post.id) +
      renderLinkCard(post.link) +
      '</div>' +
      renderReactions(post, opts) +
      foot +
      '</article>'
    );
  }

  function postEditPanelHtml(post) {
    var vis = post.visibility || 'public';
    var rich = window.MambersRichEditor;
    return (
      '<div class="mambers-post-edit" data-post-edit>' +
      (rich ? rich.toolbarHtml() : '') +
      '<div class="mambers-rich-editor mambers-rich-editor--dark" contenteditable="true" data-rich-editor></div>' +
      (rich ? rich.emojiPopHtml() : '') +
      '<input type="hidden" data-body-plain><input type="hidden" data-body-html>' +
      '<div class="mambers-post-edit-actions">' +
      '<label class="mambers-composer-visibility">' +
      '<select class="mambers-composer-select" data-edit-visibility aria-label="Visibility">' +
      '<option value="public"' +
      (vis === 'public' ? ' selected' : '') +
      '>Public</option>' +
      '<option value="members"' +
      (vis === 'members' ? ' selected' : '') +
      '>Members</option>' +
      '<option value="private"' +
      (vis === 'private' ? ' selected' : '') +
      '>Only me</option>' +
      '</select></label>' +
      '<button type="button" class="mambers-btn mambers-btn--post" data-post-edit-save>Save</button>' +
      '<button type="button" class="mambers-btn mambers-btn--ghost" data-post-edit-cancel>Cancel</button>' +
      '</div></div>'
    );
  }

  function closePostEdit(article) {
    if (!article) return;
    article.classList.remove('is-editing');
    var panel = article.querySelector('[data-post-edit]');
    if (panel) panel.remove();
    var content = article.querySelector('.mambers-post-content');
    if (content) content.hidden = false;
  }

  function openPostEdit(article, post, api, nonce, opts, reload) {
    if (!article || article.classList.contains('is-editing')) return;
    closePostEdit(article);
    article.classList.add('is-editing');
    var content = article.querySelector('.mambers-post-content');
    if (content) content.hidden = true;

    var tmp = document.createElement('div');
    tmp.innerHTML = postEditPanelHtml(post);
    var panel = tmp.firstChild;
    article.insertBefore(panel, article.querySelector('.mambers-reactions'));

    var rich = window.MambersRichEditor;
    var editorApi = null;
    if (rich) {
      editorApi = rich.init(panel, {
        initialHtml: post.body_html || '',
        initialText: post.body_html ? '' : post.body || '',
      });
    }

    panel.querySelector('[data-post-edit-cancel]').addEventListener('click', function () {
      closePostEdit(article);
    });

    panel.querySelector('[data-post-edit-save]').addEventListener('click', function () {
      var saveBtn = panel.querySelector('[data-post-edit-save]');
      var plain = panel.querySelector('[data-body-plain]');
      var html = panel.querySelector('[data-body-html]');
      var visibility = panel.querySelector('[data-edit-visibility]');
      if (editorApi) editorApi.sync();
      saveBtn.disabled = true;
      fetch(api + '/activity/' + post.id, {
        method: 'PATCH',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-Members-Nonce': nonce },
        body: JSON.stringify({
          nonce: nonce,
          body: plain ? plain.value : '',
          body_html: html ? html.value : '',
          visibility: visibility ? visibility.value : 'public',
        }),
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data.ok) throw new Error(data.error || 'edit_failed');
          if (reload) {
            reload(1);
            return;
          }
          var parent = article.parentElement;
          if (!parent) return;
          var tmpPost = document.createElement('div');
          tmpPost.innerHTML = renderPost(data.post, opts);
          var fresh = tmpPost.firstChild;
          if (fresh) article.replaceWith(fresh);
        })
        .catch(function (err) {
          alert(err.message || 'Could not save post.');
        })
        .finally(function () {
          saveBtn.disabled = false;
        });
    });
  }

  function composerHtml(nonce, opts) {
    opts = opts || {};
    var lim = opts.limits || mediaLimitsFrom();
    var giphyBtn = opts.giphy
      ? '<button type="button" class="mambers-rich-btn" data-giphy-open title="GIF">GIF</button>'
      : '';
    return (
      '<form class="mambers-activity-composer" data-mambers-composer enctype="multipart/form-data">' +
      '<input type="hidden" name="nonce" value="' + esc(nonce) + '">' +
      '<input type="hidden" name="body" data-body-plain value="">' +
      '<input type="hidden" name="body_html" data-body-html value="">' +
      '<div class="mambers-rich-toolbar mambers-tiptap-bar" role="toolbar" aria-label="Formatting">' +
      '<button type="button" class="mambers-rich-btn" data-cmd="bold" title="Bold"><strong>B</strong></button>' +
      '<button type="button" class="mambers-rich-btn" data-cmd="italic" title="Italic"><em>I</em></button>' +
      '<button type="button" class="mambers-rich-btn" data-cmd="underline" title="Underline"><u>U</u></button>' +
      '<button type="button" class="mambers-rich-btn" data-cmd="link" title="Link">🔗</button>' +
      '<button type="button" class="mambers-rich-btn" data-emoji-open title="Emoji">😀</button>' +
      giphyBtn +
      '</div>' +
      '<div class="mambers-rich-editor" contenteditable="true" data-rich-editor data-placeholder="What\'s happening in the village? Paste a link for a live preview…"></div>' +
      '<div class="mambers-emoji-pop" data-emoji-pop hidden></div>' +
      '<div class="mambers-giphy-modal" data-giphy-modal hidden><div class="mambers-giphy-panel">' +
      '<input type="search" class="mambers-giphy-search" placeholder="Search Giphy…">' +
      '<div class="mambers-giphy-results"></div>' +
      '<button type="button" class="mambers-btn mambers-btn--ghost" data-giphy-close>Close</button></div></div>' +
      '<div class="mambers-activity-compose-preview" data-compose-preview hidden></div>' +
      '<div class="mambers-gif-staging" data-gif-staging hidden></div>' +
      '<div class="mambers-media-staging" data-media-staging hidden>' +
      '<div class="mambers-media-preview-grid" data-media-files></div>' +
      '<p class="mambers-media-warn" data-media-warn hidden></p>' +
      '</div>' +
      '<div class="mambers-upload-progress" data-upload-progress hidden>' +
      '<div class="mambers-upload-progress-track" aria-hidden="true"><span class="mambers-upload-progress-bar" data-upload-bar></span></div>' +
      '<span class="mambers-upload-progress-label" data-upload-label>Uploading…</span>' +
      '</div>' +
      '<footer class="mambers-composer-footer">' +
      '<div class="mambers-composer-toolbar-row">' +
      '<label class="mambers-composer-attach" data-composer-attach>' +
      '<span class="mambers-composer-attach-icon" aria-hidden="true">' +
      '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M4 5a2 2 0 0 1 2-2h2.2l1.2-2h4.2l1.2 2H17a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5Zm2 0v12h12V5H6Zm6 2.5A4.5 4.5 0 1 1 7.5 12 4.5 4.5 0 0 1 12 7.5Zm0 2A2.5 2.5 0 1 0 14.5 12 2.5 2.5 0 0 0 12 9.5Z"/></svg>' +
      '</span>' +
      '<span class="mambers-composer-attach-text">Add media</span>' +
      '<span class="mambers-composer-attach-badge" data-media-count hidden></span>' +
      '<input type="file" name="media[]" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm" multiple>' +
      '</label>' +
      '<div class="mambers-composer-footer-right">' +
      '<label class="mambers-composer-visibility">' +
      '<span class="mambers-composer-visibility-icon" aria-hidden="true">' +
      '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 4.5C7 4.5 2.7 7.6 1 12c1.7 4.4 6 7.5 11 7.5s9.3-3.1 11-7.5C21.3 7.6 17 4.5 12 4.5Zm0 2.2a5.3 5.3 0 1 1-5.3 5.3A5.3 5.3 0 0 1 12 6.7Zm0 2.1a3.2 3.2 0 1 0 3.2 3.2 3.2 3.2 0 0 0-3.2-3.2Z"/></svg>' +
      '</span>' +
      '<select class="mambers-composer-select" name="visibility" aria-label="Visibility">' +
      '<option value="public">Public</option>' +
      '<option value="members">Members</option>' +
      '<option value="private">Only me</option>' +
      '</select>' +
      '</label>' +
      '<button type="submit" class="mambers-btn mambers-btn--post" data-post-submit><span>Post</span></button>' +
      '</div>' +
      '</div>' +
      '<p class="mambers-composer-footnote mambers-media-hint">' +
      (lim.compress
        ? 'Up to ' +
          lim.maxImages +
          ' images (upload ' +
          lim.maxImageMb +
          ' MB, auto-compressed to ' +
          lim.storedImageMb +
          ' MB) · '
        : 'Up to ' + lim.maxImages + ' images (' + lim.maxImageMb + ' MB each) · ') +
      lim.maxVideos +
      ' video (' +
      lim.maxVideoMb +
      ' MB)</p>' +
      '<p class="mambers-activity-status" hidden></p>' +
      '</footer>' +
      '</form>'
    );
  }

  function feedTabsHtml(scope) {
    return (
      '<nav class="mambers-feed-tabs" aria-label="Feed scope">' +
      '<button type="button" class="mambers-feed-tab' +
      (scope === 'all' ? ' is-active' : '') +
      '" data-scope="all">All</button>' +
      '<button type="button" class="mambers-feed-tab' +
      (scope === 'following' ? ' is-active' : '') +
      '" data-scope="following">Following</button>' +
      '</nav>'
    );
  }

  function socialBarHtml(graph, profile, canEdit) {
    graph = graph || {};
    var counts =
      '<span class="mambers-social-counts">' +
      '<strong>' + esc(String(graph.followers || 0)) + '</strong> followers · ' +
      '<strong>' + esc(String(graph.following || 0)) + '</strong> following' +
      '</span>';
    var actions = '';
    if (!canEdit && graph.can_follow) {
      actions +=
        '<button type="button" class="mambers-btn mambers-btn--follow' +
        (graph.is_following ? ' is-following' : '') +
        '" data-graph-follow>' +
        (graph.is_following ? 'Following' : 'Follow') +
        '</button>';
    }
    if (!canEdit && graph.can_follow) {
      actions +=
        '<button type="button" class="mambers-btn mambers-btn--ghost mambers-btn--block" data-graph-block>' +
        (graph.is_blocked ? 'Unblock' : 'Block') +
        '</button>';
    }
    return '<div class="mambers-social-bar-inner">' + counts + actions + '</div>';
  }

  function mountSocial(el) {
    var api = (el.getAttribute('data-api') || '').replace(/\/$/, '');
    var profile = el.getAttribute('data-profile') || '';
    var canEdit = el.getAttribute('data-can-edit') === '1';
    var nonce = el.getAttribute('data-nonce') || '';
    var graph = {};
    try {
      graph = JSON.parse(el.getAttribute('data-graph') || '{}');
    } catch (e) {
      graph = {};
    }

    function paint(g) {
      el.innerHTML = socialBarHtml(g, profile, canEdit);
      bindSocial(el, api, nonce, profile, canEdit, paint);
    }

    paint(graph);
  }

  function bindSocial(root, api, nonce, profile, canEdit, repaint) {
    var followBtn = root.querySelector('[data-graph-follow]');
    if (followBtn) {
      followBtn.addEventListener('click', function () {
        var following = followBtn.classList.contains('is-following');
        var method = following ? 'DELETE' : 'POST';
        fetch(api + '/graph/follow/' + encodeURIComponent(profile), {
          method: method,
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-Members-Nonce': nonce },
          body: JSON.stringify({ nonce: nonce }),
        })
          .then(function (r) {
            return r.json();
          })
          .then(function (data) {
            if (!data.ok) throw new Error(data.error || 'follow_failed');
            if (data.graph) repaint(data.graph);
          })
          .catch(function (err) {
            alert(err.message || 'Could not update follow.');
          });
      });
    }

    var blockBtn = root.querySelector('[data-graph-block]');
    if (blockBtn) {
      blockBtn.addEventListener('click', function () {
        var blocked = /unblock/i.test(blockBtn.textContent || '');
        if (!blocked && !confirm('Block @' + profile + '? They will not see your posts and you will unfollow each other.')) {
          return;
        }
        var method = blocked ? 'DELETE' : 'POST';
        fetch(api + '/graph/block/' + encodeURIComponent(profile), {
          method: method,
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-Members-Nonce': nonce },
          body: JSON.stringify({ nonce: nonce }),
        })
          .then(function (r) {
            return r.json();
          })
          .then(function (data) {
            if (!data.ok) throw new Error(data.error || 'block_failed');
            return fetch(api + '/graph/stats/' + encodeURIComponent(profile), { credentials: 'same-origin' });
          })
          .then(function (r) {
            return r.json();
          })
          .then(function (data) {
            if (data.ok && data.graph) repaint(data.graph);
            else location.reload();
          })
          .catch(function (err) {
            alert(err.message || 'Could not update block.');
          });
      });
    }
  }

  function mount(el) {
    var api = (el.getAttribute('data-api') || '').replace(/\/$/, '');
    var mode = el.getAttribute('data-mode') || 'profile';
    var user = el.getAttribute('data-user') || '';
    var canEdit = el.getAttribute('data-can-edit') === '1';
    var nonce = el.getAttribute('data-nonce') || '';
    var feedTabs = el.getAttribute('data-feed-tabs') === '1';
    var giphy = el.getAttribute('data-giphy') === '1';
    var reactionsEnabled = el.getAttribute('data-reactions') !== '0';
    var limits = mediaLimitsFrom(el);
    var scope = 'all';

    function postOpts(extra) {
      return Object.assign(
        {
          reactionsEnabled: reactionsEnabled,
          canReact: !!nonce,
          nonce: nonce,
        },
        extra || {}
      );
    }

    if (mode === 'single') {
      try {
        var post = JSON.parse(el.getAttribute('data-post') || '{}');
        var singleCanEdit = canEdit && post.author === user;
        el.innerHTML = renderPost(
          post,
          postOpts({ showAuthor: true, canEdit: singleCanEdit })
        );
        bindMediaGrids(el);
        bindReactions(el, api, nonce);
        bindShare(el);
        bind(el, api, nonce, user, function () {
          window.location.reload();
        }, null, { limits: limits });
      } catch (e) {
        el.textContent = 'Could not load post.';
      }
      return;
    }

    var listUrl = mode === 'site' ? api + '/feed' : api + '/activity/' + encodeURIComponent(user);

    function load(page) {
      el.innerHTML = '<p class="mambers-activity-loading">Loading…</p>';
      var qs = '?page=' + (page || 1);
      if (mode === 'site' && feedTabs) qs += '&scope=' + encodeURIComponent(scope);
      fetch(listUrl + qs, { credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data.ok) throw new Error(data.error || 'load_failed');
          var html = '';
          if (feedTabs && nonce) {
            html += feedTabsHtml(scope);
          }
          if ((canEdit || (mode === 'site' && nonce)) && nonce) {
            html += composerHtml(nonce, { giphy: giphy, limits: limits });
          }
          if (!data.items || !data.items.length) {
            html += '<p class="mambers-muted">' + (scope === 'following' ? 'No posts from people you follow yet.' : 'No posts yet.') + '</p>';
          } else {
            html += '<div class="mambers-activity-list">';
            data.items.forEach(function (post) {
              html += renderPost(
                post,
                postOpts({
                  showAuthor: mode === 'site',
                  canEdit: canEdit && post.author === user,
                })
              );
            });
            html += '</div>';
          }
          if (data.pages > 1) {
            html += '<nav class="mambers-activity-pager">';
            for (var p = 1; p <= data.pages; p++) {
              html +=
                '<button type="button" class="mambers-btn mambers-btn--ghost' +
                (p === data.page ? ' is-active' : '') +
                '" data-page="' +
                p +
                '">' +
                p +
                '</button>';
            }
            html += '</nav>';
          }
          el.innerHTML = html;
          bind(el, api, nonce, user, load, function (newScope) {
            scope = newScope;
            load(1);
          }, { giphy: giphy, limits: limits });
          bindMediaGrids(el);
          bindReactions(el, api, nonce);
          bindShare(el);
        })
        .catch(function () {
          el.innerHTML = '<p class="mambers-activity-error">Could not load activity.</p>';
        });
    }

    load(1);
  }

  function bindMediaGrids(root) {
    root.querySelectorAll('[data-mambers-grid]').forEach(function (grid) {
      var postId = grid.getAttribute('data-post');
      var postEl = root.querySelector('[data-post-id="' + postId + '"]');
      if (!postEl) return;
      var urls = [];
      try {
        urls = JSON.parse(grid.getAttribute('data-urls') || '[]');
      } catch (e) {
        grid.querySelectorAll('img').forEach(function (img) {
          urls.push(img.getAttribute('src'));
        });
      }
      grid.querySelectorAll('.mambers-media-cell').forEach(function (btn) {
        btn.addEventListener('click', function () {
          openLightbox(urls, parseInt(btn.getAttribute('data-index'), 10) || 0);
        });
      });
    });
  }

  function scheduleLinkPreview(editor, previewBox, api) {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(function () {
      var text = editor ? editor.innerText || '' : '';
      var url = extractFirstUrl(text);
      if (!url) {
        previewBox.hidden = true;
        previewBox.innerHTML = '';
        return;
      }
      fetch(api + '/link-preview', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ url: url }),
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          var link = data.link || data.preview;
          if (!data.ok || !link) {
            previewBox.hidden = true;
            return;
          }
          previewBox.hidden = false;
          previewBox.innerHTML = renderLinkCard(link);
        })
        .catch(function () {
          previewBox.hidden = true;
        });
    }, 600);
  }

  function initComposer(form, api, opts) {
    opts = opts || {};
    var limits = opts.limits || mediaLimitsFrom(form.closest('[data-mambers-activity]'));
    var editor = form.querySelector('[data-rich-editor]');
    var previewBox = form.querySelector('[data-compose-preview]');
    var gifStaging = form.querySelector('[data-gif-staging]');
    var mediaStaging = form.querySelector('[data-media-staging]');
    var mediaFiles = form.querySelector('[data-media-files]');
    var mediaWarn = form.querySelector('[data-media-warn]');
    var uploadProgress = form.querySelector('[data-upload-progress]');
    var uploadBar = form.querySelector('[data-upload-bar]');
    var uploadLabel = form.querySelector('[data-upload-label]');
    var fileInput = form.querySelector('input[type="file"][name="media[]"]');
    var attachBtn = form.querySelector('[data-composer-attach]');
    var mediaCount = form.querySelector('[data-media-count]');
    var submitBtn = form.querySelector('[data-post-submit]') || form.querySelector('button[type="submit"]');
    var mediaCheck = { ok: true, errors: [], hasValid: false };
    var gifUrls = [];
    var stagedMedia = [];
    var previewUrls = [];

    function revokePreviewUrls() {
      previewUrls.forEach(function (url) {
        if (url) URL.revokeObjectURL(url);
      });
      previewUrls = [];
    }

    function addStagedFiles(fileList) {
      Array.prototype.slice.call(fileList || []).forEach(function (file) {
        stagedMedia.push(file);
      });
      if (fileInput) fileInput.value = '';
      paintMediaStaging();
    }

    function removeStagedFile(index) {
      if (index < 0 || index >= stagedMedia.length) return;
      if (previewUrls[index]) URL.revokeObjectURL(previewUrls[index]);
      stagedMedia.splice(index, 1);
      paintMediaStaging();
    }

    function setUploadProgress(pct, loaded, total) {
      if (!uploadProgress) return;
      uploadProgress.hidden = false;
      if (uploadBar) {
        uploadBar.style.width = pct < 0 ? '35%' : pct + '%';
        uploadBar.classList.toggle('is-indeterminate', pct < 0);
      }
      if (uploadLabel) {
        if (pct < 0) {
          uploadLabel.textContent = 'Uploading… ' + formatBytes(loaded);
        } else {
          uploadLabel.textContent =
            'Uploading… ' + pct + '%' + (total ? ' (' + formatBytes(loaded) + ' / ' + formatBytes(total) + ')' : '');
        }
      }
    }

    function clearUploadProgress() {
      if (!uploadProgress) return;
      uploadProgress.hidden = true;
      if (uploadBar) {
        uploadBar.style.width = '0%';
        uploadBar.classList.remove('is-indeterminate');
      }
    }

    function paintMediaStaging() {
      if (!mediaStaging || !mediaFiles) return;
      mediaCheck = validateMediaFiles(stagedMedia, limits);
      if (!stagedMedia.length) {
        revokePreviewUrls();
        mediaStaging.hidden = true;
        mediaFiles.innerHTML = '';
        if (mediaWarn) mediaWarn.hidden = true;
        if (mediaCount) {
          mediaCount.hidden = true;
          mediaCount.textContent = '';
        }
        if (attachBtn) {
          attachBtn.classList.remove('is-active', 'has-error');
        }
        if (submitBtn) submitBtn.disabled = false;
        return;
      }
      if (mediaCount) {
        mediaCount.hidden = false;
        mediaCount.textContent = String(stagedMedia.length);
      }
      if (attachBtn) {
        attachBtn.classList.add('is-active');
        attachBtn.classList.toggle('has-error', !mediaCheck.ok);
      }
      mediaStaging.hidden = false;
      revokePreviewUrls();
      mediaFiles.innerHTML = '';
      mediaCheck.items.forEach(function (item, index) {
        var cell = document.createElement('div');
        cell.className = 'mambers-media-preview mambers-media-preview--' + item.status;
        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'mambers-media-preview-remove';
        removeBtn.setAttribute('data-media-remove', String(index));
        removeBtn.setAttribute('aria-label', 'Remove ' + item.file.name);
        removeBtn.textContent = '❌';
        cell.appendChild(removeBtn);

        var previewUrl = URL.createObjectURL(item.file);
        previewUrls.push(previewUrl);
        if (isVideoMime(item.file.type)) {
          var vid = document.createElement('video');
          vid.src = previewUrl;
          vid.muted = true;
          vid.playsInline = true;
          vid.preload = 'metadata';
          vid.setAttribute('aria-label', item.file.name);
          cell.appendChild(vid);
        } else {
          var img = document.createElement('img');
          img.src = previewUrl;
          img.alt = item.file.name;
          cell.appendChild(img);
        }

        var meta = document.createElement('span');
        meta.className = 'mambers-media-preview-meta';
        meta.textContent = formatBytes(item.file.size);
        cell.appendChild(meta);
        mediaFiles.appendChild(cell);
      });
      if (mediaWarn) {
        if (mediaCheck.errors.length) {
          mediaWarn.hidden = false;
          mediaWarn.innerHTML = mediaCheck.errors.map(function (e) {
            return '<span>' + esc(e) + '</span>';
          }).join('');
        } else {
          mediaWarn.hidden = true;
          mediaWarn.innerHTML = '';
        }
      }
      if (submitBtn) submitBtn.disabled = !mediaCheck.ok;
    }

    if (fileInput) {
      fileInput.addEventListener('change', function () {
        addStagedFiles(fileInput.files);
      });
    }

    if (mediaFiles) {
      mediaFiles.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-media-remove]');
        if (!btn) return;
        e.preventDefault();
        removeStagedFile(parseInt(btn.getAttribute('data-media-remove'), 10));
      });
    }

    function syncHidden() {
      if (!editor) return;
      form.querySelector('[data-body-plain]').value = (editor.innerText || '').trim().slice(0, 5000);
      form.querySelector('[data-body-html]').value = editor.innerHTML || '';
    }

    form.querySelectorAll('[data-cmd]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (!editor) return;
        editor.focus();
        var cmd = btn.getAttribute('data-cmd');
        if (cmd === 'link') {
          var u = prompt('Link URL');
          if (u) document.execCommand('createLink', false, u);
        } else {
          document.execCommand(cmd, false, null);
        }
        syncHidden();
        scheduleLinkPreview(editor, previewBox, api);
      });
    });

    if (editor) {
      editor.addEventListener('input', function () {
        syncHidden();
        scheduleLinkPreview(editor, previewBox, api);
      });
      editor.addEventListener('paste', function () {
        setTimeout(function () {
          syncHidden();
          scheduleLinkPreview(editor, previewBox, api);
        }, 50);
      });
    }

    var emojiPop = form.querySelector('[data-emoji-pop]');
    var emojiBtn = form.querySelector('[data-emoji-open]');
    if (emojiBtn && emojiPop) {
      emojiBtn.addEventListener('click', function () {
        emojiPop.hidden = !emojiPop.hidden;
        if (!emojiPop.hidden && !emojiPop.querySelector('emoji-picker')) {
          var picker = document.createElement('emoji-picker');
          picker.addEventListener('emoji-click', function (ev) {
            if (!editor) return;
            editor.focus();
            document.execCommand('insertText', false, ev.detail.unicode);
            syncHidden();
            emojiPop.hidden = true;
          });
          emojiPop.appendChild(picker);
        }
      });
    }

    var giphyModal = form.querySelector('[data-giphy-modal]');
    if (opts.giphy && giphyModal) {
      var giphySearch = giphyModal.querySelector('.mambers-giphy-search');
      var giphyResults = giphyModal.querySelector('.mambers-giphy-results');
      var giphyTimer = null;
      form.querySelector('[data-giphy-open]').addEventListener('click', function () {
        giphyModal.hidden = false;
        giphySearch.focus();
      });
      giphyModal.querySelector('[data-giphy-close]').addEventListener('click', function () {
        giphyModal.hidden = true;
      });
      giphySearch.addEventListener('input', function () {
        clearTimeout(giphyTimer);
        giphyTimer = setTimeout(function () {
          var q = giphySearch.value.trim();
          if (q.length < 2) return;
          fetch(api + '/giphy/search?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
            .then(function (r) {
              return r.json();
            })
            .then(function (data) {
              giphyResults.innerHTML = '';
              if (!data.ok || !data.items) return;
              data.items.forEach(function (item) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'mambers-giphy-hit';
                btn.innerHTML = '<img src="' + esc(item.preview || item.url) + '" alt="">';
                btn.addEventListener('click', function () {
                  gifUrls.push(item.url);
                  gifStaging.hidden = false;
                  gifStaging.innerHTML =
                    '<p class="mambers-muted">GIF attached</p><img src="' + esc(item.url) + '" alt="gif">';
                  giphyModal.hidden = true;
                });
                giphyResults.appendChild(btn);
              });
            });
        }, 400);
      });
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      syncHidden();
      paintMediaStaging();
      var status = form.querySelector('.mambers-activity-status');
      var plain = (form.querySelector('[data-body-plain]') || {}).value || '';
      var hasMedia = stagedMedia.length > 0;

      if (hasMedia && !mediaCheck.ok) {
        status.hidden = false;
        status.textContent = mediaCheck.errors[0] || 'Fix media files before posting.';
        return;
      }
      if (!plain.trim() && !hasMedia && !gifUrls.length) {
        status.hidden = false;
        status.textContent = 'Add text, a link, media, or a GIF.';
        return;
      }

      var fd = new FormData(form);
      stagedMedia.forEach(function (file) {
        fd.append('media[]', file);
      });
      gifUrls.forEach(function (u) {
        fd.append('gif_urls[]', u);
      });
      status.hidden = false;
      status.textContent = hasMedia ? 'Preparing upload…' : 'Posting…';
      if (submitBtn) submitBtn.disabled = true;
      clearUploadProgress();

      postFormData(api + '/activity', fd, hasMedia ? setUploadProgress : null)
        .then(function () {
          if (editor) editor.innerHTML = '';
          if (fileInput) fileInput.value = '';
          stagedMedia = [];
          revokePreviewUrls();
          gifUrls = [];
          if (gifStaging) {
            gifStaging.hidden = true;
            gifStaging.innerHTML = '';
          }
          if (mediaStaging) {
            mediaStaging.hidden = true;
          }
          if (mediaFiles) mediaFiles.innerHTML = '';
          if (mediaWarn) {
            mediaWarn.hidden = true;
            mediaWarn.innerHTML = '';
          }
          if (previewBox) {
            previewBox.hidden = true;
            previewBox.innerHTML = '';
          }
          clearUploadProgress();
          status.textContent = 'Posted!';
          form.dispatchEvent(new CustomEvent('mambers-posted', { bubbles: true }));
        })
        .catch(function (err) {
          clearUploadProgress();
          status.textContent = err.message || 'Post failed.';
        })
        .finally(function () {
          if (submitBtn) submitBtn.disabled = !mediaCheck.ok;
        });
    });

    form.addEventListener('mambers-posted', function () {
      /* reload wired in bind */
    });
  }

  function bindReactions(root, api, nonce) {
    if (!nonce || root.getAttribute('data-reactions-bound') === '1') return;
    root.setAttribute('data-reactions-bound', '1');

    root.addEventListener('click', function (e) {
      var btn = e.target.closest('.mambers-reaction-btn');
      if (!btn || !root.contains(btn)) return;

      var postEl = btn.closest('.mambers-reactions');
      var postId = postEl && postEl.getAttribute('data-post-id');
      var emoji = btn.getAttribute('data-react');
      if (!postId || !emoji) return;

      btn.disabled = true;
      fetch(api + '/activity/' + postId + '/react', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-Members-Nonce': nonce },
        body: JSON.stringify({ nonce: nonce, emoji: emoji }),
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data.ok) throw new Error(data.error || 'react_failed');
          var tmp = document.createElement('div');
          tmp.innerHTML = renderReactions(
            { id: postId, reactions: data.reactions },
            { reactionsEnabled: true, canReact: true, nonce: nonce }
          );
          var fresh = tmp.firstChild;
          if (postEl && fresh) postEl.replaceWith(fresh);
        })
        .catch(function (err) {
          alert(err.message || 'Reaction failed.');
        })
        .finally(function () {
          btn.disabled = false;
        });
    });
  }

  function bindShare(root) {
    root.querySelectorAll('.mambers-activity-share').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var url = absoluteUrl(btn.getAttribute('data-share-url') || window.location.href);
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(url).then(function () {
            btn.textContent = 'Copied!';
            setTimeout(function () {
              btn.textContent = 'Share';
            }, 2000);
          });
        } else {
          prompt('Copy link:', url);
        }
      });
    });
  }

  function bind(root, api, nonce, user, reload, setScope, composerOpts) {
    root.querySelectorAll('.mambers-feed-tab').forEach(function (tab) {
      tab.addEventListener('click', function () {
        if (setScope) setScope(tab.getAttribute('data-scope') || 'all');
      });
    });

    var form = root.querySelector('[data-mambers-composer]');
    if (form) {
      initComposer(form, api, composerOpts || {});
      form.addEventListener('mambers-posted', function () {
        reload(1);
      });
    }

    if (!root.getAttribute('data-edit-bound')) {
      root.setAttribute('data-edit-bound', '1');
      root.addEventListener('click', function (e) {
        var btn = e.target.closest('.mambers-activity-edit');
        if (!btn || !root.contains(btn)) return;
        var id = btn.getAttribute('data-id');
        var article = btn.closest('.mambers-activity-post');
        if (!id || !article) return;
        var post = {
          id: id,
          body: '',
          body_html: '',
          visibility: article.getAttribute('data-visibility') || 'public',
        };
        var bodyEl = article.querySelector('.mambers-activity-body');
        if (bodyEl) {
          if (bodyEl.classList.contains('mambers-activity-body--rich')) {
            post.body_html = bodyEl.innerHTML;
            post.body = bodyEl.innerText || '';
          } else {
            post.body = bodyEl.innerText || bodyEl.textContent || '';
          }
        }
        openPostEdit(
          article,
          post,
          api,
          nonce,
          {
            reactionsEnabled: root.getAttribute('data-reactions') !== '0',
            canReact: !!nonce,
            nonce: nonce,
            canEdit: true,
          },
          reload
        );
      });
    }

    root.querySelectorAll('.mambers-activity-delete').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = btn.getAttribute('data-id');
        if (!id || !confirm('Delete this post?')) return;
        fetch(api + '/activity/' + id, {
          method: 'DELETE',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-Members-Nonce': nonce },
          body: JSON.stringify({ nonce: nonce }),
        })
          .then(function (r) {
            return r.json();
          })
          .then(function (data) {
            if (!data.ok) throw new Error(data.error || 'delete_failed');
            reload(1);
          })
          .catch(function (err) {
            alert(err.message || 'Delete failed.');
          });
      });
    });

    root.querySelectorAll('.mambers-activity-pager [data-page]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        reload(parseInt(btn.getAttribute('data-page'), 10));
      });
    });
  }

  document.querySelectorAll('[data-mambers-social]').forEach(mountSocial);
  document.querySelectorAll('[data-mambers-activity]').forEach(mount);
})();
