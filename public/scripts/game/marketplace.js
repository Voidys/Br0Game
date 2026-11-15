import { qs, qsa, on, now, pretty_number, getRestTimeFormat, setVisibleInterval } from '../app/core.js';

function Refrash() {
	qsa("[data-time]").forEach(function (el) {
		var remaining = Number(el.dataset.time) - (now().getTime() - window.startTime) / 1000;
		el.textContent = getRestTimeFormat(remaining);
	});
}


//-------------------
function parseReferenceRatio(value) {
	if (typeof value === 'number') {
		return isFinite(value) && value > 0 ? value : NaN;
	}
	var text = String(value == null ? '' : value).replace(/\s/g, '');
	if (text === '') {
		return NaN;
	}
	// decimalSeparator 0 is comma (same as pretty_number). A lone '.' stays a
	// decimal so machine-formatted data attributes still parse.
	var european = (typeof decimalSeparator !== 'undefined' && String(decimalSeparator) === '0');
	if (european) {
		if (text.indexOf(',') !== -1) {
			text = text.replace(/\./g, '').replace(',', '.');
		}
	} else if (text.indexOf(',') !== -1) {
		text = text.replace(/,/g, '');
	}
	var ratio = parseFloat(text);
	return isFinite(ratio) && ratio > 0 ? ratio : NaN;
}

function formatReferenceRatio(value) {
	var ratio = parseReferenceRatio(value);
	return isFinite(ratio) ? pretty_number(ratio, 2) : value;
}

function officialReferenceRatios() {
	var box = qs('#ratioBaselines');
	return {
		metal: parseReferenceRatio(box?.dataset.metal),
		crystal: parseReferenceRatio(box?.dataset.crystal),
		deuterium: parseReferenceRatio(box?.dataset.deuterium)
	};
}

function inputReferenceRatios() {
	return {
		metal: parseReferenceRatio(qs('input[name=ratio-metal]')?.value),
		crystal: parseReferenceRatio(qs('input[name=ratio-cristal]')?.value),
		deuterium: parseReferenceRatio(qs('input[name=ratio-deuterium]')?.value)
	};
}

function scaleOfficialReferenceTo(resource) {
	var official = officialReferenceRatios();
	var baseline = official[resource];
	if (!isFinite(baseline) || baseline <= 0) {
		return null;
	}
	return {
		metal: official.metal / baseline,
		crystal: official.crystal / baseline,
		deuterium: official.deuterium / baseline
	};
}

function setReferenceRatioInputs(ratios) {
	const set = function (name, value) {
		const input = qs('input[name=' + name + ']');
		if (input) input.value = formatReferenceRatio(value);
	};
	set('ratio-metal', ratios.metal);
	set('ratio-cristal', ratios.crystal);
	set('ratio-deuterium', ratios.deuterium);
}

var workingReferenceRatios = null;

function setWorkingReferenceRatios(ratios) {
	workingReferenceRatios = ratios;
}

function normalizeReferenceTo(resource) {
	var ratios = scaleOfficialReferenceTo(resource);
	if (!ratios) {
		return;
	}
	setWorkingReferenceRatios(ratios);
	setReferenceRatioInputs(ratios);
	qsa('.ratioBaseline').forEach(function (el) { el.classList.remove('selected'); });
	qsa('.ratioBaseline[data-resource="' + resource + '"]').forEach(function (el) { el.classList.add('selected'); });
	calculateRatios();
}

function deuteriumRelativeRatios(refs) {
	if (!isFinite(refs.deuterium) || refs.deuterium <= 0) {
		return null;
	}
	return {
		metal: refs.metal / refs.deuterium,
		crystal: refs.crystal / refs.deuterium,
		deuterium: 1
	};
}

function convertAmountToDeut(amount, isMetal, isCrystal, isDeuterium, rates) {
	var value = 0;
	if (isMetal) {
		value += amount / rates.metal;
	}
	if (isCrystal) {
		value += amount / rates.crystal;
	}
	if (isDeuterium) {
		value += amount;
	}
	return Math.ceil(value);
}

function officialRowRatio(ratioCell) {
	var offerDeut = parseFloat(ratioCell.getAttribute('data-offer-deut'));
	var askDeut = parseFloat(ratioCell.getAttribute('data-ask-deut'));
	if (!isFinite(offerDeut) || !isFinite(askDeut) || askDeut <= 0) {
		return 0;
	}
	return offerDeut / askDeut;
}

function paintRatio(ratioCell, ratio) {
	ratioCell.textContent = pretty_number(ratio, 2);
	ratioCell.style.color = ratio < 1 ? '#F00' : '#0F0';
}

function paintFleetRatios() {
	qsa('table#tradeFleetList tbody tr').forEach(function(row) {
		var ratioCell = row.querySelector('.ratio');
		if (ratioCell) paintRatio(ratioCell, officialRowRatio(ratioCell));
	});
}

function calculateRatios(){
	/*
	 * Thanks to zb0oj for idea and a part of source code!
	 */
	paintFleetRatios();
	var useOfficial = qsa('.ratioBaseline.selected').length > 0;
	var rates = null;
	if (!useOfficial) {
		rates = deuteriumRelativeRatios(workingReferenceRatios || inputReferenceRatios());
		if (!rates || !isFinite(rates.metal) || !isFinite(rates.crystal)) {
			return;
		}
	}
	qsa('table#tradeList tbody tr').filter(function (row) {
		return !row.matches('.no-background.no-border.center');
	}).forEach(function(tradeOffer) {
			var ratioCell = tradeOffer.querySelector('.ratio');
			if (!ratioCell) return;
			var ratio;
			if (useOfficial) {
				ratio = officialRowRatio(ratioCell);
			} else {
				var has = function (selector) { return tradeOffer.querySelector(selector) !== null; };
				var offeredAmount = parseInt(tradeOffer.querySelector('.offered-resource-amount').innerHTML.replace(/\D/g, ''), 10) || 0;
				var wantedAmount = parseInt(tradeOffer.querySelector('.wanted-resource-amount').innerHTML.replace(/\D/g, ''), 10) || 0;
				var offerDeut = convertAmountToDeut(
					offeredAmount,
					has('.offered-resource-1'),
					has('.offered-resource-2'),
					has('.offered-resource-3'),
					rates
				);
				var costDeut = convertAmountToDeut(
					wantedAmount,
					has('.wanted-resource-1'),
					has('.wanted-resource-2'),
					has('.wanted-resource-3'),
					rates
				);
				ratio = costDeut > 0 ? offerDeut / costDeut : 0;
			}
			paintRatio(ratioCell, ratio);
		});
}



/**
 * Sort keys for the sortable tables: resource id and durations come from
 * data attributes, the shared table widget reads them from data-sort. Then the former default order is applied.
 */
function prepareSorting() {
	qsa('#tradeList, #resourceHistoryList, #tradeFleetList, #fleetHistoryList').forEach(function (table) {
		qsa('td[data-resource-id], td[data-seconds], td[data-time]', table).forEach(function (cell) {
			cell.dataset.sort = cell.dataset.resourceId ?? cell.dataset.seconds ?? cell.dataset.time;
		});
	});
	// [table id, column, descending]
	[['tradeList', 6, false], ['resourceHistoryList', 0, true], ['tradeFleetList', 6, false], ['fleetHistoryList', 0, true]].forEach(function ([id, column, descending]) {
		const table = document.getElementById(id);
		if (!table || !table.tBodies[0]?.rows.length) return;
		const th = table.tHead.rows[0].cells[column];
		th.click();
		if (descending) th.click();
	});
}

onPageInit(function() {
	setVisibleInterval(Refrash, 1000);
	Refrash();

	qsa('input[name=ratio-metal], input[name=ratio-cristal], input[name=ratio-deuterium]').forEach(function (input) {
		input.addEventListener('input', function(){
			qsa('.ratioBaseline').forEach(function (el) { el.classList.remove('selected'); });
			setWorkingReferenceRatios(inputReferenceRatios());
			calculateRatios();
		});
		input.addEventListener('change', function(){
			this.value = formatReferenceRatio(this.value);
			setWorkingReferenceRatios(inputReferenceRatios());
			calculateRatios();
		});
	});
	qsa('.ratioBaseline').forEach(function (el) {
		el.addEventListener('click', function(){
			normalizeReferenceTo(el.dataset.resource);
		});
	});
	var selectedBaseline = qs('.ratioBaseline.selected')?.dataset.resource;
	var selectedRatios = selectedBaseline ? scaleOfficialReferenceTo(selectedBaseline) : null;
	if (selectedRatios) {
		setWorkingReferenceRatios(selectedRatios);
	}
	calculateRatios();
	prepareSorting();
});
