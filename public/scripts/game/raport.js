/**
 * Battle Report Statistics Visualization
 * Renders interactive statistics from the Rust battle engine report data.
 * Shows full FleetId/Player drill-down: source fleet → target fleet → ship table.
 * Runs on page init and for every report opened in the modal (modal:load, scripts/app/dialog.js);
 * the data comes from the JSON island #raport-data of the report template ({raportData, shipNames, raportLNG}).
 */
import { qs, qsa, on, show, hide, toggle, pageData, pretty_number } from '../app/core.js';

/**
 * Renders the "battle statistics" section of a combat report from the JSON island: per round and side the stat categories
 * (STAT_ORDER), plus a total over all rounds, each as fleet-to-fleet and ship-to-ship tables. Does nothing without round
 * data. DOM only; all numbers come from the server's battle engine result.
 */
/** Lucide sprite icon (same markup as the Twig icon() helper) */
const ico = (name) => '<svg class="icon" aria-hidden="true"><use href="./styles/resource/icons/lucide.svg#i-' + name + '"></use></svg>';

function renderRaport() {
  const { raportData, shipNames = {}, raportLNG = {} } = pageData('raport-data');
  if (!raportData || !raportData.rounds) return;

  // Stat category labels & icons
  let STAT_LABELS = {
    damage_done:        { label: raportLNG.damage_done,         icon: ico('swords'), color: '#e74c3c' },
    damage_dead:        { label: raportLNG.damage_dead,         icon: ico('skull'), color: '#c0392b' },
    shield_hit:         { label: raportLNG.shield_hit,          icon: ico('shield'), color: '#3498db' },
    ship_destroyed:     { label: raportLNG.ship_destroyed,      icon: ico('circle-x'), color: '#e67e22' },
    shield_bounced:     { label: raportLNG.shield_bounced,      icon: ico('shield-check'), color: '#2ecc71' },
    rapid_fire_done:    { label: raportLNG.rapid_fire_done,     icon: ico('activity'), color: '#f39c12' },
    explosion_triggered:{ label: raportLNG.explosion_triggered, icon: ico('triangle-alert'), color: '#9b59b6' },
    stunned_done:       { label: raportLNG.stunned_done,        icon: ico('timer'), color: '#8e44ad' }
  };

  let STAT_ORDER = [
    'damage_done', 'damage_dead', 'stunned_done', 'shield_hit',
    'ship_destroyed', 'shield_bounced', 'rapid_fire_done', 'explosion_triggered'
  ];

  /** Get a human-readable name for a ship ID */
  function getShipName(id) {
    return shipNames[id] || ('ID ' + id);
  }

  /** Format a number using the game's formatting settings */
  function fmt(n) {
    return pretty_number(n, n % 1 !== 0 ? 1 : 0);
  }

  /**
   * Get a player/fleet label for a given fleetId on a given side ('attacker'|'defender').
   * Looks up raportData.rounds[0].attackers[fleetId] or defenders[fleetId].
   */
  function getFleetLabel(side, fleetId) {
    let round0 = raportData.rounds && raportData.rounds[0];
    if (!round0) return raportLNG.fleet + ' #' + fleetId;

    let sideData = (side === 'attacker') ? round0.attackers : round0.defenders;
    if (sideData && sideData[fleetId] && sideData[fleetId].player) {
      return sideData[fleetId].player.username + ' (#' + fleetId + ')';
    }
    return raportLNG.fleet + ' #' + fleetId;
  }

  /**
   * Parse a 4-level StatBlock preserving both fleet levels.
   *
   * Rust StatBlock: { fromFleetId: { toFleetId: { fromShipId: { toShipId: value } } } }
   * (mixed arrays/objects due to PHP json_encode)
   *
   * Returns: {
   *   full:           { fromFleetId: { toFleetId: { fromShipId: { toShipId: value } } } },
   *   perSourceFleet: { fromFleetId: { fromShipId: { toShipId: value } } },
   *   total:          { fromShipId: { toShipId: value } },
   *   fromFleetIds:   [sorted],
   *   toFleetIdsOf:   { fromFleetId: [sorted target fleet IDs] }
   * }
   */
  function parseStatBlock(data) {
    let result = {
      full: {},
      perSourceFleet: {},
      total: {},
      fromFleetIds: [],
      toFleetIdsOf: {}
    };
    if (!data || typeof data !== 'object') return result;

    let fleetKeys = Object.keys(data);
    for (let fi = 0; fi < fleetKeys.length; fi++) {
      let fromFid = fleetKeys[fi];
      let toFleetData = data[fromFid];
      if (!toFleetData || typeof toFleetData !== 'object') continue;

      let toKeys = Object.keys(toFleetData);
      for (let ti = 0; ti < toKeys.length; ti++) {
        let toFid = toKeys[ti];
        let shipData = toFleetData[toFid];
        if (!shipData || typeof shipData !== 'object') continue;

        let srcShipKeys = Object.keys(shipData);
        for (let si = 0; si < srcShipKeys.length; si++) {
          let fromShipId = srcShipKeys[si];
          let targets = shipData[fromShipId];
          if (!targets || typeof targets !== 'object') continue;

          let tgtShipKeys = Object.keys(targets);
          for (let tsi = 0; tsi < tgtShipKeys.length; tsi++) {
            let toShipId = tgtShipKeys[tsi];
            let value = targets[toShipId];
            if (typeof value !== 'number' || value === 0) continue;

            // Full: preserve both fleet levels
            if (!result.full[fromFid]) result.full[fromFid] = {};
            if (!result.full[fromFid][toFid]) result.full[fromFid][toFid] = {};
            if (!result.full[fromFid][toFid][fromShipId]) result.full[fromFid][toFid][fromShipId] = {};
            result.full[fromFid][toFid][fromShipId][toShipId] =
              (result.full[fromFid][toFid][fromShipId][toShipId] || 0) + value;

            // Per source fleet: merge across toFleetId
            if (!result.perSourceFleet[fromFid]) result.perSourceFleet[fromFid] = {};
            if (!result.perSourceFleet[fromFid][fromShipId]) result.perSourceFleet[fromFid][fromShipId] = {};
            result.perSourceFleet[fromFid][fromShipId][toShipId] =
              (result.perSourceFleet[fromFid][fromShipId][toShipId] || 0) + value;

            // Total: merge across all fleets
            if (!result.total[fromShipId]) result.total[fromShipId] = {};
            result.total[fromShipId][toShipId] =
              (result.total[fromShipId][toShipId] || 0) + value;
          }
        }
      }
    }

    // Collect sorted fleet IDs
    result.fromFleetIds = Object.keys(result.full).sort(function (a, b) { return a - b; });
    for (let fk = 0; fk < result.fromFleetIds.length; fk++) {
      let fkey = result.fromFleetIds[fk];
      result.toFleetIdsOf[fkey] = Object.keys(result.full[fkey]).sort(function (a, b) { return a - b; });
    }
    return result;
  }

  /** Check if a flat stat object has any entries */
  function hasData(statObj) {
    for (let k in statObj) {
      if (statObj.hasOwnProperty(k)) return true;
    }
    return false;
  }

  /** Adds the values of a 4-level stat block (from fleet -> to fleet -> from ship -> to ship) into `target`, summing equal paths. */
  function mergeStatMaps(target, source) {
    if (!source || typeof source !== 'object') return;

    let fromFleetIds = Object.keys(source);
    for (let fi = 0; fi < fromFleetIds.length; fi++) {
      let fromFid = fromFleetIds[fi];
      let toFleetData = source[fromFid];
      if (!toFleetData || typeof toFleetData !== 'object') continue;

      let toFleetIds = Object.keys(toFleetData);
      for (let ti = 0; ti < toFleetIds.length; ti++) {
        let toFid = toFleetIds[ti];
        let shipData = toFleetData[toFid];
        if (!shipData || typeof shipData !== 'object') continue;

        let fromShipIds = Object.keys(shipData);
        for (let si = 0; si < fromShipIds.length; si++) {
          let fromShipId = fromShipIds[si];
          let targets = shipData[fromShipId];
          if (!targets || typeof targets !== 'object') continue;

          let toShipIds = Object.keys(targets);
          for (let tsi = 0; tsi < toShipIds.length; tsi++) {
            let toShipId = toShipIds[tsi];
            let value = targets[toShipId];
            if (typeof value !== 'number' || value === 0) continue;

            if (!target[fromFid]) target[fromFid] = {};
            if (!target[fromFid][toFid]) target[fromFid][toFid] = {};
            if (!target[fromFid][toFid][fromShipId]) target[fromFid][toFid][fromShipId] = {};
            target[fromFid][toFid][fromShipId][toShipId] =
              (target[fromFid][toFid][fromShipId][toShipId] || 0) + value;
          }
        }
      }
    }
  }

  /** Empty statistics object with every stat category present. */
  function buildEmptyStatistics() {
    let statistics = {};
    for (let i = 0; i < STAT_ORDER.length; i++) {
      statistics[STAT_ORDER[i]] = { maps: {} };
    }
    return statistics;
  }

  /** Sums the statistics of all rounds into one pseudo round ("total") with the same structure, per side. */
  function buildTotalRound(rounds) {
    let totalRound = {
      attacker: { Statistics: buildEmptyStatistics() },
      defender: { Statistics: buildEmptyStatistics() }
    };
    let sideKeys = ['attacker', 'defender'];

    for (let r = 0; r < rounds.length; r++) {
      let roundData = rounds[r];
      if (!roundData) continue;

      for (let si = 0; si < sideKeys.length; si++) {
        let sideKey = sideKeys[si];
        let sideData = roundData[sideKey];
        if (!sideData || !sideData.Statistics) continue;

        for (let sk = 0; sk < STAT_ORDER.length; sk++) {
          let statKey = STAT_ORDER[sk];
          let statData = sideData.Statistics[statKey];
          if (!statData || !statData.maps) continue;

          mergeStatMaps(totalRound[sideKey].Statistics[statKey].maps, statData.maps);
        }
      }
    }

    return totalRound;
  }

  /** True when any stat category of the side has a non-zero entry. */
  function sideHasStats(sideData) {
    if (!sideData || !sideData.Statistics) return false;

    for (let sk = 0; sk < STAT_ORDER.length; sk++) {
      let statData = sideData.Statistics[STAT_ORDER[sk]];
      if (!statData || !statData.maps) continue;

      let parsed = parseStatBlock(statData.maps);
      if (hasData(parsed.total)) return true;
    }

    return false;
  }

  /**
   * Build a summary table for a flat ship-to-ship stat block.
   * statData: { srcShipId: { tgtShipId: value } }
   */
  function buildStatTable(statData) {
    if (!hasData(statData)) return null;

    let targetIds = {};
    let sourceIds = [];
    for (let srcId in statData) {
      if (!statData.hasOwnProperty(srcId)) continue;
      sourceIds.push(srcId);
      for (let tgtId in statData[srcId]) {
        if (!statData[srcId].hasOwnProperty(tgtId)) continue;
        targetIds[tgtId] = true;
      }
    }
    let targetIdList = Object.keys(targetIds).sort(function (a, b) { return a - b; });
    sourceIds.sort(function (a, b) { return a - b; });

    if (sourceIds.length === 0 || targetIdList.length === 0) return null;

    let html = '<div class="stat-table-wrap">';
    html += '<table class="stat-table">';

    // Header
    html += '<thead><tr>';
    html += '<th class="stat-cell-header">→ ' + raportLNG.target + '</th>';
    for (let t = 0; t < targetIdList.length; t++) {
      html += '<th class="stat-cell-header stat-cell-target">' + getShipName(targetIdList[t]) + '</th>';
    }
    html += '<th class="stat-cell-header stat-cell-total">Σ</th>';
    html += '</tr></thead>';

    // Rows
    html += '<tbody>';
    let colTotals = {};
    let grandTotal = 0;
    for (let s = 0; s < sourceIds.length; s++) {
      let sid = sourceIds[s];
      html += '<tr>';
      html += '<td class="stat-cell-source">' + getShipName(sid) + '</td>';
      let rowTotal = 0;
      for (let t2 = 0; t2 < targetIdList.length; t2++) {
        let tid = targetIdList[t2];
        let val = (statData[sid] && statData[sid][tid]) ? statData[sid][tid] : 0;
        rowTotal += val;
        colTotals[tid] = (colTotals[tid] || 0) + val;
        grandTotal += val;
        let cls = 'stat-cell-value';
        if (val > 0) cls += ' stat-cell-has-value';
        html += '<td class="' + cls + '"' +
          ' title="' + getShipName(sid) + ' → ' + getShipName(tid) + ': ' + fmt(val) + '">' +
          (val > 0 ? fmt(val) : '<span class="stat-zero">-</span>') +
          '</td>';
      }
      html += '<td class="stat-cell-total">' + fmt(rowTotal) + '</td>';
      html += '</tr>';
    }

    // Footer
    html += '<tr class="stat-row-total">';
    html += '<td class="stat-cell-source stat-cell-total-label">Σ</td>';
    for (let t3 = 0; t3 < targetIdList.length; t3++) {
      let cval = colTotals[targetIdList[t3]] || 0;
      html += '<td class="stat-cell-total">' + fmt(cval) + '</td>';
    }
    html += '<td class="stat-cell-grand-total">' + fmt(grandTotal) + '</td>';
    html += '</tr>';

    html += '</tbody></table></div>';
    return html;
  }

  /**
   * Build a collapsible accordion element (generic helper).
   */
  function buildAccordion(id, icon, label, content, cssClass, btnClass) {
    let html = '<div class="' + (cssClass || 'stats-fleet-accordion') + '">';
    html += '<button class="' + (btnClass || 'stats-fleet-btn') + '" data-target="' + id + '" type="button">';
    html += '<span class="stats-acc-icon">' + icon + '</span>';
    html += '<span class="stats-acc-label">' + label + '</span>';
    html += '<span class="stats-acc-arrow">' + ico('chevron-right') + '</span>';
    html += '</button>';
    html += '<div class="stats-fleet-body" id="' + id + '" style="display:none;">';
    html += content;
    html += '</div></div>';
    return html;
  }

  /**
   * Build the content for a single stat category.
   *
   * ALWAYS shows the full hierarchy:
   *   Source Fleet → Target Fleet → Ship table
   *
   * sideKey: 'attacker' or 'defender' (the side whose statistics we display)
   * Target fleet labels come from the opposite side.
   */
  function buildStatContent(parsed, sideKey, roundIndex, statKey) {
    let fromIds = parsed.fromFleetIds;
    if (fromIds.length === 0) return '';

    let oppositeSide = (sideKey === 'attacker') ? 'defender' : 'attacker';
    let html = '';
    let pfx = 'stats-r' + roundIndex + '-' + sideKey + '-' + statKey;

    // Build per-source-fleet content
    for (let f = 0; f < fromIds.length; f++) {
      let srcFid = fromIds[f];
      let toIds = parsed.toFleetIdsOf[srcFid];
      let srcLabel = getFleetLabel(sideKey, srcFid);

      // Build inner content: target fleet sub-accordions
      let innerHtml = '';

      for (let tf = 0; tf < toIds.length; tf++) {
        let tgtFid = toIds[tf];
        let tgtLabel = '→ ' + getFleetLabel(oppositeSide, tgtFid);
        let tgtTable = buildStatTable(parsed.full[srcFid][tgtFid]);
        if (!tgtTable) continue;

        let tgtAccId = pfx + '-sf' + srcFid + '-tf' + tgtFid;
        innerHtml += buildAccordion(tgtAccId, ico('crosshair'), tgtLabel, tgtTable, 'stats-target-accordion', 'stats-fleet-btn stats-target-btn');
      }

      // Source fleet total (all targets merged) – show if multiple targets
      if (toIds.length > 1) {
        let srcTotalTable = buildStatTable(parsed.perSourceFleet[srcFid]);
        if (srcTotalTable) {
          let srcTotalAccId = pfx + '-sf' + srcFid + '-total';
          innerHtml += buildAccordion(srcTotalAccId, 'Σ', raportLNG.total_all_targets, srcTotalTable,
            'stats-target-accordion stats-fleet-total', 'stats-fleet-btn stats-fleet-btn-total');
        }
      }

      if (!innerHtml) continue;

      // Always wrap in source fleet accordion
      let srcAccId = pfx + '-sf' + srcFid;
      html += buildAccordion(srcAccId, ico('rocket'), srcLabel, innerHtml, 'stats-fleet-accordion', 'stats-fleet-btn');
    }

    // Grand total (all source + target fleets merged) – show if multiple source fleets
    if (fromIds.length > 1) {
      let grandTotalTable = buildStatTable(parsed.total);
      if (grandTotalTable) {
        let grandTotalAccId = pfx + '-grand-total';
        html += buildAccordion(grandTotalAccId, 'Σ', raportLNG.total_all_fleets, grandTotalTable,
          'stats-fleet-accordion stats-fleet-total', 'stats-fleet-btn stats-fleet-btn-total');
      }
    }

    return html;
  }

  /**
   * Build the complete statistics panel for one round
   */
  function buildRoundStats(roundData, roundIndex) {
    let html = '';
    let sides = [
      { key: 'attacker', label: raportLNG.attacker, cls: 'stats-attacker' },
      { key: 'defender', label: raportLNG.defender, cls: 'stats-defender' }
    ];

    for (let si = 0; si < sides.length; si++) {
      let side = sides[si];
      let sideData = roundData[side.key];
      if (!sideData || !sideData.Statistics) continue;
      let stats = sideData.Statistics;

      if (!sideHasStats(sideData)) continue;

      html += '<div class="stats-side ' + side.cls + '">';
      html += '<h4 class="stats-side-title">' + side.label + '</h4>';
      for (let k = 0; k < STAT_ORDER.length; k++) {
        let statKey = STAT_ORDER[k];
        let meta = STAT_LABELS[statKey];
        let statData = stats[statKey];
        if (!statData || !statData.maps) continue;

        let parsedStat = parseStatBlock(statData.maps);
        if (!hasData(parsedStat.total)) continue;

        let contentHtml = buildStatContent(parsedStat, side.key, roundIndex, statKey);
        if (!contentHtml) continue;

        let accordionId = 'stats-acc-r' + roundIndex + '-' + side.key + '-' + statKey;

        html += '<div class="stats-accordion">';
        html += '<button class="stats-accordion-btn" data-target="' + accordionId + '" type="button">';
        html += '<span class="stats-acc-icon">' + meta.icon + '</span>';
        html += '<span class="stats-acc-label">' + meta.label + '</span>';
        html += '<span class="stats-acc-arrow">' + ico('chevron-right') + '</span>';
        html += '</button>';
        html += '<div class="stats-accordion-body" id="' + accordionId + '" style="display:none;">';
        html += contentHtml;
        html += '</div></div>';
      }

      html += '</div>';
    }

    return html;
  }

  /**
   * Initialize the statistics panel
   */
  function init() {
    // the first report container not rendered yet (page and modal can both hold one)
    let container = [...document.querySelectorAll('#battle-statistics')].find((el) => !el.dataset.rendered);
    if (!container) return;
    container.dataset.rendered = '1';
    let rounds = raportData.rounds;
    let roundsWithStats = [];
    for (let r = 0; r < rounds.length; r++) {
      let rd = rounds[r];
      if (!rd) continue;
      let atkHas = sideHasStats(rd.attacker);
      let defHas = sideHasStats(rd.defender);
      if (atkHas || defHas) {
        roundsWithStats.push(r);
      }
    }

    if (roundsWithStats.length === 0) {
      container.innerHTML = '';
      return;
    }

    let html = '';
    let totalRound = buildTotalRound(rounds);

    // Round tabs plus a total tab aggregating all rounds.
    if (roundsWithStats.length > 0) {
      html += '<div class="stats-round-tabs">';
      for (let i = 0; i < roundsWithStats.length; i++) {
        let ri = roundsWithStats[i];
        let tabLabel = raportLNG.round + ' ' + (ri);
        html += '<button class="stats-round-tab' + (i === 0 ? ' active' : '') + '" data-round="' + ri + '">' + tabLabel + '</button>';
      }
      html += '<button class="stats-round-tab" data-round="total">' + raportLNG.total + '</button>';
      html += '</div>';
    }

    // Round panels
    for (let j = 0; j < roundsWithStats.length; j++) {
      let rIdx = roundsWithStats[j];
      html += '<div class="stats-round-panel" data-round-panel="' + rIdx + '"' +
        (j > 0 ? ' style="display:none;"' : '') + '>';
      html += buildRoundStats(rounds[rIdx], rIdx);
      html += '</div>';
    }
    html += '<div class="stats-round-panel" data-round-panel="total" style="display:none;">';
    html += buildRoundStats(totalRound, 'total');
    html += '</div>';

    container.innerHTML = html;

    // Event: Tab switching
    on(container, 'click', '.stats-round-tab', (event, tab) => {
      const round = tab.dataset.round;
      qsa('.stats-round-tab', container).forEach((other) => other.classList.remove('active'));
      tab.classList.add('active');
      qsa('.stats-round-panel', container).forEach(hide);
      qsa('[data-round-panel="' + round + '"]', container).forEach((panel) => show(panel));
    });

    // Event: Stat category accordion toggle and fleet / target sub-accordion toggle
    const toggleAccordion = (event, button) => {
      toggle(document.getElementById(button.dataset.target));
      button.classList.toggle('is-open');
      button.setAttribute('aria-expanded', button.classList.contains('is-open') ? 'true' : 'false');
    };
    on(container, 'click', '.stats-accordion-btn', toggleAccordion);
    on(container, 'click', '.stats-fleet-btn', toggleAccordion);

    // Auto-expand first stat accordion of each side
    qsa('.stats-round-panel', container)
      .filter((panel) => getComputedStyle(panel).display !== 'none')
      .forEach((panel) => {
        qsa('.stats-side', panel).forEach((side) => qs('.stats-accordion-btn', side)?.click());
      });

  }

  init();
}

// on DOM ready (after the inline data bridge) and for reports loaded into the modal
window.onPageInit(renderRaport);
document.addEventListener('modal:load', (event) => {
  if (event.detail.root.querySelector('#battle-statistics')) renderRaport();
});
