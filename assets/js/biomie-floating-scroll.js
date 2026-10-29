/**
 * Reuse existing horizontal table scroll owners. Only an otherwise clipped,
 * ownerless table receives a reversible wrapper around its original node.
 * Auto: real table scroll owners. Opt-in/out: data-biomie-floating-scroll="on|off".
 * Public lifecycle: window.BiomieFloatingScroll.init(), refresh(), destroy().
 */
(function (global, document) {
  'use strict';

  if (global.BiomieFloatingScroll) {
    global.BiomieFloatingScroll.init();
    return;
  }

  var ATTRIBUTE = 'data-biomie-floating-scroll';
  var LAYER = 'biomie-floating-scroll-layer';
  var SCOPE = 'dialog,[role="dialog"],[aria-modal="true"],.biomie-drawer,.detail-drawer,.mag-drawer,.finance-insight-drawer,.geo-drawer,.admin-modal';
  var EXCLUDE = 'nav,menu,[role="tablist"],.carousel,.swiper,.mag-drawer-tabs,.biomie-drawer__tabs';
  var MENU = '[role="menu"],[role="listbox"],.dropdown-menu,.select2-dropdown,.flatpickr-calendar,[data-biomie-floating-scroll-obstacle]';
  var records = new Map();
  var bars = new Map();
  var layers = new Map();
  var wrappers = new Map();
  var observed = new Set();
  var transitions = new Map();
  var resizeObserver = null;
  var mutationObserver = null;
  var active = false;
  var frame = 0;
  var scanNeeded = true;
  var sequence = 0;
  var rtlType = null;
  var GAP = 4;

  function own(node) {
    return !!(node && node.nodeType === 1 && node.closest('.' + LAYER));
  }

  function allowed(element) {
    if (element.closest('[' + ATTRIBUTE + '="off"]')) return false;
    return !!element.closest('[' + ATTRIBUTE + '="on"]') || !element.closest(EXCLUDE);
  }

  function scrolling(style) {
    return /^(auto|scroll|overlay)$/.test(style.overflowX);
  }

  function usableTableViewport(element) {
    if (element.tagName !== 'TABLE' || !element.parentElement) return true;
    var parent = element.parentElement;
    if (!parent.clientWidth) return true;
    var box = clientRect(parent);
    var style = global.getComputedStyle(parent);
    var padding = (parseFloat(style.paddingLeft) || 0) + (parseFloat(style.paddingRight) || 0);
    // A wide display:block table may have only a few pixels of incidental
    // internal overflow at zoomed breakpoints. Its 2800px viewport must not
    // replace the enclosing 560px scroller that exposes all data columns.
    return element.getBoundingClientRect().width <= box.right - box.left - padding * box.sx + 1;
  }

  function queue(scan) {
    if (!active) return;
    scanNeeded = scanNeeded || scan === true;
    if (!frame) frame = global.requestAnimationFrame(render);
  }

  function putStyle(element, name, value) {
    if (element.style[name] !== value) element.style[name] = value;
  }

  function scopeOf(owner) {
    return owner.closest(SCOPE) || document.body;
  }

  function layerFor(scope) {
    var layer = layers.get(scope);
    if (layer && layer.isConnected) return layer;
    layer = document.createElement('div');
    layer.className = LAYER;
    layer.setAttribute('data-biomie-floating-scroll-layer', scope === document.body ? 'page' : 'panel');
    scope.appendChild(layer);
    layers.set(scope, layer);
    return layer;
  }

  function create(owner) {
    var previousId = owner.getAttribute('id');
    var id = previousId;
    if (!id) {
      do { id = 'biomie-floating-scroll-source-' + (++sequence); } while (document.getElementById(id));
    }
    if (!previousId) owner.id = id;
    var previousMarker = owner.getAttribute('data-biomie-floating-scroll-source');
    owner.setAttribute('data-biomie-floating-scroll-source', id);
    var outer = document.createElement('div');
    outer.className = 'biomie-floating-scroll';
    outer.setAttribute('data-biomie-floating-scroll-for', id);
    outer.hidden = true;
    var viewport = document.createElement('div');
    viewport.className = 'biomie-floating-scroll__viewport';
    viewport.tabIndex = 0;
    viewport.setAttribute('role', 'scrollbar');
    viewport.setAttribute('aria-orientation', 'horizontal');
    viewport.setAttribute('aria-controls', id);
    viewport.setAttribute('aria-valuemin', '0');
    var content = document.createElement('div');
    content.className = 'biomie-floating-scroll__content';
    content.setAttribute('aria-hidden', 'true');
    viewport.appendChild(content);
    outer.appendChild(viewport);
    var record = {
      owner: owner, outer: outer, viewport: viewport, content: content,
      id: id, previousId: previousId, previousMarker: previousMarker,
      addedTabindex: false, tables: [], explicit: false, layer: null,
      sourceMax: 0, barTarget: null, visible: false, rtl: false
    };
    records.set(owner, record);
    bars.set(viewport, record);
    return record;
  }

  function restore(record) {
    if (record.outer.contains(document.activeElement)) record.viewport.blur();
    record.outer.remove();
    bars.delete(record.viewport);
    if (record.owner.getAttribute('data-biomie-floating-scroll-source') === record.id) {
      if (record.previousMarker === null) record.owner.removeAttribute('data-biomie-floating-scroll-source');
      else record.owner.setAttribute('data-biomie-floating-scroll-source', record.previousMarker);
    }
    if (!record.previousId && record.owner.id === record.id) record.owner.removeAttribute('id');
    if (record.addedTabindex && record.owner.getAttribute('tabindex') === '0') record.owner.removeAttribute('tabindex');
  }

  function scan() {
    scanNeeded = false;
    prepareFallbacks();
    var candidates = new Map();
    var targets = new Set();
    function add(owner, table, explicit) {
      if (!allowed(owner)) return;
      var entry = candidates.get(owner);
      if (!entry) { entry = { tables: [], explicit: false }; candidates.set(owner, entry); }
      if (table && entry.tables.indexOf(table) === -1) entry.tables.push(table);
      entry.explicit = entry.explicit || explicit;
    }
    document.querySelectorAll('table').forEach(function (table) {
      if (own(table) || !allowed(table) || /^(presentation|none)$/.test(table.getAttribute('role') || '')) return;
      targets.add(table);
      for (var element = table; element && element !== document.body && element !== document.documentElement; element = element.parentElement) {
        targets.add(element);
        if (scrolling(global.getComputedStyle(element)) && usableTableViewport(element)) add(element, table, false);
      }
    });
    document.querySelectorAll('[' + ATTRIBUTE + '="on"]').forEach(function (element) {
      if (!own(element) && allowed(element)) {
        if (usableTableViewport(element)) add(element, element.tagName === 'TABLE' ? element : null, true);
        for (var parent = element; parent && parent !== document.documentElement; parent = parent.parentElement) targets.add(parent);
      }
    });
    records.forEach(function (record, owner) {
      if (!candidates.has(owner) || !owner.isConnected) { restore(record); records.delete(owner); }
    });
    candidates.forEach(function (entry, owner) {
      var record = records.get(owner) || create(owner);
      record.tables = entry.tables;
      record.explicit = entry.explicit;
      var layer = layerFor(scopeOf(owner));
      if (record.outer.parentElement !== layer) layer.appendChild(record.outer);
      record.layer = layer;
    });
    layers.forEach(function (layer, scope) {
      if (!layer.children.length || !scope.isConnected) { layer.remove(); layers.delete(scope); }
    });
    if (resizeObserver) {
      observed.forEach(function (element) { if (!targets.has(element)) resizeObserver.unobserve(element); });
      targets.forEach(function (element) { if (!observed.has(element)) resizeObserver.observe(element); });
      observed = targets;
    }
  }

  function unwrap(table, wrapper) {
    if (wrapper.parentNode) {
      var fragment = document.createDocumentFragment();
      while (wrapper.firstChild) fragment.appendChild(wrapper.firstChild);
      wrapper.replaceWith(fragment);
    }
    wrappers.delete(table);
  }

  function prepareFallbacks() {
    wrappers.forEach(function (wrapper, table) {
      if (!table.isConnected || table.parentElement !== wrapper) unwrap(table, wrapper);
      // Keep explicit configuration on an owned wrapper; unwrapping it would
      // erase its opt-out and immediately enhance the table again in this scan.
      else if (wrapper.getAttribute(ATTRIBUTE) === 'off') return;
      else if (!allowed(table)) unwrap(table, wrapper);
      else if (wrapper.hasAttribute(ATTRIBUTE)) return;
      else if (wrapper.clientWidth > 0 && table.scrollWidth <= wrapper.clientWidth + 1 && table.getBoundingClientRect().width <= clientRect(wrapper).right - clientRect(wrapper).left + 1) unwrap(table, wrapper);
    });
    document.querySelectorAll('table').forEach(function (table) {
      if (own(table) || wrappers.has(table) || !allowed(table) || /^(presentation|none)$/.test(table.getAttribute('role') || '') || hidden(table)) return;
      var parent = table.parentElement;
      if (!parent || /^(TABLE|THEAD|TBODY|TFOOT|TR)$/.test(parent.tagName)) return;
      var owner = false;
      var blocked = false;
      var tableRect = table.getBoundingClientRect();
      for (var element = table; element && element !== document.body && element !== document.documentElement; element = element.parentElement) {
        var style = global.getComputedStyle(element);
        if (!blocked && scrolling(style) && usableTableViewport(element) && element.scrollWidth > element.clientWidth + 1) { owner = true; break; }
        if (element !== table && /^(hidden|clip)$/.test(style.overflowX) && clientRect(element).right - clientRect(element).left < tableRect.width - 1) blocked = true;
      }
      if (owner) return;
      var parentStyle = global.getComputedStyle(parent);
      var available = clientRect(parent);
      var padding = (parseFloat(parentStyle.paddingLeft) || 0) + (parseFloat(parentStyle.paddingRight) || 0);
      var width = available.right - available.left - padding * available.sx;
      if (width < 1 || Math.max(tableRect.width, table.scrollWidth * (table.offsetWidth ? tableRect.width / table.offsetWidth : 1)) <= width + 1) return;
      // Preserve the exact table node, IDs and listeners. Only an otherwise
      // clipped, ownerless table gets this reversible local scroll container.
      var wrapper = document.createElement('div');
      wrapper.className = 'biomie-floating-scroll-source-wrap';
      wrapper.setAttribute('data-biomie-floating-scroll-wrapper', '');
      parent.insertBefore(wrapper, table);
      wrapper.appendChild(table);
      wrappers.set(table, wrapper);
    });
  }

  function intersect(a, b, x, y) {
    if (x) { a.left = Math.max(a.left, b.left); a.right = Math.min(a.right, b.right); }
    if (y) { a.top = Math.max(a.top, b.top); a.bottom = Math.min(a.bottom, b.bottom); }
    return a;
  }

  function clientRect(element) {
    var rect = element.getBoundingClientRect();
    var sx = element.offsetWidth ? rect.width / element.offsetWidth : 1;
    var sy = element.offsetHeight ? rect.height / element.offsetHeight : 1;
    var left = rect.left + element.clientLeft * sx;
    var top = rect.top + element.clientTop * sy;
    return { left: left, top: top, right: left + element.clientWidth * sx, bottom: top + element.clientHeight * sy, sx: sx || 1, sy: sy || 1 };
  }

  function viewportRect() {
    var view = global.visualViewport;
    var left = view ? view.offsetLeft : 0;
    var top = view ? view.offsetTop : 0;
    return { left: left, top: top, right: left + (view ? view.width : document.documentElement.clientWidth), bottom: top + (view ? view.height : global.innerHeight) };
  }

  function hidden(element) {
    return !element.isConnected || !element.getClientRects().length || !!element.closest('[hidden],[inert],[aria-hidden="true"]') || global.getComputedStyle(element).visibility !== 'visible';
  }

  function fixedContainingBlock(style) {
    return ['transform', 'perspective', 'filter', 'backdropFilter', 'translate', 'rotate', 'scale'].some(function (name) {
      return style[name] && style[name] !== 'none';
    }) || /(?:layout|paint|strict|content)/.test(style.contain || '') ||
      /(?:transform|perspective|filter|translate|rotate|scale|contain)/.test(style.willChange || '') || style.contentVisibility === 'auto';
  }

  function modalTopLayer(element) {
    try { return element.matches(':modal'); } catch (ignore) { return false; }
  }

  function clipOf(owner, box) {
    var clip = intersect(viewportRect(), box, true, true);
    if (modalTopLayer(owner)) return clip;
    var outsideFixed = global.getComputedStyle(owner).position === 'fixed';
    for (var ancestor = owner.parentElement; ancestor && ancestor !== document.body && ancestor !== document.documentElement; ancestor = ancestor.parentElement) {
      var style = global.getComputedStyle(ancestor);
      // Fixed panels escape ordinary overflow ancestors up to their actual
      // containing block. A background card must not shorten a viewport drawer.
      if (outsideFixed && !fixedContainingBlock(style)) {
        if (style.clipPath !== 'none') intersect(clip, clientRect(ancestor), true, true);
        continue;
      }
      outsideFixed = false;
      var paint = /(?:paint|strict|content)/.test(style.contain || '') || style.clipPath !== 'none';
      var x = paint || /^(auto|scroll|overlay|hidden|clip)$/.test(style.overflowX);
      var y = paint || /^(auto|scroll|overlay|hidden|clip)$/.test(style.overflowY);
      if (x || y) intersect(clip, clientRect(ancestor), x, y);
      if (modalTopLayer(ancestor)) break;
      outsideFixed = style.position === 'fixed';
    }
    return clip;
  }

  function nearestOwner(table, owner) {
    for (var element = table; element && element !== owner; element = element.parentElement) {
      if (records.has(element) && element.scrollWidth > element.clientWidth + 1) return false;
    }
    return true;
  }

  function dataBounds(record, clip) {
    if (record.explicit && !record.tables.length) return Object.assign({}, clip);
    var best = null;
    record.tables.forEach(function (table) {
      if (hidden(table) || !allowed(table) || !nearestOwner(table, record.owner)) return;
      var rect = table.getBoundingClientRect();
      var top = rect.top;
      if (table.tHead) top = Math.max(top, table.tHead.getBoundingClientRect().bottom);
      var band = { left: clip.left, right: clip.right, top: Math.max(clip.top, top), bottom: Math.min(clip.bottom, rect.bottom) };
      if (!best || band.bottom - band.top > best.bottom - best.top) best = band;
    });
    return best;
  }

  function nativeAllowed(owner, style) {
    if (style.scrollbarWidth === 'none') return false;
    try {
      if (!global.CSS || !global.CSS.supports || !global.CSS.supports('selector(::-webkit-scrollbar)')) return true;
      var scrollbar = global.getComputedStyle(owner, '::-webkit-scrollbar');
      if (scrollbar.display === 'none' || scrollbar.height === '0px') return false;
    } catch (ignore) { /* Firefox can omit WebKit pseudo-element styles. */ }
    return true;
  }

  function visibleBlocker(record, rect) {
    if (!document.elementsFromPoint) return null;
    var xs = [rect.left + 2, (rect.left + rect.right) / 2, rect.right - 2];
    var ys = [rect.top + 1, (rect.top + rect.bottom) / 2, rect.bottom - 1];
    for (var index = 0; index < xs.length * ys.length; index++) {
      var elements = document.elementsFromPoint(xs[index % xs.length], ys[Math.floor(index / xs.length)]);
      for (var item = 0; item < elements.length; item++) {
        var element = elements[item];
        if (own(element) || element === document.documentElement || element === document.body) continue;
        if (element === record.owner || element.contains(record.owner)) break;
        if (record.owner.contains(element)) {
          var menu = element.closest(MENU);
          if (menu && menu !== record.owner) return menu.getBoundingClientRect();
          // Normal table rows are the intended underlying surface; sticky controls are not.
          for (var parent = element; parent && parent !== record.owner; parent = parent.parentElement) {
            var position = global.getComputedStyle(parent).position;
            if ((position === 'sticky' || position === 'fixed') && !/^(TD|TH|TR|TBODY|TABLE)$/.test(parent.tagName)) return parent.getBoundingClientRect();
          }
          break;
        }
        var externalMenu = element.closest(MENU);
        return (externalMenu || element).getBoundingClientRect();
      }
    }
    return null;
  }

  function avoidObstacles(record, bounds, height) {
    var bottom = bounds.bottom - GAP;
    for (var attempt = 0; attempt < 6; attempt++) {
      var rect = { left: bounds.left + GAP, right: bounds.right - GAP, top: bottom - height, bottom: bottom };
      if (rect.right - rect.left < 64 || rect.top < bounds.top + GAP) return null;
      var obstacle = visibleBlocker(record, rect);
      if (!obstacle) return rect;
      if (obstacle.top >= bottom || obstacle.bottom <= rect.top) return null;
      bottom = Math.min(bottom, obstacle.top - GAP);
    }
    return null;
  }

  function nativeVisible(record, box, clip, style) {
    if (!nativeAllowed(record.owner, style)) return false;
    var owner = record.owner;
    var border = parseFloat(style.borderBottomWidth) || 0;
    var rect = owner.getBoundingClientRect();
    var gutter = Math.max(0, owner.offsetHeight - owner.clientHeight - owner.clientTop - border) * box.sy;
    var bottom = rect.bottom - border * box.sy;
    var strip = { left: clip.left + GAP, right: clip.right - GAP, top: bottom - Math.max(gutter, 3), bottom: bottom };
    // clip.bottom is the owner's client edge, so its own native gutter is checked
    // against the external clipping ancestors separately.
    var outside = clipOf(owner, { left: box.left, right: box.right, top: rect.top, bottom: bottom });
    if (strip.top < outside.top - 1 || strip.bottom > outside.bottom + 1 || strip.right - strip.left < 64) return false;
    return !visibleBlocker(record, strip);
  }

  function rtlMode() {
    if (rtlType) return rtlType;
    var outer = document.createElement('div');
    outer.dir = 'rtl';
    outer.style.cssText = 'position:absolute;left:-10000px;top:-10000px;width:4px;height:1px;overflow:scroll;visibility:hidden';
    var inner = document.createElement('div');
    inner.style.width = '8px';
    outer.appendChild(inner);
    document.body.appendChild(outer);
    if (outer.scrollLeft > 0) rtlType = 'default';
    else { outer.scrollLeft = 1; rtlType = outer.scrollLeft === 0 ? 'negative' : 'reverse'; }
    outer.remove();
    return rtlType;
  }

  function sourceLeft(record) {
    var value = record.owner.scrollLeft;
    if (!record.rtl) return Math.max(0, Math.min(record.sourceMax, value));
    var mode = rtlMode();
    return Math.max(0, Math.min(record.sourceMax, mode === 'negative' ? record.sourceMax + value : mode === 'reverse' ? record.sourceMax - value : value));
  }

  function setSourceLeft(record, value) {
    value = Math.max(0, Math.min(record.sourceMax, value));
    var next = value;
    if (record.rtl) {
      var mode = rtlMode();
      next = mode === 'negative' ? value - record.sourceMax : mode === 'reverse' ? record.sourceMax - value : value;
    }
    if (Math.abs(record.owner.scrollLeft - next) > 0.5) record.owner.scrollLeft = next;
  }

  function syncBar(record) {
    var max = record.viewport.scrollWidth - record.viewport.clientWidth;
    var value = sourceLeft(record);
    var next = record.sourceMax ? value / record.sourceMax * max : 0;
    if (Math.abs(record.viewport.scrollLeft - next) > 0.5) {
      record.barTarget = next;
      record.viewport.scrollLeft = next;
    }
    record.viewport.setAttribute('aria-valuemax', String(Math.round(record.sourceMax)));
    record.viewport.setAttribute('aria-valuenow', String(Math.round(value)));
  }

  function hide(record) {
    if (record.outer.contains(document.activeElement)) {
      // Transfer only this disappearing control's focus, never steal focus on scroll.
      if (!hidden(record.owner)) {
        if (!record.owner.hasAttribute('tabindex')) { record.owner.tabIndex = 0; record.addedTabindex = true; }
        record.owner.focus({ preventScroll: true });
      } else record.viewport.blur();
    }
    if (!record.outer.hidden) record.outer.hidden = true;
    record.visible = false;
  }

  function measure(record) {
    var owner = record.owner;
    if (hidden(owner) || !allowed(owner)) return null;
    var style = global.getComputedStyle(owner);
    record.rtl = style.direction === 'rtl';
    record.sourceMax = Math.max(0, owner.scrollWidth - owner.clientWidth);
    if (record.sourceMax <= 1 || owner.clientWidth < 1) return null;
    var box = clientRect(owner);
    var clip = clipOf(owner, box);
    if (clip.right - clip.left < 72 || clip.bottom <= clip.top) return null;
    var bounds = dataBounds(record, clip);
    if (!bounds || bounds.bottom <= bounds.top || nativeVisible(record, box, clip, style)) return null;
    var layer = record.layer;
    var layerRect = layer.getBoundingClientRect();
    var sx = layer.offsetWidth ? layerRect.width / layer.offsetWidth : 1;
    var sy = layer.offsetHeight ? layerRect.height / layer.offsetHeight : 1;
    if (!sx || !sy) return null;
    var height = parseFloat(global.getComputedStyle(record.outer).getPropertyValue('--biomie-floating-scroll-height')) || 24;
    var rect = avoidObstacles(record, bounds, height * sy);
    if (!rect) return null;
    var label = owner.getAttribute('data-biomie-floating-scroll-label');
    if (!label && record.tables[0]) label = record.tables[0].getAttribute('data-biomie-floating-scroll-label') || record.tables[0].getAttribute('aria-label') || (record.tables[0].caption && record.tables[0].caption.textContent.trim());
    return { record: record, rect: rect, left: (rect.left - layerRect.left) / sx, top: (rect.top - layerRect.top) / sy,
      width: (rect.right - rect.left) / sx, label: label || 'Rolagem horizontal da tabela' };
  }

  function render() {
    frame = 0;
    if (!active) return;
    if (scanNeeded) scan();
    var plans = [];
    records.forEach(function (record) {
      var plan = measure(record);
      if (plan) plans.push(plan); else hide(record);
    });
    // A nested table's own control takes precedence if the tracks would overlap.
    plans.forEach(function (plan) {
      var covered = plans.some(function (other) {
        return other !== plan && plan.record.owner.contains(other.record.owner) &&
          plan.rect.left < other.rect.right && plan.rect.right > other.rect.left &&
          plan.rect.top < other.rect.bottom && plan.rect.bottom > other.rect.top;
      });
      if (covered) { hide(plan.record); return; }
      var record = plan.record;
      putStyle(record.outer, 'left', plan.left + 'px');
      putStyle(record.outer, 'top', plan.top + 'px');
      putStyle(record.outer, 'width', plan.width + 'px');
      record.viewport.setAttribute('aria-label', plan.label);
      record.outer.hidden = false;
      record.visible = true;
    });
    // After geometry writes, map proportional ranges. Partial clipping must not
    // shorten the source's reachable range or jump its existing scroll position.
    plans.forEach(function (plan) {
      var record = plan.record;
      if (!record.visible) return;
      var width = record.viewport.clientWidth;
      putStyle(record.content, 'width', Math.max(width + 1, width * record.owner.scrollWidth / record.owner.clientWidth) + 'px');
      syncBar(record);
    });
    var moving = false;
    transitions.forEach(function (until, element) {
      if (!element.isConnected || until < Date.now()) transitions.delete(element);
      else moving = true;
    });
    if (moving) queue(false);
  }

  function onScroll(event) {
    var record = bars.get(event.target);
    if (record && record.visible) {
      var position = record.viewport.scrollLeft;
      if (record.barTarget !== null && Math.abs(position - record.barTarget) <= 1) record.barTarget = null;
      else {
        record.barTarget = null;
        var max = record.viewport.scrollWidth - record.viewport.clientWidth;
        if (max > 0) setSourceLeft(record, position / max * record.sourceMax);
      }
    }
    // Source notifications are measured in the shared frame. Writing the bar
    // immediately here can overwrite a newer pending bar gesture/scroll event.
    queue(false);
  }

  function onKey(event) {
    var record = bars.get(event.target);
    if (!record || !record.visible || event.altKey || event.ctrlKey || event.metaKey) return;
    var value = sourceLeft(record);
    if (event.key === 'ArrowLeft') value -= 40;
    else if (event.key === 'ArrowRight') value += 40;
    else if (event.key === 'Home') value = 0;
    else if (event.key === 'End') value = record.sourceMax;
    else if (event.key === 'PageDown') value += record.owner.clientWidth * 0.9;
    else if (event.key === 'PageUp') value -= record.owner.clientWidth * 0.9;
    else return;
    event.preventDefault();
    setSourceLeft(record, value);
    syncBar(record);
    queue(false);
  }

  function onMutation(mutations) {
    var changed = mutations.some(function (mutation) {
      if (own(mutation.target)) return false;
      if (mutation.type !== 'childList') return true;
      return Array.prototype.some.call(mutation.addedNodes, function (node) { return !own(node); }) ||
        Array.prototype.some.call(mutation.removedNodes, function (node) { return !own(node); });
    });
    if (changed) queue(true);
  }

  function onResize() { queue(true); }
  function onObservedResize() { queue(true); }
  function onLayout() { queue(false); }
  function onTransition(event) {
    if (own(event.target)) return;
    var relevant = event.target.matches && event.target.matches(SCOPE);
    if (!relevant) records.forEach(function (record) { if (event.target.contains(record.owner)) relevant = true; });
    if (!relevant) return;
    if (event.type === 'transitionrun' || event.type === 'animationstart') transitions.set(event.target, Date.now() + 1500);
    else transitions.delete(event.target);
    queue(false);
  }

  function init() {
    if (active) { queue(true); return api; }
    if (!document.body) { document.addEventListener('DOMContentLoaded', init, { once: true }); return api; }
    active = true;
    if (global.ResizeObserver) resizeObserver = new global.ResizeObserver(onObservedResize);
    if (global.MutationObserver) {
      mutationObserver = new global.MutationObserver(onMutation);
      mutationObserver.observe(document.body, { childList: true, subtree: true, characterData: true, attributes: true,
        attributeFilter: ['style', 'class', 'hidden', 'open', 'inert', 'aria-hidden', ATTRIBUTE, 'data-biomie-floating-scroll-label', 'data-biomie-floating-scroll-obstacle'] });
    }
    document.addEventListener('scroll', onScroll, { capture: true, passive: true });
    document.addEventListener('keydown', onKey);
    document.addEventListener('focusin', onLayout);
    document.addEventListener('focusout', onLayout);
    ['transitionrun', 'transitionend', 'transitioncancel', 'animationstart', 'animationend', 'animationcancel'].forEach(function (event) { document.addEventListener(event, onTransition, true); });
    global.addEventListener('resize', onResize, { passive: true });
    global.addEventListener('scroll', onLayout, { passive: true });
    if (global.visualViewport) {
      global.visualViewport.addEventListener('resize', onResize, { passive: true });
      global.visualViewport.addEventListener('scroll', onLayout, { passive: true });
    }
    if (document.fonts && document.fonts.addEventListener) document.fonts.addEventListener('loadingdone', onResize);
    queue(true);
    return api;
  }

  function destroy() {
    document.removeEventListener('DOMContentLoaded', init);
    active = false;
    if (frame) global.cancelAnimationFrame(frame);
    frame = 0;
    if (resizeObserver) resizeObserver.disconnect();
    if (mutationObserver) mutationObserver.disconnect();
    resizeObserver = mutationObserver = null;
    document.removeEventListener('scroll', onScroll, true);
    document.removeEventListener('keydown', onKey);
    document.removeEventListener('focusin', onLayout);
    document.removeEventListener('focusout', onLayout);
    ['transitionrun', 'transitionend', 'transitioncancel', 'animationstart', 'animationend', 'animationcancel'].forEach(function (event) { document.removeEventListener(event, onTransition, true); });
    global.removeEventListener('resize', onResize);
    global.removeEventListener('scroll', onLayout);
    if (global.visualViewport) {
      global.visualViewport.removeEventListener('resize', onResize);
      global.visualViewport.removeEventListener('scroll', onLayout);
    }
    if (document.fonts && document.fonts.removeEventListener) document.fonts.removeEventListener('loadingdone', onResize);
    records.forEach(restore);
    layers.forEach(function (layer) { layer.remove(); });
    wrappers.forEach(function (wrapper, table) { unwrap(table, wrapper); });
    records.clear(); bars.clear(); layers.clear(); observed.clear(); transitions.clear();
    scanNeeded = true;
    return api;
  }

  var api = { init: init, refresh: function () { queue(true); return api; }, destroy: destroy };
  global.BiomieFloatingScroll = api;
  init();
}(window, document));
