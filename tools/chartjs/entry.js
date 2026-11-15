// Chart.js, reduced to what the game draws: line and bar charts on category, linear and logarithmic scales,
// with legend and tooltip. Exported as an ES module with everything registered.
import {
  Chart, Interaction, LineController, BarController, LineElement, PointElement, BarElement,
  CategoryScale, LinearScale, LogarithmicScale, Legend, Tooltip,
} from 'chart.js';
import { getRelativePosition } from 'chart.js/helpers';

Chart.register(
  LineController, BarController, LineElement, PointElement, BarElement,
  CategoryScale, LinearScale, LogarithmicScale, Legend, Tooltip,
);

export { Chart, Interaction, getRelativePosition };
