// Alliance points chart (alliance home and info pages). Data: <script id="alliance-chart-data">
// (shared.alliance.chart.html.twig). Many member lines cross each other, so the tooltip lists only the points
// near the cursor ("nearby" mode) instead of every member at that time.
import { Chart, Interaction, getRelativePosition, readChartData, applyPageTheme, formatPoints, timeAgoTicks, tooltip } from '../app/charts.js';

/** points within nearbyDistance px of the cursor, all at the time index of the closest one */
Interaction.modes.nearby = function (chart, event, options, useFinalPosition) {
  const position = getRelativePosition(event, chart);
  const maxDist2 = (options.nearbyDistance || 20) ** 2;
  const items = [];
  for (const meta of chart.getSortedVisibleDatasetMetas()) {
    meta.data.forEach((element, index) => {
      if (!element || element.skip) {
        return;
      }
      const center = element.getCenterPoint(useFinalPosition);
      const dist2 = (position.x - center.x) ** 2 + (position.y - center.y) ** 2;
      if (isFinite(dist2) && dist2 <= maxDist2) {
        items.push({ element, datasetIndex: meta.index, index, dist2 });
      }
    });
  }
  if (!items.length) {
    return [];
  }
  items.sort((a, b) => a.dist2 - b.dist2);
  const timeIndex = items[0].index;
  return items
    .filter((item) => item.index === timeIndex)
    .slice(0, options.nearbyLimit || 8)
    .map(({ element, datasetIndex, index }) => ({ element, datasetIndex, index }));
};

const finiteOrNull = (values) => values.map((v) => (typeof v === 'number' && isFinite(v) ? v : null));

window.onPageInit(() => {
  const data = readChartData('alliance-chart-data');
  const canvas = document.getElementById('allianceChart');
  if (!data || !canvas) {
    return;
  }
  applyPageTheme();
  new Chart(canvas, {
    type: 'line',
    data: {
      labels: data.timesAgo,
      datasets: data.datasets.map((dataset) => ({ ...dataset, data: finiteOrNull(dataset.data) })),
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      spanGaps: true,
      interaction: { mode: 'nearby', intersect: true, nearbyDistance: 20, nearbyLimit: 8 },
      elements: { point: { radius: 1, hoverRadius: 4, hitRadius: 14 } },
      scales: {
        x: { ticks: timeAgoTicks(), title: { display: Boolean(data.xTitle), text: data.xTitle } },
        y: { type: 'linear', beginAtZero: true, ticks: { callback: formatPoints }, title: { display: Boolean(data.yTitle), text: data.yTitle } },
      },
      plugins: {
        tooltip: {
          itemSort: tooltip.sortDescending,
          callbacks: {
            title: tooltip.titleFrom(data.times),
            labelColor: tooltip.lineColor,
            label: (context) => {
              const value = context.parsed.y;
              return value == null || !isFinite(value) ? null : context.dataset.label + ': ' + formatPoints(value);
            },
          },
        },
      },
    },
  });
});
