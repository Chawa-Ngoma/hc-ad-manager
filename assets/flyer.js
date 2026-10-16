// Draws the flyer on a canvas: one renderer for the preview and for the picture
// that is saved and emailed. Logical units are 800 x 1200; the canvas backing
// store is 1200 x 1800 (scale 1.5). System fonts only.
(function () {
  'use strict';

  var W = 800, H = 1200, SCALE = 1.5;
  var SERIF = 'Georgia, "Times New Roman", serif';
  var SANS = '"Helvetica Neue", Helvetica, Arial, sans-serif';
  var INK = '#2b1d1d', ACCENT = '#c4483f', PINK = '#f4b9b2', PALE = '#f9d9d3';
  var CREAM = '#fbf3ee', LINE = '#ecc3bd', DARK_RED = '#8a2f28', BODY = '#4a3838';
  var WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
  var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August',
    'September', 'October', 'November', 'December'];

  // Icon parts on a 64 x 64 grid: filled path, line path, or accent dot [cx, cy, r].
  var ITEMS = [
    { key: 'shoes', label: 'Shoes', parts: [
      ['fill', 'M6 40C6 30 10 20 18 16l5 7c5 5 13 6 19 10 8 2 16 4 16 11v4H6z'],
      ['line', 'M6 48h52M26 28l4 4M32 31l4 4']] },
    { key: 'bags', label: 'Handbags', parts: [
      ['fill', 'M12 26h40l4 28H8z'], ['line', 'M22 26c0-16 20-16 20 0'], ['dot', 32, 38, 2.5]] },
    { key: 'jewel', label: 'Jewellery', parts: [
      ['line', 'M12 10c0 26 40 26 40 0'], ['fill', 'M32 40l7 7-7 10-7-10z']] },
    { key: 'cloth', label: 'Clothing', parts: [
      ['fill', 'M22 6h20l-3 16 10 34H15l10-34z'], ['line', 'M22 22h20']] },
    { key: 'card', label: 'Cardigans', parts: [
      ['fill', 'M22 8L6 18l6 16 8-4v26h24V30l8 4 6-16-16-10c-2 6-18 6-20 0z'], ['line', 'M32 14v42'],
      ['dot', 27, 26, 1.8], ['dot', 27, 38, 1.8], ['dot', 27, 50, 1.8]] },
    { key: 'blouse', label: 'Blouses', parts: [
      ['fill', 'M22 8L6 18l6 16 8-4v26h24V30l8 4 6-16-16-10-10 12z'], ['fill', 'M26 24l6-4 6 4-6 6z']] }
  ];

  function font(weight, size, family, italic) {
    return (italic ? 'italic ' : '') + weight + ' ' + size + 'px ' + family;
  }

  function txt(v) { return String(v == null ? '' : v).replace(/\s+/g, ' ').trim(); }

  // Width of text; letter spacing is added between characters, not after the last one.
  function textWidth(ctx, text, fnt, spacing) {
    ctx.font = fnt;
    if (!spacing) return ctx.measureText(text).width;
    var chars = Array.from(text), w = 0;
    chars.forEach(function (c) { w += ctx.measureText(c).width; });
    return w + spacing * Math.max(0, chars.length - 1);
  }

  // Draws text left-aligned at x (or centred on x), with optional letter spacing.
  function drawText(ctx, text, x, y, fnt, color, spacing, centre) {
    if (!text) return;
    ctx.font = fnt;
    ctx.fillStyle = color;
    if (centre) x -= textWidth(ctx, text, fnt, spacing) / 2;
    if (!spacing) { ctx.fillText(text, x, y); return; }
    Array.from(text).forEach(function (c) { ctx.fillText(c, x, y); x += ctx.measureText(c).width + spacing; });
  }

  function ellipsize(text, maxW, widthOf) {
    if (widthOf(text) <= maxW) return text;
    var chars = Array.from(text);
    while (chars.length && widthOf(chars.join('') + '…') > maxW) chars.pop();
    return chars.join('').replace(/\s+$/, '') + '…';
  }

  // Shrinks from start towards min until the text fits, then cuts it with an ellipsis.
  function fit(text, start, min, maxW, widthAt) {
    var size = start;
    while (size > min && widthAt(text, size) > maxW) size -= 0.5;
    return { size: size, text: ellipsize(text, maxW, function (t) { return widthAt(t, size); }) };
  }

  // Wraps words into lines no wider than maxW (a number, or a function of the line index).
  // A word wider than a line is broken by characters.
  function wrap(ctx, words, maxW, fontFor) {
    var lines = [], cur = null;
    var limit = function (i) { return typeof maxW === 'function' ? maxW(i) : maxW; };
    var width = function (t, it) { return textWidth(ctx, t, fontFor(it), 0); };
    var space = width(' ', false);
    words.forEach(function (w) {
      var chunks = [w.text];
      if (width(w.text, w.italic) > limit(lines.length)) {
        chunks = [];
        var s = '';
        Array.from(w.text).forEach(function (c) {
          if (s && width(s + c, w.italic) > limit(lines.length)) { chunks.push(s); s = c; } else { s += c; }
        });
        chunks.push(s);
      }
      chunks.forEach(function (chunk, i) {
        var cw = width(chunk, w.italic);
        if (cur && i === 0 && cur.w + space + cw <= limit(lines.length - 1)) {
          cur.segs.push({ text: chunk, italic: w.italic, x: cur.w + space });
          cur.w += space + cw;
        } else {
          cur = { segs: [{ text: chunk, italic: w.italic, x: 0 }], w: cw };
          lines.push(cur);
        }
      });
    });
    return lines;
  }

  // Keeps the first max lines; if lines were lost, the last kept line ends with an ellipsis.
  function limitLines(ctx, lines, max, maxW, fontFor) {
    if (lines.length <= max) return lines;
    var line = lines[max - 1];
    if (typeof maxW === 'function') maxW = maxW(max - 1);
    var lineW = function () {
      var s = line.segs[line.segs.length - 1];
      return s.x + textWidth(ctx, s.text, fontFor(s.italic), 0);
    };
    var ell = textWidth(ctx, '…', fontFor(false), 0);
    var last = line.segs[line.segs.length - 1];
    while (lineW() + ell > maxW && (last.text.length > 1 || line.segs.length > 1)) {
      if (last.text.length > 1) { last.text = last.text.slice(0, -1); }
      else { line.segs.pop(); last = line.segs[line.segs.length - 1]; }
    }
    last.text = last.text.replace(/\s+$/, '') + '…';
    return lines.slice(0, max);
  }

  function rr(ctx, x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
  }

  function icon(ctx, parts, x, y, size) {
    ctx.save();
    ctx.translate(x, y);
    ctx.scale(size / 64, size / 64);
    ctx.lineWidth = 2.5; ctx.lineJoin = 'round'; ctx.lineCap = 'round'; ctx.strokeStyle = INK;
    parts.forEach(function (p) {
      if (p[0] === 'dot') {
        ctx.fillStyle = ACCENT; ctx.beginPath(); ctx.arc(p[1], p[2], p[3], 0, Math.PI * 2); ctx.fill();
        return;
      }
      var path = new Path2D(p[1]);
      if (p[0] === 'fill') { ctx.fillStyle = PINK; ctx.fill(path); }
      ctx.stroke(path);
    });
    ctx.restore();
  }

  function parseDate(s) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s);
    if (!m) return null;
    var y = +m[1], mo = +m[2], d = +m[3], dt = new Date(y, mo - 1, d);
    if (dt.getFullYear() !== y || dt.getMonth() !== mo - 1 || dt.getDate() !== d) return null;
    return {
      long: WEEKDAYS[dt.getDay()] + ' ' + d + ' ' + MONTHS[mo - 1] + ' ' + y,
      short: WEEKDAYS[dt.getDay()] + ' ' + d + ' ' + MONTHS[mo - 1],
      kick: d + ' ' + MONTHS[mo - 1].toUpperCase()
    };
  }

  function fmtTime(s) {
    var m = /^(\d{2}):(\d{2})$/.exec(s);
    if (!m || +m[1] > 23 || +m[2] > 59) return '—';
    var h = +m[1] % 12 || 12;
    return (h < 10 ? '0' : '') + h + ':' + m[2] + (+m[1] < 12 ? ' AM' : ' PM');
  }

  function fields(ad) {
    var chosen = Array.isArray(ad.items) ? ad.items : [];
    return {
      venue: txt(ad.venue), title: txt(ad.title), partner: txt(ad.partner), desc: txt(ad.desc), pay: txt(ad.pay),
      date: parseDate(txt(ad.date)), time: fmtTime(txt(ad.time)),
      items: ITEMS.filter(function (it) { return chosen.indexOf(it.key) !== -1; }),
      contacts: [1, 2, 3].map(function (n) { return { name: txt(ad['c' + n + 'n']), phone: txt(ad['c' + n + 'p']) }; })
        .filter(function (c) { return c.name || c.phone; })
    };
  }

  // Headline, partner and description positions. The headline stays at 64 px when everything
  // fits above the item tiles (or the details card); otherwise it shrinks, down to 40 px.
  function layoutText(ctx, f) {
    var words = f.title ? f.title.split(' ').map(function (w, i, a) { return { text: w, italic: i === a.length - 1 }; }) : [];
    var descWords = f.desc ? f.desc.split(' ').map(function (w) { return { text: w, italic: false }; }) : [];
    var hasTiles = f.items.length > 0;
    // Last text baseline allowed: the tiles sit 14 below it (clear of descenders) and must end before the details card.
    var bottomLimit = hasTiles ? 612 : 720;
    var descFont = function () { return font(400, 26, SANS, false); };

    function headlineAt(size, maxLines) {
      var fontFor = function (it) { return font(700, size, SERIF, it); };
      var lines = limitLines(ctx, wrap(ctx, words, 470, fontFor), maxLines, 470, fontFor);
      var lh = size * 66 / 64;
      return { size: size, lines: lines, lh: lh, last: 170 + Math.max(0, lines.length - 1) * lh };
    }

    function below(head, maxDescLines) {
      var y = head.last, partner = null, desc = null;
      if (f.partner) { partner = { amp: y + 58, name: y + 120 }; y = partner.name; }
      if (descWords.length && maxDescLines > 0) {
        var first = y + 62;
        // Lines whose top is still beside the door (it ends at y 285, starts at x 604) wrap at 540, not 560.
        var descW = function (i) { return first + i * 39 - 21 < 290 ? 540 : 560; };
        var lines = limitLines(ctx, wrap(ctx, descWords, descW, descFont), Math.min(6, maxDescLines), descW, descFont);
        desc = { first: first, lines: lines };
        y = desc.first + (lines.length - 1) * 39;
      }
      return { partner: partner, desc: desc, bottom: y };
    }

    var head, rest, size;
    for (size = 64; size >= 40; size--) {
      head = headlineAt(size, 99);
      rest = below(head, 6);
      if (rest.bottom <= bottomLimit) break;
    }
    if (size < 40) { // still too tall at 40 px: cap the headline, then cut the description to fit
      head = headlineAt(40, 6);
      var start = head.last + (f.partner ? 120 : 0) + 62;
      rest = below(head, Math.floor((bottomLimit - start) / 39) + 1);
    }
    return { head: head, rest: rest, hasTiles: hasTiles };
  }

  function drawFlyer(canvas, ad) {
    if (canvas.width !== W * SCALE) { canvas.width = W * SCALE; canvas.height = H * SCALE; }
    var ctx = canvas.getContext('2d');
    var f = fields(ad || {});
    var L = layoutText(ctx, f);
    var boxes = {};

    ctx.setTransform(SCALE, 0, 0, SCALE, 0, 0);
    ctx.clearRect(0, 0, W, H);
    ctx.textBaseline = 'alphabetic';

    // Background, circles, door
    ctx.fillStyle = CREAM; ctx.fillRect(0, 0, W, H);
    ctx.fillStyle = PINK; ctx.beginPath(); ctx.arc(680, 100, 280, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = PALE; ctx.beginPath(); ctx.arc(80, 1140, 260, 0, Math.PI * 2); ctx.fill();
    ctx.save();
    ctx.translate(594, 110);
    ctx.strokeStyle = INK; ctx.lineWidth = 7; ctx.stroke(new Path2D('M30 170V20h60v150'));
    ctx.fillStyle = INK; ctx.fill(new Path2D('M90 20l32 10v130l-32 10z'));
    ctx.fillStyle = CREAM; ctx.beginPath(); ctx.arc(108, 98, 4, 0, Math.PI * 2); ctx.fill();
    ctx.lineWidth = 5; ctx.lineCap = 'round'; ctx.stroke(new Path2D('M10 172h130'));
    ctx.restore();

    // Kicker: venue in capitals, a dot, then day and month
    var kicker = (f.venue.toUpperCase() + (f.date ? (f.venue ? ' · ' : '') + f.date.kick : '')).trim();
    var k = fit(kicker, 17, 10, 672, function (t, s) { return textWidth(ctx, t, font(700, s, SANS), 5.4 * s / 17); });
    drawText(ctx, k.text, 64, 80, font(700, k.size, SANS), ACCENT, 5.4 * k.size / 17);
    boxes.kicker = { x: 64, y: 80 - k.size, w: textWidth(ctx, k.text, font(700, k.size, SANS), 5.4 * k.size / 17), h: k.size * 1.2 };

    // Headline: the last word is italic in the accent colour
    var h = L.head;
    h.lines.forEach(function (line, i) {
      line.segs.forEach(function (s) {
        drawText(ctx, s.text, 64 + s.x, 170 + i * h.lh, font(700, h.size, SERIF, s.italic), s.italic ? ACCENT : INK);
      });
    });
    boxes.headline = { x: 64, y: 170 - h.size * 0.8, w: 470, h: h.last - 170 + h.size };

    // Partner
    var r = L.rest;
    if (r.partner) {
      drawText(ctx, '&', 64, r.partner.amp, font(400, 34, SERIF, true), ACCENT);
      var nm = f.partner.toUpperCase();
      var p = fit(nm, 50, 22, 672, function (t, s) { return textWidth(ctx, t, font(400, s, SERIF), 0.04 * s); });
      drawText(ctx, p.text, 64, r.partner.name, font(400, p.size, SERIF), INK, 0.04 * p.size);
      boxes.partner = { x: 64, y: r.partner.amp - 34 * 0.8, w: 672, h: r.partner.name - r.partner.amp + 34 * 0.8 + p.size * 0.25 };
    }

    // Description
    if (r.desc) {
      r.desc.lines.forEach(function (line, i) {
        line.segs.forEach(function (s) { drawText(ctx, s.text, 64 + s.x, r.desc.first + i * 39, font(400, 26, SANS), BODY); });
      });
      boxes.desc = { x: 64, y: r.desc.first - 26 * 0.8, w: 560, h: (r.desc.lines.length - 1) * 39 + 26 };
    }

    // Item tiles
    if (L.hasTiles) {
      var top = Math.max(r.bottom + 14, 612);
      var n = f.items.length, tw = (672 - 10 * (n - 1)) / n;
      f.items.forEach(function (it, i) {
        var x = 64 + i * (tw + 10);
        rr(ctx, x, top, tw, 110, 20); ctx.fillStyle = '#fff'; ctx.fill();
        ctx.lineWidth = 1.5; ctx.strokeStyle = LINE; ctx.stroke();
        icon(ctx, it.parts, x + tw / 2 - 29, top + 16, 58);
        drawText(ctx, it.label, x + tw / 2, top + 97, font(700, 14, SANS), DARK_RED, 0, true);
      });
      boxes.tiles = { x: 64, y: top, w: 672, h: 110 };
    }

    // Details card
    ctx.save();
    ctx.shadowColor = 'rgba(196,72,63,0.28)'; ctx.shadowBlur = 40 * SCALE; ctx.shadowOffsetY = 18 * SCALE;
    rr(ctx, 64, 746, 672, 208, 28); ctx.fillStyle = ACCENT; ctx.fill();
    ctx.restore();
    var rows = [['VENUE', f.venue ? f.venue.toUpperCase() : '—', 813], ['TIME', f.time, 865],
      ['DATE', f.date ? f.date.long : '—', 917]];
    rows.forEach(function (row) {
      drawText(ctx, row[0], 104, row[2], font(400, 15, SANS), 'rgba(255,255,255,0.78)', 3.7);
      var v = fit(row[1], 31, 16, 500, function (t, s) { return textWidth(ctx, t, font(700, s, SANS), 0); });
      drawText(ctx, v.text, 202, row[2], font(700, v.size, SANS), '#fff');
    });
    boxes.card = { x: 64, y: 746, w: 672, h: 208 };

    // Payment line with a small card icon
    if (f.pay) {
      ctx.save();
      ctx.translate(64, 983);
      rr(ctx, 1, 1, 42, 28, 5); ctx.fillStyle = '#fff'; ctx.fill();
      ctx.lineWidth = 2; ctx.strokeStyle = ACCENT; ctx.stroke();
      ctx.fillStyle = ACCENT; ctx.fillRect(1, 7, 42, 6);
      rr(ctx, 6, 19, 12, 4, 2); ctx.fillStyle = PINK; ctx.fill();
      ctx.restore();
      var pay = fit(f.pay, 22, 14, 614, function (t, s) { return textWidth(ctx, t, font(700, s, SANS), 0); });
      drawText(ctx, pay.text, 122, 1005, font(700, pay.size, SANS), INK);
      boxes.pay = { x: 64, y: 983, w: 672, h: 30 };
    }

    // Contact boxes: one size for every number
    var cn = f.contacts.length;
    if (cn) {
      var bw = (672 - 16 * (cn - 1)) / cn, inner = bw - 40, numSize = 31;
      var numW = function (t, s) { return textWidth(ctx, t, font(800, s, SANS), 0); };
      f.contacts.forEach(function (c) { numSize = Math.min(numSize, fit(c.phone, 31, 14, inner, numW).size); });
      f.contacts.forEach(function (c, i) {
        var x = 64 + i * (bw + 16);
        rr(ctx, x, 1036, bw, 94, 20); ctx.fillStyle = '#fff'; ctx.fill();
        ctx.lineWidth = 1.5; ctx.strokeStyle = LINE; ctx.stroke();
        var nmFit = fit(c.name.toUpperCase(), 14, 9, inner, function (t, s) { return textWidth(ctx, t, font(700, s, SANS), 2.8 * s / 14); });
        drawText(ctx, nmFit.text, x + 20, 1036 + 32, font(700, nmFit.size, SANS), ACCENT, 2.8 * nmFit.size / 14);
        drawText(ctx, ellipsize(c.phone, inner, function (t) { return numW(t, numSize); }), x + 20, 1036 + 70, font(800, numSize, SANS), INK);
      });
      boxes.contacts = { x: 64, y: 1036, w: 672, h: 94 };
    }

    boxes.headlineSize = h.size;
    return boxes; // where each part was drawn, in logical units
  }

  window.drawFlyer = drawFlyer;
  // "Wednesday 30 September" for an ISO date, from the same fixed English names; '' when it is not a date.
  window.flyerDate = function (iso) { var d = parseDate(iso); return d ? d.short : ''; };
})();
