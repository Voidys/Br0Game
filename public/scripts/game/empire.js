// Empire page: collapsible row groups and the two score charts (points history on a log scale; score change
// per interval as bars + line on two aligned axes). Data: <script id="empire-chart-data"> (EmpireController).
import { on, cookie } from '../app/core.js';
import { Chart, readChartData, applyPageTheme, formatPoints, timeAgoTicks, tooltip } from '../app/charts.js';

// ------------------------------------------------------------------ collapsible row groups

const GROUPS = ['chart', 'ressources', 'buildings', 'technology', 'ships', 'defenses', 'missile'];

function showGroup(target, hidden) {
  document.querySelectorAll('.' + target).forEach((row) => row.classList.toggle('hidden', hidden));
  const arrow = document.getElementById(target + '-toggle');
  if (arrow) arrow.textContent = hidden ? '▼' : '▲';
}

function initGroups() {
  for (const target of GROUPS) {
    showGroup(target, localStorage.getItem(target) === 'true');
  }
  on(document, 'click', '[data-toggle-rows]', (event, el) => {
    const target = el.dataset.toggleRows;
    const hidden = localStorage.getItem(target) !== 'true';
    showGroup(target, hidden);
    localStorage.setItem(target, String(hidden));
    if (target === 'chart' && !hidden) {
      // canvases measured 0×0 while hidden
      requestAnimationFrame(() => ['playerChart', 'playerChartBars'].forEach((id) => Chart.getChart(id)?.resize()));
    }
  });
  on(document, 'input', 'input[data-cookie]', (event, input) => cookie.set(input.dataset.cookie, input.checked));
}

// ------------------------------------------------------------------ dataset visibility (remembered per chart)

const visibilityKey = (chartId) => 'empire-chart-visibility-' + chartId;

function applySavedVisibility(chartId, datasets) {
  let saved = null;
  try {
    saved = JSON.parse(localStorage.getItem(visibilityKey(chartId)) || 'null');
  } catch (e) {
    saved = null;
  }
  if (saved && typeof saved === 'object') {
    for (const dataset of datasets) {
      if (Object.hasOwn(saved, dataset.label)) {
        dataset.hidden = saved[dataset.label] === false;
      }
    }
  }
  return datasets;
}

function saveVisibility(chart) {
  const saved = {};
  chart.data.datasets.forEach((dataset, index) => {
    saved[dataset.label] = chart.isDatasetVisible(index);
  });
  localStorage.setItem(visibilityKey(chart.canvas.id), JSON.stringify(saved));
}

function legendClick(evt, legendItem, legend) {
  const chart = legend.chart;
  const index = legendItem.datasetIndex;
  chart.setDatasetVisibility(index, !chart.isDatasetVisible(index));
  configureBarAxes(chart);
  syncBarChartBox(chart);
  chart.resize();
  chart.update();
  saveVisibility(chart);
}

/** the bar chart box shrinks to its legend when every dataset is hidden */
function syncBarChartBox(chart) {
  const box = chart.canvas?.parentNode;
  if (!box?.classList.contains('empire-chart--bars')) {
    return;
  }
  const anyVisible = chart.data.datasets.some((dataset, i) => chart.isDatasetVisible(i));
  box.classList.toggle('is-legend-only', !anyVisible);
}

// ------------------------------------------------------------------ points chart: log scale with 1-2-5 ticks

const positiveOrNull = (values) => values.map((v) => (typeof v === 'number' && isFinite(v) && v > 0 ? v : null));

function logTickCandidates(min, max, coeffs) {
  const values = [];
  if (!(min > 0) || !(max > 0) || !isFinite(min) || !isFinite(max) || min > max) {
    return values;
  }
  const lo = min / 1.05;
  const hi = max * 1.05;
  const endExp = Math.ceil(Math.log10(hi) - 1e-12);
  for (let exp = Math.floor(Math.log10(lo) + 1e-12); exp <= endExp; exp++) {
    for (const coeff of coeffs) {
      const value = coeff * 10 ** exp;
      if (value >= lo && value <= hi) {
        values.push(value);
      }
    }
  }
  return values;
}

function niceLogTicks(scale) {
  let values = [];
  for (const sequence of [[1, 2, 5], [1, 5], [1]]) {
    values = logTickCandidates(scale.min, scale.max, sequence);
    if (values.length <= 8) break;
  }
  if (!values.length && scale.min > 0 && scale.max > 0) {
    values = scale.min === scale.max ? [scale.min] : [scale.min, scale.max];
  }
  scale.ticks = values.map((value) => ({ value }));
}

const formatLogTick = (value) => formatPoints(value).replace(/(\d+)\.0+(?= |$)/, '$1');

function pointsChart(data) {
  const line = (label, values, color, main) => ({
    label,
    data: positiveOrNull(values),
    borderColor: color,
    borderWidth: main ? 2 : 1,
    borderDash: main ? undefined : [2, 2],
    radius: 2,
  });

  return new Chart(document.getElementById('playerChart'), {
    type: 'line',
    data: {
      labels: data.hoursAgo,
      datasets: applySavedVisibility('playerChart', [
        line(data.labels.points, data.total, '#008fff', true),
        line(data.labels.buildings, data.build, '#addc8d'),
        line(data.labels.research, data.tech, '#843bff'),
        line(data.labels.fleets, data.fleet, '#c52b2f'),
        line(data.labels.defenses, data.defence, '#ffc166'),
      ]),
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      spanGaps: true,
      interaction: { mode: 'index', intersect: false },
      scales: {
        x: { ticks: timeAgoTicks() },
        y: { type: 'logarithmic', afterBuildTicks: niceLogTicks, ticks: { autoSkip: false, callback: formatLogTick } },
      },
      plugins: {
        legend: { onClick: legendClick },
        tooltip: {
          itemSort: tooltip.sortDescending,
          callbacks: {
            title: tooltip.titleFrom(data.times),
            labelColor: tooltip.lineColor,
            label: (context) => {
              const value = context.parsed.y;
              return value == null || !(value > 0) ? null : context.dataset.label + ': ' + formatPoints(value);
            },
          },
        },
      },
    },
  });
}

// ------------------------------------------------------------------ change chart: two axes with aligned zero lines

function dataRange(values) {
  let min = Math.min(...values);
  let max = Math.max(...values);
  if (!isFinite(min) || !isFinite(max)) {
    return { min: 0, max: 1 };
  }
  min = Math.min(min, 0);
  max = Math.max(max, 0);
  return { min, max: min === 0 && max === 0 ? 1 : max };
}

function niceNum(range) {
  if (!(range > 0) || !isFinite(range)) {
    return 1;
  }
  const exp = Math.floor(Math.log10(range));
  const frac = range / 10 ** exp;
  return (frac < 1.5 ? 1 : frac < 3 ? 2 : frac < 7 ? 5 : 10) * 10 ** exp;
}

/** step and number of steps below / above zero for about three steps on the larger side */
function niceAxisPlan(range) {
  const needNeg = -Math.min(range.min, 0);
  const needPos = Math.max(range.max, 0);
  const step = niceNum(Math.max(needNeg, needPos, 1e-9) / 3);
  const nNeg = needNeg > 0 ? Math.ceil(needNeg / step - 1e-9) : 0;
  let nPos = needPos > 0 ? Math.ceil(needPos / step - 1e-9) : 0;
  if (nNeg === 0 && nPos === 0) {
    nPos = 1;
  }
  return { step, nNeg, nPos };
}

const planRatio = (plan) => {
  const ratio = plan.nPos > 0 ? plan.nNeg / plan.nPos : 1;
  return isFinite(ratio) && ratio > 0 ? ratio : 1;
};

/** stretches an axis so that negative/positive extents have the given ratio (zero lines meet) */
function applyAlignedPlan(plan, ratio) {
  let min = -plan.nNeg * plan.step;
  let max = plan.nPos * plan.step;
  if (max > 0 && -min / max < ratio) {
    min = -max * ratio;
  } else if (min < 0 && max === 0 && isFinite(ratio) && ratio > 0) {
    max = -min / ratio;
  } else if (max > 0 && -min / max > ratio) {
    max = -min / ratio;
  }
  return { min, max, step: plan.step };
}

function alignedNiceAxes(relValues, absValues) {
  const relPlan = niceAxisPlan(dataRange(relValues));
  const absPlan = niceAxisPlan(dataRange(absValues));
  const ratio = Math.max(planRatio(relPlan), planRatio(absPlan));
  return { rel: applyAlignedPlan(relPlan, ratio), abs: applyAlignedPlan(absPlan, ratio) };
}

function ownNiceAxis(values) {
  const plan = niceAxisPlan(dataRange(values));
  return applyAlignedPlan(plan, planRatio(plan));
}

function barAxisState(relVisible, absVisible, relData, absData) {
  const { rel, abs } = relVisible && absVisible
    ? alignedNiceAxes(relData, absData)
    : { rel: ownNiceAxis(relData), abs: ownNiceAxis(absData) };
  if (!relVisible && absVisible) {
    return {
      x: { display: true },
      y: { display: false, limits: rel },
      y2: { display: true, position: 'left', drawGrid: true, limits: abs },
    };
  }
  return {
    x: { display: relVisible || absVisible },
    y: { display: 'auto', position: 'left', drawGrid: true, limits: rel },
    y2: { display: 'auto', position: 'right', drawGrid: false, limits: abs },
  };
}

function applyScaleLimits(scale, limits) {
  scale.min = limits.min;
  scale.max = limits.max;
  if (scale.ticks) {
    scale.ticks.stepSize = limits.step;
  }
}

function applyBarAxisState(chart, state) {
  const { x, y, y2 } = chart.options.scales;
  if (x) {
    x.display = !!state.x.display;
  }
  y.display = state.y.display;
  if (state.y.position) {
    y.position = state.y.position;
  }
  if (y.grid) {
    y.grid.drawOnChartArea = state.y.drawGrid !== false;
  }
  applyScaleLimits(y, state.y.limits);
  y2.display = state.y2.display;
  y2.position = state.y2.position;
  if (y2.grid) {
    y2.grid.drawOnChartArea = !!state.y2.drawGrid;
  }
  applyScaleLimits(y2, state.y2.limits);
}

function configureBarAxes(chart) {
  if (!chart.options.scales?.y2) {
    return;
  }
  const datasets = chart.data.datasets;
  const absIndex = datasets.findIndex((d) => d.yAxisID === 'y2');
  const relIndex = datasets.findIndex((d) => d.yAxisID !== 'y2');
  if (relIndex === -1 || absIndex === -1) {
    return;
  }
  applyBarAxisState(chart, barAxisState(
    chart.isDatasetVisible(relIndex),
    chart.isDatasetVisible(absIndex),
    datasets[relIndex].data,
    datasets[absIndex].data,
  ));
}

const withSign = (value, formatted) => (Number(value) < 0 ? formatted : '+' + formatted);
const formatRelative = (value) => Number(value).toLocaleString(undefined, { maximumFractionDigits: 2 }) + '%';

function formatRelativeTick(value) {
  const n = Number(value);
  if (!isFinite(n)) {
    return '';
  }
  return Math.abs(n - Math.round(n)) < 1e-6
    ? Math.round(n).toLocaleString() + '%'
    : n.toLocaleString(undefined, { maximumFractionDigits: 1 }) + '%';
}

function changeTooltipLine(dataset, index) {
  const value = dataset.data[index];
  return dataset.label + ': ' + withSign(value, dataset.yAxisID === 'y2' ? formatPoints(value) : formatRelative(value));
}

function changeChart(data) {
  const times = data.times.slice(1);
  const datasets = applySavedVisibility('playerChartBars', [
    {
      type: 'bar',
      label: 'Relative',
      yAxisID: 'y',
      order: 1,
      data: data.relative,
      backgroundColor: (context) => (context.dataset.data[context.dataIndex] < 0 ? '#ee4d2e' : '#addc8d'),
    },
    {
      type: 'line',
      label: 'Absolute',
      yAxisID: 'y2',
      order: 0,
      data: data.absolute,
      pointRadius: 0,
      pointHoverRadius: 4,
      pointHitRadius: 18,
      borderWidth: 2,
      borderColor: '#56B4E9',
    },
  ]);
  const axis = barAxisState(datasets[0].hidden !== true, datasets[1].hidden !== true, data.relative, data.absolute);
  const valueAxis = (state, callback, extraTicks) => ({
    type: 'linear',
    display: state.display,
    position: state.position || 'left',
    min: state.limits.min,
    max: state.limits.max,
    ticks: { autoSkip: false, includeBounds: false, stepSize: state.limits.step, callback, ...extraTicks },
    grid: { drawOnChartArea: !!state.drawGrid },
  });

  const chart = new Chart(document.getElementById('playerChartBars'), {
    data: { labels: data.hoursAgo.slice(1), datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      scales: {
        x: { display: axis.x.display, offset: false, ticks: timeAgoTicks(), grid: { offset: false } },
        y: valueAxis(axis.y, formatRelativeTick),
        y2: valueAxis(axis.y2, formatPoints, { precision: 0 }),
      },
      plugins: {
        legend: { onClick: legendClick, labels: { sort: (a, b) => a.datasetIndex - b.datasetIndex } },
        tooltip: {
          displayColors: false,
          // one tooltip entry (both values in it), taken from the first visible dataset
          filter: (item) => item.datasetIndex === item.chart.data.datasets.findIndex((d, i) => item.chart.isDatasetVisible(i)),
          callbacks: {
            title: tooltip.titleFrom(times),
            label: (context) => {
              const all = context.chart.data.datasets;
              const rel = all.find((d) => d.yAxisID !== 'y2');
              const abs = all.find((d) => d.yAxisID === 'y2');
              return [rel, abs].filter(Boolean).map((d) => changeTooltipLine(d, context.dataIndex));
            },
          },
        },
      },
    },
  });
  syncBarChartBox(chart);
  chart.resize();
}

// ------------------------------------------------------------------ start

window.onPageInit(() => {
  initGroups();
  const data = readChartData('empire-chart-data');
  if (data) {
    applyPageTheme();
    pointsChart(data);
    changeChart(data);
  }
});
