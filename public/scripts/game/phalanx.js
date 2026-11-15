import { qsa, now, getRestTimeFormat, setVisibleInterval } from '../app/core.js';

function FleetTime() {
	qsa('.fleets').forEach(function(el) {
		var s		= Number(el.dataset.fleetTime) - (now().getTime() - window.startTime) / 1000;
		if(s <= 0) {
			el.textContent = '-';
		} else {
			el.textContent = getRestTimeFormat(s);
		}
	});
}

onPageInit(function() {
	FleetTime();
	setVisibleInterval(FleetTime, 1000);
});
