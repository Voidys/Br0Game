import { qs, qsa, on, now, pretty_number, getRestTimeFormat, setVisibleInterval, pageData } from '../app/core.js';
import { Dialog } from '../app/dialog.js';

/**
 * Marketplace: reference ratios and the live ratio colouring, sortable offer tables, the resource /
 * fleet market switch, the flight column selector and the confirmed "buy" buttons. The confirmation
 * text comes from the data island #market-data ({confirmText}).
 */

/** Counts the remaining time of every offer row (`data-time` = seconds at render time). */
function Refrash() {
	qsa("[data-time]").forEach(function (el) {
		let remaining = Number(el.dataset.time) - (now().getTime() - window.startTime) / 1000;
		el.textContent = getRestTimeFormat(remaining);
	});
}


/**
 * Number from a reference ratio input: tolerates spaces and, depending on the player's decimal separator, both comma and dot
 * notation. NaN when empty, not positive or not a number.
 */
function parseReferenceRatio(value) {
	if (typeof value === 'number') {
		return isFinite(value) && value > 0 ? value : NaN;
	}
	let text = String(value == null ? '' : value).replace(/\s/g, '');
	if (text === '') {
		return NaN;
	}
	// decimalSeparator 0 is comma (same as pretty_number). A lone '.' stays a
	// decimal so machine-formatted data attributes still parse.
	let european = (typeof decimalSeparator !== 'undefined' && String(decimalSeparator) === '0');
	if (european) {
		if (text.indexOf(',') !== -1) {
			text = text.replace(/\./g, '').replace(',', '.');
		}
	} else if (text.indexOf(',') !== -1) {
		text = text.replace(/,/g, '');
	}
	let ratio = parseFloat(text);
	return isFinite(ratio) && ratio > 0 ? ratio : NaN;
}

function formatReferenceRatio(value) {
	let ratio = parseReferenceRatio(value);
	return isFinite(ratio) ? pretty_number(ratio, 2) : value;
}

function officialReferenceRatios() {
	let box = qs('#ratioBaselines');
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

/** Official reference ratios divided by the chosen baseline resource (so the baseline becomes 1); null when the baseline ratio is invalid. */
function scaleOfficialReferenceTo(resource) {
	let official = officialReferenceRatios();
	let baseline = official[resource];
	if (!isFinite(baseline) || baseline <= 0) {
		return null;
	}
	return {
		metal: official.metal / baseline,
		crystal: official.crystal / baseline,
		deuterium: official.deuterium / baseline
	};
}

/** Writes the three ratios into their inputs (formatted). */
function setReferenceRatioInputs(ratios) {
	const set = function (name, value) {
		const input = qs('input[name=' + name + ']');
		if (input) input.value = formatReferenceRatio(value);
	};
	set('ratio-metal', ratios.metal);
	set('ratio-cristal', ratios.crystal);
	set('ratio-deuterium', ratios.deuterium);
}

let workingReferenceRatios = null;

function setWorkingReferenceRatios(ratios) {
	workingReferenceRatios = ratios;
}

/** Baseline button: sets the working ratios to the official ones expressed relative to `resource`, updates inputs and highlighting, repaints the offers. */
function normalizeReferenceTo(resource) {
	let ratios = scaleOfficialReferenceTo(resource);
	if (!ratios) {
		return;
	}
	setWorkingReferenceRatios(ratios);
	setReferenceRatioInputs(ratios);
	qsa('.ratioBaseline').forEach(function (el) { el.classList.remove('selected'); });
	qsa('.ratioBaseline[data-resource="' + resource + '"]').forEach(function (el) { el.classList.add('selected'); });
	calculateRatios();
}

/** Ratios relative to deuterium (deuterium = 1); null when the deuterium ratio is invalid. */
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

/** Value of an amount of the flagged resource kinds in deuterium, rounded up: metal and crystal are divided by their rate. */
function convertAmountToDeut(amount, isMetal, isCrystal, isDeuterium, rates) {
	let value = 0;
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

/** Official ratio of an offer row: offered deuterium value divided by the asked one (data attributes); 0 when not computable. */
function officialRowRatio(ratioCell) {
	let offerDeut = parseFloat(ratioCell.getAttribute('data-offer-deut'));
	let askDeut = parseFloat(ratioCell.getAttribute('data-ask-deut'));
	if (!isFinite(offerDeut) || !isFinite(askDeut) || askDeut <= 0) {
		return 0;
	}
	return offerDeut / askDeut;
}

/** Shows the ratio with two decimals, red below 1 (a bad deal for the buyer), green otherwise. */
function paintRatio(ratioCell, ratio) {
	ratioCell.textContent = pretty_number(ratio, 2);
	ratioCell.style.color = ratio < 1 ? '#F00' : '#0F0';
}

/** Colours the ratio column of the fleet market table. */
function paintFleetRatios() {
	qsa('table#tradeFleetList tbody tr').forEach(function(row) {
		let ratioCell = row.querySelector('.ratio');
		if (ratioCell) paintRatio(ratioCell, officialRowRatio(ratioCell));
	});
}

/**
 * Recalculates the "ratio" column of the resource offers: with an official baseline selected from the rows' data attributes,
 * otherwise by converting offered and wanted amounts to deuterium with the player's own reference ratios. The fleet table is
 * repainted too. Does nothing when the reference ratios are invalid.
 */
function calculateRatios(){
	// Thanks to zb0oj for idea and a part of source code!
	paintFleetRatios();
	let useOfficial = qsa('.ratioBaseline.selected').length > 0;
	let rates = null;
	if (!useOfficial) {
		rates = deuteriumRelativeRatios(workingReferenceRatios || inputReferenceRatios());
		if (!rates || !isFinite(rates.metal) || !isFinite(rates.crystal)) {
			return;
		}
	}
	qsa('table#tradeList tbody tr').filter(function (row) {
		return !row.matches('.no-background.no-border.center');
	}).forEach(function(tradeOffer) {
			let ratioCell = tradeOffer.querySelector('.ratio');
			if (!ratioCell) return;
			let ratio;
			if (useOfficial) {
				ratio = officialRowRatio(ratioCell);
			} else {
				let has = function (selector) { return tradeOffer.querySelector(selector) !== null; };
				let offeredAmount = parseInt(tradeOffer.querySelector('.offered-resource-amount').innerHTML.replace(/\D/g, ''), 10) || 0;
				let wantedAmount = parseInt(tradeOffer.querySelector('.wanted-resource-amount').innerHTML.replace(/\D/g, ''), 10) || 0;
				let offerDeut = convertAmountToDeut(
					offeredAmount,
					has('.offered-resource-1'),
					has('.offered-resource-2'),
					has('.offered-resource-3'),
					rates
				);
				let costDeut = convertAmountToDeut(
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
	let selectedBaseline = qs('.ratioBaseline.selected')?.dataset.resource;
	let selectedRatios = selectedBaseline ? scaleOfficialReferenceTo(selectedBaseline) : null;
	if (selectedRatios) {
		setWorkingReferenceRatios(selectedRatios);
	}
	calculateRatios();
	prepareSorting();
	initMarketBoxes();
});

/** shows the resource or the fleet market box, whichever switch is selected (the only one if just one exists) */
function reloadMarketBox() {
	const resBtn = document.getElementById('resourceMBtn');
	const fleetBtn = document.getElementById('fleetMBtn');
	const clRes = resBtn ? resBtn.className : null;
	const clFleet = fleetBtn ? fleetBtn.className : null;

	document.getElementById('resourceMarketBox').hidden = !(clRes != null && (clRes.indexOf('selected') != -1 || clFleet == null));
	document.getElementById('fleetMarketBox').hidden = !(clFleet != null && (clFleet.indexOf('selected') != -1 || clRes == null));
}

/** fleet market: the light / heavy cargo selector decides which flight column is shown */
function toggleFlightColumns() {
	const valueSelected = document.getElementById('shipT').value;
	const showLC = valueSelected == 1;
	const showHC = valueSelected == 2;
	if (showLC || showHC) {
		qsa('.HC').forEach((el) => { el.hidden = showLC; });
		qsa('.LC').forEach((el) => { el.hidden = !showLC; });
	}
}

/** Initial state of the resource/fleet box switch and the flight column selector. */
function initMarketBoxes() {
	reloadMarketBox();
	if (document.getElementById('shipT')) toggleFlightColumns();
}

on(document, 'click', '#resourceMBtn, #fleetMBtn', (event, button) => {
	qsa('.marketOption').forEach((el) => el.classList.remove('selected'));
	button.classList.add('selected');
	reloadMarketBox();
});

on(document, 'change', '#shipT', toggleFlightColumns);

/** buy button: asks, then submits its form with the chosen ship type (#shipT) as hidden field */
on(document, 'click', '.market_buy_button', async (event, button) => {
	if (!(await Dialog.confirm(pageData('market-data').confirmText))) return;
	const form = button.closest('form');
	form.querySelectorAll('input[name="shipType"]').forEach((el) => el.remove());
	const hidden = document.createElement('input');
	hidden.type = 'hidden';
	hidden.name = 'shipType';
	hidden.value = document.getElementById('shipT').value;
	form.append(hidden);
	form.submit();
});
