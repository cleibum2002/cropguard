// assets/js/photo_viewer.js
//
// In-system photo viewer (panel revision): clicking a report or evidence photo
// opens it full size in a window on the same page, instead of a new browser
// tab. Arrows (or swipe, or the keyboard) flip through the other photos of the
// same gallery; Esc, the X or a click on the dark background closes it.
//
// Usage: mark each clickable photo with data-photo="<full-size url>" (an <a>
// with an href also works) and, optionally, data-caption="...". Photos inside
// the same element marked data-photo-group are flipped through together.
//   <div data-photo-group>
//     <a href="uploads/x.jpg" data-photo="uploads/x.jpg" data-caption="At farm (22 m)"><img src="uploads/x.jpg"></a>
//   </div>

(function () {
    'use strict';

    var photos = [], index = 0, box = null, img, caption, counter, prevBtn, nextBtn, lastFocus = null;

    function el(tag, css, html) {
        var e = document.createElement(tag);
        if (css) e.style.cssText = css;
        if (html) e.innerHTML = html;
        return e;
    }

    function btnCss(extra) {
        return 'position:absolute;display:flex;align-items:center;justify-content:center;width:44px;height:44px;' +
            'border:none;border-radius:9999px;background:rgba(255,255,255,0.15);color:#fff;font-size:18px;' +
            'cursor:pointer;' + extra;
    }

    function build() {
        box = el('div', 'position:fixed;inset:0;z-index:2000;background:rgba(10,15,12,0.92);display:none;' +
            'align-items:center;justify-content:center;padding:56px 12px 64px;');
        box.setAttribute('role', 'dialog');
        box.setAttribute('aria-modal', 'true');
        box.setAttribute('aria-label', 'Photo viewer');

        img = el('img', 'max-width:100%;max-height:100%;object-fit:contain;border-radius:12px;' +
            'box-shadow:0 10px 40px rgba(0,0,0,0.5);background:#111;user-select:none;');
        img.alt = '';

        var closeBtn = el('button', btnCss('top:12px;right:12px;'), '<i class="fa-solid fa-xmark"></i>');
        closeBtn.setAttribute('aria-label', 'Close');
        prevBtn = el('button', btnCss('left:12px;top:50%;transform:translateY(-50%);'), '<i class="fa-solid fa-chevron-left"></i>');
        prevBtn.setAttribute('aria-label', 'Previous photo');
        nextBtn = el('button', btnCss('right:12px;top:50%;transform:translateY(-50%);'), '<i class="fa-solid fa-chevron-right"></i>');
        nextBtn.setAttribute('aria-label', 'Next photo');

        var footer = el('div', 'position:absolute;left:0;right:0;bottom:16px;text-align:center;color:#fff;' +
            'font:600 12px/1.4 system-ui,sans-serif;padding:0 16px;');
        counter = el('div', 'font-weight:800;letter-spacing:0.05em;opacity:0.9;');
        caption = el('div', 'margin-top:2px;opacity:0.75;');
        footer.appendChild(counter);
        footer.appendChild(caption);

        box.appendChild(img);
        box.appendChild(closeBtn);
        box.appendChild(prevBtn);
        box.appendChild(nextBtn);
        box.appendChild(footer);
        document.body.appendChild(box);

        closeBtn.addEventListener('click', close);
        prevBtn.addEventListener('click', function (e) { e.stopPropagation(); go(-1); });
        nextBtn.addEventListener('click', function (e) { e.stopPropagation(); go(1); });
        // A click on the dark area (not the photo or a button) closes it.
        box.addEventListener('click', function (e) { if (e.target === box) close(); });

        // Swipe left/right on phones.
        var startX = null;
        box.addEventListener('touchstart', function (e) { startX = e.touches[0].clientX; }, { passive: true });
        box.addEventListener('touchend', function (e) {
            if (startX === null) return;
            var dx = e.changedTouches[0].clientX - startX;
            startX = null;
            if (Math.abs(dx) > 50) go(dx < 0 ? 1 : -1);
        });
    }

    function render() {
        var p = photos[index];
        img.src = p.src;
        img.alt = p.caption || ('Photo ' + (index + 1));
        counter.textContent = photos.length > 1 ? (index + 1) + ' / ' + photos.length : '';
        caption.textContent = p.caption || '';
        var many = photos.length > 1;
        prevBtn.style.display = many ? 'flex' : 'none';
        nextBtn.style.display = many ? 'flex' : 'none';
    }

    function go(step) {
        if (photos.length < 2) return;
        index = (index + step + photos.length) % photos.length;
        render();
    }

    function onKey(e) {
        if (e.key === 'Escape') { e.stopPropagation(); close(); }
        else if (e.key === 'ArrowLeft') go(-1);
        else if (e.key === 'ArrowRight') go(1);
    }

    function open(list, start) {
        if (!list || !list.length) return;
        if (!box) build();
        photos = list;
        index = Math.min(Math.max(start || 0, 0), list.length - 1);
        render();
        lastFocus = document.activeElement;
        box.style.display = 'flex';
        // Capture phase, so Esc closes the photo and not the report window under it.
        document.addEventListener('keydown', onKey, true);
        box.querySelector('button').focus();
    }

    function close() {
        if (!box) return;
        box.style.display = 'none';
        img.removeAttribute('src');
        document.removeEventListener('keydown', onKey, true);
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    // One listener for every page: any [data-photo] opens the viewer.
    document.addEventListener('click', function (e) {
        var hit = e.target.closest ? e.target.closest('[data-photo]') : null;
        if (!hit) return;
        e.preventDefault();
        var group = hit.closest('[data-photo-group]') || hit.parentElement;
        var items = Array.prototype.slice.call(group.querySelectorAll('[data-photo]'));
        var list = items.map(function (it) {
            return {
                src: it.getAttribute('data-photo') || it.getAttribute('href') || it.getAttribute('src'),
                caption: it.getAttribute('data-caption') || ''
            };
        });
        open(list, items.indexOf(hit));
    });

    window.CropGuardPhotos = { open: open, close: close };
})();
