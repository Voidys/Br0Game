import { qsa, now, getRestTimeFormat, setVisibleInterval } from '../app/core.js';
import { env } from '../app/env.js';

/**
 * Phalanx scan (modal or page): counts the remaining flight time of every listed fleet down. One interval
 * serves every load of the module's markup (the modal's content is new each time, the module runs once).
 */
function FleetTime() {
  qsa('.fleets').forEach((el) => {
    const s = Number(el.dataset.fleetTime) - (now().getTime() - env.startTime) / 1000;
    el.textContent = s <= 0 ? '-' : getRestTimeFormat(s);
  });
}

setVisibleInterval(FleetTime, 1000);
document.addEventListener('modal:load', FleetTime);
FleetTime();
