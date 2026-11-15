// Shared Chart.js setup for the game's charts (empire, alliance). Chart.js itself is the reduced build in
// ../lib/chart.js (rebuild: tools/chartjs/build.sh). Charts take their text and grid colours from the theme.
import { Chart, Interaction, getRelativePosition } from '../lib/chart.js';
import { shortly_number } from './core.js';

export { Chart, Interaction, getRelativePosition };

/** chart data the template printed as <script type="application/json" id="…"> */
export function readChartData(id) {
  const el = document.getElementById(id);
  return el ? JSON.parse(el.textContent) : null;
}

function colorWithAlpha(color, alpha) {
  const match = String(color).match(/rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)/i);
  return match ? `rgba(${match[1]}, ${match[2]}, ${match[3]}, ${alpha})` : color;
}

/** text, tick, grid and legend colours follow the theme's table text colour */
export function applyPageTheme() {
  const el = document.querySelector('.content td') || document.body;
  const text = getComputedStyle(el).color;
  const grid = colorWithAlpha(text, 0.4);
  const scale = Chart.defaults.scale;
  Chart.defaults.color = text;
  scale.ticks.color = text;
  scale.grid.color = grid;
  scale.grid.tickColor = grid;
  scale.border.color = colorWithAlpha(text, 0.55);
  Chart.defaults.plugins.legend.labels.color = text;
}

/** points as the game prints them (12.3 K), with a real no-break space for the canvas */
export function formatPoints(value) {
  if (value == null || !isFinite(Number(value))) {
    return '';
  }
  return String(shortly_number(value)).replace(/&nbsp;/g, ' ');
}

/** x axis of "time ago" labels: horizontal, thinned out */
export function timeAgoTicks() {
  return { autoSkip: true, autoSkipPadding: 6, minRotation: 0, maxRotation: 0, maxTicksLimit: 12 };
}

/** tooltip helpers: largest value first, colour box = line colour, title = full date of the point */
export const tooltip = {
  sortDescending(a, b) {
    const av = a.parsed.y;
    const bv = b.parsed.y;
    if (av == null) return 1;
    if (bv == null) return -1;
    return bv - av;
  },
  lineColor(context) {
    const color = context.dataset.borderColor;
    return { borderColor: color, backgroundColor: color };
  },
  titleFrom(times) {
    return (items) => (items.length ? times[items[0].dataIndex] : '');
  },
};
