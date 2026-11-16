/**
 * NOC view editor. Tiles snap to a 12×6 board that maps to 1920×1080.
 * A drop that would hang off the board or cover another tile is refused.
 */
(function () {
  'use strict';

  var cfg = window.NocViewEditor;
  if (!cfg) return;

  var COLS = 12;
  var ROWS = 6;
  var modules = cfg.modules || {};
  var pageNames = Array.isArray(cfg.pages) ? cfg.pages : [];
  var tiles = (Array.isArray(cfg.tiles) ? cfg.tiles : []).map(function (tile) {
    return JSON.parse(JSON.stringify(tile));
  });
  var selected = tiles.length ? 0 : -1;
  var drag = null;
  var board = document.getElementById('nvBoard');
  var palette = document.getElementById('nvPalette');
  var detail = document.getElementById('nvDetail');
  var fitNote = document.getElementById('nvFit');
  var layoutInput = document.getElementById('layout_json');
  var slugInput = document.getElementById('nvSlug');
  var nameInput = document.getElementById('nvName');
  var urlNode = document.getElementById('nvUrl');
  var openLink = document.getElementById('nvOpen');
  if (!board || !palette || !layoutInput) return;

  function note(msg) {
    if (fitNote) fitNote.textContent = msg || '';
  }

  function slugify(value) {
    return String(value || '').toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40).replace(/-+$/g, '');
  }

  function overlaps(a, b) {
    return a.x < b.x + b.w && a.x + a.w > b.x && a.y < b.y + b.h && a.y + a.h > b.y;
  }

  function fits(candidate, ignoreIndex) {
    if (candidate.x < 0 || candidate.y < 0 || candidate.w < 1 || candidate.h < 1) return false;
    if (candidate.x + candidate.w > COLS || candidate.y + candidate.h > ROWS) return false;
    for (var i = 0; i < tiles.length; i++) {
      if (i === ignoreIndex) continue;
      if (overlaps(candidate, tiles[i])) return false;
    }
    return true;
  }

  function clampOrigin(x, y, w, h) {
    if (x + w > COLS) x = COLS - w;
    if (y + h > ROWS) y = ROWS - h;
    if (x < 0) x = 0;
    if (y < 0) y = 0;
    return { x: x, y: y };
  }

  function bestSize(module, x, y, ignoreIndex) {
    var sizes = (modules[module] && modules[module].sizes) || [];
    for (var i = 0; i < sizes.length; i++) {
      var origin = clampOrigin(x, y, sizes[i][0], sizes[i][1]);
      var tile = { module: module, x: origin.x, y: origin.y, w: sizes[i][0], h: sizes[i][1] };
      if (fits(tile, ignoreIndex)) return tile;
    }
    return null;
  }

  function cellFromEvent(ev) {
    var rect = board.getBoundingClientRect();
    var x = Math.floor(((ev.clientX - rect.left) / rect.width) * COLS);
    var y = Math.floor(((ev.clientY - rect.top) / rect.height) * ROWS);
    if (x < 0) x = 0;
    if (y < 0) y = 0;
    if (x > COLS - 1) x = COLS - 1;
    if (y > ROWS - 1) y = ROWS - 1;
    return { x: x, y: y };
  }

  function sizeLabel(w, h) {
    var names = {
      '12x6': 'Full',
      '7x6': 'Wide',
      '6x6': 'Half',
      '5x6': 'Narrow',
      '12x4': 'Full, short',
      '7x4': 'Wide, short',
      '6x4': 'Half, short',
      '5x4': 'Narrow, short',
      '12x2': 'Full strip',
      '7x2': 'Wide strip',
      '6x2': 'Half strip',
      '5x2': 'Narrow strip'
    };
    return names[w + 'x' + h] || (w + '×' + h);
  }

  function used(module) {
    return tiles.some(function (tile) { return tile.module === module; });
  }

  function rotateTile() {
    for (var i = 0; i < tiles.length; i++) {
      if (tiles[i].module === 'rotate') return tiles[i];
    }
    return null;
  }

  function refreshUrl() {
    if (!urlNode || !slugInput) return;
    var slug = slugify(slugInput.value) || 'name';
    var url = String(cfg.wallBase || '') + '?view=' + encodeURIComponent(slug);
    if (cfg.token) url += '&token=' + encodeURIComponent(cfg.token);
    urlNode.textContent = url;
    if (openLink) openLink.href = url;
  }

  function syncInput() {
    layoutInput.value = JSON.stringify(tiles.map(function (tile) {
      var item = { module: tile.module, x: tile.x, y: tile.y, w: tile.w, h: tile.h };
      if (tile.module === 'rotate') item.pages = (tile.pages || []).slice();
      return item;
    }));
  }

  function showGhost(tile, ok) {
    var ghost = board.querySelector('.nv-ghost');
    if (!ghost) {
      ghost = document.createElement('div');
      ghost.className = 'nv-ghost';
      board.appendChild(ghost);
    }
    if (!tile) {
      ghost.hidden = true;
      return;
    }
    ghost.hidden = false;
    ghost.classList.toggle('is-bad', !ok);
    ghost.style.gridColumn = (tile.x + 1) + ' / span ' + tile.w;
    ghost.style.gridRow = (tile.y + 1) + ' / span ' + tile.h;
  }

  function hideGhost() {
    var ghost = board.querySelector('.nv-ghost');
    if (ghost) ghost.hidden = true;
  }

  function renderPalette() {
    palette.innerHTML = '';
    Object.keys(modules).forEach(function (key) {
      var mod = modules[key];
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'nv-mod';
      btn.draggable = !used(key);
      btn.disabled = used(key);
      btn.dataset.module = key;
      var title = document.createElement('strong');
      title.textContent = mod.label;
      var blurb = document.createElement('span');
      blurb.textContent = mod.blurb;
      btn.appendChild(title);
      btn.appendChild(blurb);
      btn.addEventListener('dragstart', function (ev) {
        drag = { kind: 'new', module: key };
        if (ev.dataTransfer) {
          ev.dataTransfer.effectAllowed = 'copy';
          ev.dataTransfer.setData('text/plain', key);
        }
      });
      btn.addEventListener('dragend', function () {
        drag = null;
        hideGhost();
      });
      palette.appendChild(btn);
    });
  }

  function renderDetail() {
    if (!detail) return;
    detail.innerHTML = '';
    if (selected < 0 || !tiles[selected]) {
      detail.innerHTML = '<p class="text-muted">Select a tile to resize it or take it off the board.</p>';
      return;
    }
    var tile = tiles[selected];
    var mod = modules[tile.module];
    var box = document.createElement('div');
    box.className = 'nv-detail';
    var title = document.createElement('strong');
    title.textContent = mod ? mod.label : tile.module;
    box.appendChild(title);

    var sizes = document.createElement('div');
    sizes.className = 'nv-sizes';
    (mod ? mod.sizes : []).forEach(function (size) {
      var origin = clampOrigin(tile.x, tile.y, size[0], size[1]);
      var candidate = { module: tile.module, x: origin.x, y: origin.y, w: size[0], h: size[1] };
      var ok = size[0] === tile.w && size[1] === tile.h ? true : fits(candidate, selected);
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'nv-size' + (size[0] === tile.w && size[1] === tile.h ? ' is-on' : '');
      btn.disabled = !ok;
      btn.textContent = sizeLabel(size[0], size[1]);
      btn.addEventListener('click', function () {
        if (!ok) return;
        tiles[selected].x = candidate.x;
        tiles[selected].y = candidate.y;
        tiles[selected].w = candidate.w;
        tiles[selected].h = candidate.h;
        note('');
        render();
      });
      sizes.appendChild(btn);
    });
    box.appendChild(sizes);

    if (tile.module === 'rotate') {
      var pagesBox = document.createElement('div');
      pagesBox.className = 'nv-pages';
      pageNames.forEach(function (page) {
        var label = document.createElement('label');
        var input = document.createElement('input');
        input.type = 'checkbox';
        var onBoard = used(page);
        var checked = (tile.pages || []).indexOf(page) >= 0;
        input.checked = checked;
        input.disabled = onBoard;
        input.addEventListener('change', function () {
          var next = pageNames.filter(function (name) {
            if (name === page) return input.checked;
            return (tiles[selected].pages || []).indexOf(name) >= 0;
          });
          if (!next.length) {
            input.checked = true;
            note('Rotating pages needs at least one page.');
            return;
          }
          tiles[selected].pages = next;
          note('');
          syncInput();
        });
        label.appendChild(input);
        label.appendChild(document.createTextNode(' ' + ((modules[page] && modules[page].label) || page)));
        if (onBoard) {
          var hint = document.createElement('span');
          hint.className = 'text-muted';
          hint.textContent = ' (already on the board)';
          label.appendChild(hint);
        }
        pagesBox.appendChild(label);
      });
      box.appendChild(pagesBox);
    }

    var remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'btn btn-sm btn-secondary';
    remove.textContent = 'Remove from board';
    remove.addEventListener('click', function () {
      tiles.splice(selected, 1);
      selected = tiles.length ? 0 : -1;
      note('');
      render();
    });
    box.appendChild(remove);
    detail.appendChild(box);
  }

  function renderBoard() {
    board.innerHTML = '';
    if (!tiles.length) {
      var empty = document.createElement('div');
      empty.className = 'nv-empty';
      empty.textContent = 'Drag a module here';
      board.appendChild(empty);
    }
    tiles.forEach(function (tile, index) {
      var el = document.createElement('div');
      el.className = 'nv-tile' + (index === selected ? ' is-on' : '');
      el.draggable = true;
      el.style.gridColumn = (tile.x + 1) + ' / span ' + tile.w;
      el.style.gridRow = (tile.y + 1) + ' / span ' + tile.h;
      var mod = modules[tile.module];
      var title = document.createElement('strong');
      title.textContent = mod ? mod.label : tile.module;
      var meta = document.createElement('span');
      meta.textContent = sizeLabel(tile.w, tile.h);
      el.appendChild(title);
      el.appendChild(meta);
      el.addEventListener('click', function () {
        selected = index;
        note('');
        render();
      });
      el.addEventListener('dragstart', function (ev) {
        drag = { kind: 'move', index: index };
        if (ev.dataTransfer) {
          ev.dataTransfer.effectAllowed = 'move';
          ev.dataTransfer.setData('text/plain', tile.module);
        }
      });
      el.addEventListener('dragend', function () {
        drag = null;
        hideGhost();
      });
      board.appendChild(el);
    });
  }

  function render() {
    renderPalette();
    renderBoard();
    renderDetail();
    syncInput();
    refreshUrl();
  }

  board.addEventListener('dragover', function (ev) {
    if (!drag) return;
    ev.preventDefault();
    var cell = cellFromEvent(ev);
    if (drag.kind === 'new') {
      var placed = bestSize(drag.module, cell.x, cell.y, -1);
      if (placed) showGhost(placed, true);
      else {
        var sizes = (modules[drag.module] && modules[drag.module].sizes) || [[6, 6]];
        var origin = clampOrigin(cell.x, cell.y, sizes[0][0], sizes[0][1]);
        showGhost({ x: origin.x, y: origin.y, w: sizes[0][0], h: sizes[0][1] }, false);
      }
      return;
    }
    var current = tiles[drag.index];
    if (!current) return;
    var originMove = clampOrigin(cell.x, cell.y, current.w, current.h);
    var moved = { x: originMove.x, y: originMove.y, w: current.w, h: current.h };
    showGhost(moved, fits(moved, drag.index));
  });

  board.addEventListener('drop', function (ev) {
    if (!drag) return;
    ev.preventDefault();
    var cell = cellFromEvent(ev);
    if (drag.kind === 'new') {
      if (used(drag.module)) {
        note((modules[drag.module].label || 'That module') + ' is already on this view.');
        hideGhost();
        return;
      }
      var spinning = rotateTile();
      if (spinning && pageNames.indexOf(drag.module) >= 0 && (spinning.pages || []).indexOf(drag.module) >= 0) {
        note((modules[drag.module].label || 'That page') + ' is inside Rotating pages. Uncheck it there first.');
        hideGhost();
        return;
      }
      var placed = bestSize(drag.module, cell.x, cell.y, -1);
      if (!placed) {
        note('That element does not fit on the 1080p board.');
        hideGhost();
        return;
      }
      if (drag.module === 'rotate') {
        placed.pages = pageNames.filter(function (page) { return !used(page); });
        if (!placed.pages.length) {
          note('Every page is already on the board.');
          hideGhost();
          return;
        }
      }
      tiles.push(placed);
      selected = tiles.length - 1;
      note('');
    } else {
      var current = tiles[drag.index];
      var origin = clampOrigin(cell.x, cell.y, current.w, current.h);
      var moved = {
        module: current.module,
        x: origin.x,
        y: origin.y,
        w: current.w,
        h: current.h,
        pages: current.pages
      };
      if (!fits(moved, drag.index)) {
        note('That spot is already taken.');
      } else {
        tiles[drag.index] = moved;
        selected = drag.index;
        note('');
      }
    }
    drag = null;
    hideGhost();
    render();
  });

  if (nameInput && slugInput) {
    nameInput.addEventListener('input', function () {
      if (slugInput.dataset.touched === '1') return;
      slugInput.value = slugify(nameInput.value);
      refreshUrl();
    });
    slugInput.addEventListener('input', function () {
      slugInput.dataset.touched = '1';
      refreshUrl();
    });
  }

  render();
})();
