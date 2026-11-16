import { qsa, on, getJSON, post, serialize, pretty_number, dezInt, pad, getFormatedDate, setVisibleInterval, NotifyBox, now, show, hide, pageData } from '../app/core.js';
import { Dialog } from '../app/dialog.js';
import { registerActions } from '../app/actions.js';

/**
 * Fleet dispatch, steps 1 and 2 (target and speed, then mission and cargo) and the ship selection of the
 * fleet table. The server's numbers arrive in the data island #fleet-data (templates page.fleetStep1/
 * fleetStep2): {step, data (fleet), honorboost, config, token, questionList, tradeMarket, targetMission}.
 * The controls are data-action / data-on-* markup (see app/actions.js). Changing ship amounts is announced
 * with the document event "fleet:amounts" (the fleet table shows the sums).
 */
const fleetPage = pageData('fleet-data');
const { data, honorboost, config, token, questionList = {}, tradeMarket } = fleetPage;

let acstime = 0;
let dataFlyDistance = 0;
let dataFlyTimeRaw = 0;
let dataFlyTime = 0;
let dataFlyConsumption = 0;
let dataFlyCargoSpace = 0;
let transportCapacity = 0;

/** Recomputes distance, duration, fuel and free cargo space after the target or speed changed and refreshes the form display. Resets the ACS group unless `$reset_acs` is false. Targets beyond the last planet slot (expedition position) force the planet type to 1. */
function updateVars($reset_acs = true)
{
	if ($reset_acs) {
		document.getElementsByName("group")[0].value = 0;
	}
	dataFlyDistance = GetDistance();
	dataFlyTimeRaw = GetRawDuration();
	dataFlyTime = GetDuration();
	dataFlyConsumption = GetConsumption();
	dataFlyCargoSpace = storage();
	if (config.max_planet < document.getElementsByName("planet")[0].value) {
		document.getElementsByName("type")[0].value = 1;
		document.getElementsByName("type")[0].disabled = true;
	} else {
		document.getElementsByName("type")[0].disabled = false;
	}
	refreshFormData();
}

/**
 * Calculates the distance to the target
 *
 * Changes needs to be done in src/Game/Core/FleetMath.php getTargetDistance() as well (and the preview in admin/pages/configUni.js)
 *
 * @returns int
 */
function GetDistance() {
	let thisGalaxy = Number(data.planet.galaxy);
	let thisSystem = Number(data.planet.system);
	let thisPlanet = Number(data.planet.planet);
	let targetGalaxy = Number(document.getElementsByName("galaxy")[0].value);
	let targetSystem = Number(document.getElementsByName("system")[0].value);
	let targetPlanet = Number(document.getElementsByName("planet")[0].value);

	if (thisGalaxy != targetGalaxy) {
		if (config.uni_type == 2) {
			return config.galaxy_distance_factor;
		}
		else if (config.uni_type == 1) {
			let max = Number(config.max_galaxy);
			let dist = 0;

			if (Math.abs(thisGalaxy - targetGalaxy) < Math.ceil(max / 2)) {
				dist = Math.abs(thisGalaxy - targetGalaxy);
			}
			else {
				dist = Math.floor(max / 2) - (Math.abs(thisGalaxy - targetGalaxy) - Math.ceil(max / 2));
			}
			return dist * config.galaxy_distance_factor;
		}
		else {
			return Math.abs(thisGalaxy - targetGalaxy) * config.galaxy_distance_factor;
		}
	} else if (thisSystem != targetSystem) {
		if (config.galaxy_type == 2) {
			return config.system_distance_factor + config.system_distance;
		}
        else if (config.galaxy_type == 1) {
			let max = Number(config.max_system);
			let dist = 0;

			if (Math.abs(thisSystem - targetSystem) < Math.ceil(max / 2)) {
				dist = Math.abs(thisSystem - targetSystem);
			}
			else {
				dist = Math.floor(max / 2) - (Math.abs(thisSystem - targetSystem) - Math.ceil(max / 2));
			}
			return dist * config.system_distance_factor + config.system_distance;
		} else {
			return Math.abs(thisSystem - targetSystem) * config.system_distance_factor + config.system_distance;
		}
	} else if (thisPlanet != targetPlanet) {
		return Math.abs(thisPlanet - targetPlanet) * config.planet_distance_factor + config.planet_distance;
	}
	return 5;
}

/**
 * Unrounded travel time, including fleetSpeedFactor.
 * Used for the deuterium preview so it matches FleetFunctions::getFleetConsumption().
 * Keep in sync with FleetMath::getMissionDuration().
 */
function GetRawDuration() {
	let sp = Number(document.getElementsByName("speed")[0].value);
	let duration = ((3500 / (sp * 0.1) * Math.pow(dataFlyDistance * 10 / data.maxspeed, 0.5) + 10) / data.gamespeed) * data.fleetspeedfactor;
	return Math.max(duration, data.fleetMinDuration);
}

/**
 * Whole seconds shown in the UI and stored at send.
 * Floor after the speed factor matches FleetDispatch: floor($duration) + request time.
 * Keep in sync with FleetMath::getMissionDuration() and admin/pages/configUni.js (durationFor()).
 */
function GetDuration() {
	return Math.floor(GetRawDuration());
}

/** Deuterium needed for the whole fleet (sum per ship type from base consumption, distance and flight speed, times the honor boost, +1), same formula as the server. */
function GetConsumption() {
	let dataFlyConsumption = 0;
	let dataFlyConsumption2 = 0;
	let basicConsumption = 0;
	let i;
	Object.values(data.ships).forEach(function(ship){
		const spd = 35000 / Math.max(dataFlyTimeRaw * data.gamespeed - 10, 1) * Math.sqrt(dataFlyDistance * 10 / ship.speed);
		basicConsumption = ship.consumption * ship.amount;
		dataFlyConsumption2 += basicConsumption * dataFlyDistance / 35000 * (spd / 10 + 1) * (spd / 10 + 1);
	});
	return Math.round((dataFlyConsumption + dataFlyConsumption2) * honorboost) + 1;
}

/** Free cargo space after the fuel is loaded (negative when the fleet cannot carry its own fuel). */
function storage() {
	return data.fleetroom - dataFlyConsumption;
}

/** Writes duration (h:mm:ss), distance, max speed, fuel and free cargo into the step 1 form; fuel and cargo are red when the cargo space is negative. */
function refreshFormData() {
	let seconds = dataFlyTime;
	let hours = Math.floor(seconds / 3600);
	seconds -= hours * 3600;
	let minutes = Math.floor(seconds / 60);
	seconds -= minutes * 60;
	document.getElementById("duration").textContent = hours + (":" + dezInt(minutes, 2) + ":" + dezInt(seconds,2) + " h");
	document.getElementById("distance").textContent = pretty_number(dataFlyDistance);
	document.getElementById("maxspeed").textContent = pretty_number(data.maxspeed);
	const color = dataFlyCargoSpace >= 0 ? "lime" : "red";
	document.getElementById("consumption").innerHTML = "<font color=\"" + color + "\">" + pretty_number(dataFlyConsumption) + "</font>";
	document.getElementById("storage").innerHTML = "<font color=\"" + color + "\">" + pretty_number(dataFlyCargoSpace) + "</font>";
}

/** Takes the target of an ACS group (fleet union) and remembers its id in the form. */
function setACSTarget(galaxy, solarsystem, planet, type, tacs) {
	setTarget(galaxy, solarsystem, planet, type);
	updateVars();
	document.getElementsByName("group")[0].value = tacs;
}

/** Writes target coordinates and planet type (1 planet, 2 debris field, 3 moon) into the form. */
function setTarget(galaxy, solarsystem, planet, type) {
	document.getElementsByName("galaxy")[0].value = galaxy;
	document.getElementsByName("system")[0].value = solarsystem;
	document.getElementsByName("planet")[0].value = planet;
	document.getElementsByName("type")[0].value = type;
}

/** Shows arrival and return time of the planned flight (now + duration, return = twice the duration without a stay). */
function FleetTime(){
	let starttime = dataFlyTime;
	let endtime	= starttime + dataFlyTime;
	document.getElementById("arrival").innerHTML = getFormatedDate(now().getTime()+1000*starttime, tdformat);
	document.getElementById("return").innerHTML = getFormatedDate(now().getTime()+1000*endtime, tdformat);
}

function setResource(id, val) {
	if (document.getElementsByName(id)[0]) {
		document.getElementsByName("resource" + id)[0].value = val;
	}
}

/** "Max" for one resource in step 2: fills the field with as much as the free cargo space and the planet's stock allow (deuterium minus the fuel). */
function maxResource(id) {
	let thisresource = getRessource(id);
	let thisresourcechosen = parseInt(document.getElementsByName(id)[0].value);
	if (isNaN(thisresourcechosen)) {
		thisresourcechosen = 0;
	}
	if (isNaN(thisresource)) {
		thisresource = 0;
	}

	let storCap = data.fleetroom - data.consumption;

	if (id == 'deuterium') {
		thisresource -= data.consumption;
	}
	let metalToTransport = parseInt(document.getElementsByName("metal")[0].value);
	let crystalToTransport = parseInt(document.getElementsByName("crystal")[0].value);
	let deuteriumToTransport = parseInt(document.getElementsByName("deuterium")[0].value);
	if (isNaN(metalToTransport)) {
		metalToTransport = 0;
	}
	if (isNaN(crystalToTransport)) {
		crystalToTransport = 0;
	}
	if (isNaN(deuteriumToTransport)) {
		deuteriumToTransport = 0;
	}
	let freeCapacity = Math.max(storCap - metalToTransport - crystalToTransport - deuteriumToTransport, 0);
	document.getElementsByName(id)[0].value = Math.min(freeCapacity + thisresourcechosen, thisresource);
	calculateTransportCapacity();
}


function maxResources() {
	maxResource('metal');
	maxResource('crystal');
	maxResource('deuterium');
}

/** Applies the resources preselected by the previous page (`#selectedres` data: metal, crystal, deuterium, reserve), capped by the planet's stock. */
function selectedResources() {
  let amt=document.getElementById("selectedres").getAttribute("data").split(",")
  if(!isNaN(parseInt(amt[0]))){
    document.getElementsByName("metal")[0].value = Math.min(parseInt(amt[0]), parseInt(document.getElementById('current_metal').getAttribute('data-real')))
  }else{
    document.getElementsByName("metal")[0].value = 0
  }
  if(!isNaN(parseInt(amt[1]))){
    document.getElementsByName("crystal")[0].value = Math.min(parseInt(amt[1]), parseInt(document.getElementById('current_crystal').getAttribute('data-real')))
  }else{
    document.getElementsByName("crystal")[0].value = 0
  }
  if(!isNaN(parseInt(amt[2]))){
    document.getElementsByName("deuterium")[0].value = Math.min(parseInt(amt[2]),
      parseInt(document.getElementById('current_deuterium').getAttribute('data-real')),
      parseInt(document.getElementById('current_deuterium').getAttribute('data-real')) - parseInt(amt[3]))
  }else{
    document.getElementsByName("deuterium")[0].value = 0
  }
  calculateTransportCapacity();
  applySelectedResourcesToTrade();
}

/** Shows the cargo space left after fuel and the typed resources (red when overloaded), updates the trade ratio and returns the value. */
function calculateTransportCapacity() {
	let metal = Math.abs(document.getElementsByName("metal")[0].value);
	let crystal = Math.abs(document.getElementsByName("crystal")[0].value);
	let deuterium = Math.abs(document.getElementsByName("deuterium")[0].value);
	transportCapacity = data.fleetroom - data.consumption - metal - crystal - deuterium;
	if (transportCapacity < 0) {
		document.getElementById("remainingresources").innerHTML = "<font color=red>" + pretty_number(transportCapacity) + "</font>";
	} else {
		document.getElementById("remainingresources").innerHTML = "<font color=lime>" + pretty_number(transportCapacity) + "</font>";
	}
	updateTradeRatio();
	return transportCapacity;
}

/** Positive whole number from an input value, else 0. */
function parseTradeAmount(value) {
	let amount = parseInt(value, 10);
	return isFinite(amount) && amount > 0 ? amount : 0;
}

function tradeResourceKey(resourceId) {
	return ({1: 'metal', 2: 'crystal', 3: 'deuterium'})[resourceId];
}

function tradeReferenceDecimals() {
	return typeof tradeMarket !== 'undefined' && tradeMarket && tradeMarket.decimals
		? tradeMarket.decimals
		: 2;
}

/** Value of an amount in deuterium with the market `rates` (metal/crystal rate = amount per deuterium), rounded up; 0 when invalid. */
function convertTradeAmountToDeut(amount, resourceId, rates) {
	let key = tradeResourceKey(resourceId);
	if (!key || amount <= 0 || !rates) {
		return 0;
	}
	if (key === 'deuterium') {
		return Math.ceil(amount);
	}
	let rate = parseFloat(rates[key]);
	if (!isFinite(rate) || rate <= 0) {
		return 0;
	}
	return Math.ceil(amount / rate);
}

function setTradeRatioDisplay(ratio) {
	let ratioEl = document.getElementById('tradeRelativeRatio');
	if (!ratioEl) {
		return;
	}
	if (!isFinite(ratio) || ratio <= 0) {
		ratioEl.textContent = '-';
		ratioEl.className = '';
		return;
	}
	ratioEl.textContent = pretty_number(ratio, tradeReferenceDecimals());
	ratioEl.className = ratio < 1 ? 'colorNegative' : 'colorPositive';
}

/** The resources with an amount above 0 in the cargo fields, as [{id: 1 metal | 2 crystal | 3 deuterium, amount}]. */
function getLoadedTradeResources() {
	let metalEl = document.getElementsByName('metal')[0];
	let crystalEl = document.getElementsByName('crystal')[0];
	let deuteriumEl = document.getElementsByName('deuterium')[0];
	return [
		{id: 1, amount: parseTradeAmount(metalEl && metalEl.value)},
		{id: 2, amount: parseTradeAmount(crystalEl && crystalEl.value)},
		{id: 3, amount: parseTradeAmount(deuteriumEl && deuteriumEl.value)}
	].filter(function (res) {
		return res.amount > 0;
	});
}

function getTradeElement(name) {
	return document.getElementsByName(name)[0] || null;
}

function getTradeResourceId(name) {
	let el = getTradeElement(name);
	return el ? parseInt(el.value, 10) : 0;
}

function isFleetMarket() {
	return getTradeResourceId('markettype') === 1;
}

function otherTradeResource(resourceId) {
	return resourceId === 1 ? 2 : 1;
}

function setTradeOptionsDisabled(selectEl, disabledId) {
	if (!selectEl) {
		return;
	}
	let i;
	for (i = 0; i < selectEl.options.length; i++) {
		selectEl.options[i].disabled = parseInt(selectEl.options[i].value, 10) === disabledId;
	}
}

function setTradeCargo(metal, crystal, deuterium) {
	let metalEl = getTradeElement('metal');
	let crystalEl = getTradeElement('crystal');
	let deuteriumEl = getTradeElement('deuterium');
	if (metalEl) {
		metalEl.value = metal;
	}
	if (crystalEl) {
		crystalEl.value = crystal;
	}
	if (deuteriumEl) {
		deuteriumEl.value = deuterium;
	}
}

/** Trade mission: offered and requested resource must differ (the requested one is switched and the offered one disabled in the other list); fleet market offers may not be "nothing". */
function ensureTradePairExclusive() {
	let offerEl = getTradeElement('resOffer');
	let askEl = getTradeElement('resEx');
	if (!offerEl || !askEl) {
		return;
	}
	setTradeOptionsDisabled(offerEl, 0);
	if (isFleetMarket()) {
		setTradeOptionsDisabled(askEl, 0);
		return;
	}
	let offerId = parseInt(offerEl.value, 10);
	if (offerId === parseInt(askEl.value, 10)) {
		askEl.value = String(otherTradeResource(offerId));
	}
	setTradeOptionsDisabled(askEl, offerId);
}

/** Trade mission: puts the offered amount into the cargo field of the offered resource and zeroes the others (no cargo for the fleet market type). */
function syncTradeOfferToCargo() {
	if (isFleetMarket()) {
		setTradeCargo(0, 0, 0);
		return;
	}
	let amountEl = document.getElementById('tradeOfferAmount');
	if (!amountEl) {
		return;
	}
	let offerId = getTradeResourceId('resOffer');
	let amount = parseTradeAmount(amountEl.value);
	setTradeCargo(offerId === 1 ? amount : 0, offerId === 2 ? amount : 0, offerId === 3 ? amount : 0);
}

/** Switches the form between resource market and fleet market offer (hides the offer rows for fleets) and syncs cargo and capacity. */
function applyTradeMarketType() {
	let isFleet = isFleetMarket();
	let offerRow = document.getElementById('tradeOfferRow');
	let selectedResRow = document.getElementById('selectedResRow');
	if (offerRow) {
		offerRow.style.display = isFleet ? 'none' : '';
	}
	if (selectedResRow && getTradeElement('resOffer')) {
		selectedResRow.style.display = isFleet ? 'none' : '';
	}
	if (isFleet) {
		let amountEl = document.getElementById('tradeOfferAmount');
		if (amountEl) {
			amountEl.value = '';
		}
	}
	ensureTradePairExclusive();
	syncTradeOfferToCargo();
	calculateTransportCapacity();
}

/** "Max" for the offered amount: the largest amount of the offered resource that fits into the cargo space. */
function maxTradeOffer() {
	if (isFleetMarket()) {
		return;
	}
	let key = tradeResourceKey(getTradeResourceId('resOffer'));
	if (!key || !getTradeElement(key)) {
		return;
	}
	['metal', 'crystal', 'deuterium'].forEach(function (name) {
		if (name !== key && getTradeElement(name)) {
			getTradeElement(name).value = 0;
		}
	});
	maxResource(key);
	let amountEl = document.getElementById('tradeOfferAmount');
	if (amountEl) {
		amountEl.value = getTradeElement(key).value;
	}
}

function onTradeOfferChanged() {
	ensureTradePairExclusive();
	syncTradeOfferToCargo();
	calculateTransportCapacity();
}

function onTradeAskChanged() {
	ensureTradePairExclusive();
	updateTradeRatio();
}

/** Takes a preselected single resource as the trade offer (first loaded resource), otherwise clears the offer. */
function applySelectedResourcesToTrade() {
	if (!getTradeElement('resOffer')) {
		return;
	}
	if (isFleetMarket()) {
		syncTradeOfferToCargo();
		calculateTransportCapacity();
		return;
	}
	let amountEl = document.getElementById('tradeOfferAmount');
	let loaded = getLoadedTradeResources();
	if (!amountEl || loaded.length === 0) {
		if (amountEl) {
			amountEl.value = '';
		}
		syncTradeOfferToCargo();
		calculateTransportCapacity();
		return;
	}
	getTradeElement('resOffer').value = String(loaded[0].id);
	amountEl.value = loaded[0].amount;
	ensureTradePairExclusive();
	syncTradeOfferToCargo();
	calculateTransportCapacity();
}

/** Shows the offered/requested value ratio (both converted to deuterium with the market rates), red below 1; for fleet offers the fleet's deuterium value is used. */
function updateTradeRatio() {
	if (typeof tradeMarket === 'undefined' || !tradeMarket) {
		return;
	}
	let markettypeEl = document.getElementsByName('markettype')[0];
	let resExEl = document.getElementsByName('resEx')[0];
	let exchangeEl = document.getElementsByName('exchange')[0];
	if (!markettypeEl || !resExEl || !exchangeEl) {
		return;
	}

	let markettype = parseInt(markettypeEl.value, 10) || 0;
	let askId = parseInt(resExEl.value, 10);
	let askAmount = parseTradeAmount(exchangeEl.value);
	let rates = tradeMarket.rates;

	if (markettype === 1) {
		let fleetAskDeut = convertTradeAmountToDeut(askAmount, askId, rates);
		setTradeRatioDisplay(fleetAskDeut > 0 ? tradeMarket.fleetDeut / fleetAskDeut : 0);
		return;
	}

	let loaded = getLoadedTradeResources();
	if (loaded.length !== 1 || loaded[0].id === askId) {
		setTradeRatioDisplay(0);
		return;
	}

	let offer = loaded[0];
	let offerDeut = convertTradeAmountToDeut(offer.amount, offer.id, rates);
	let askDeut = convertTradeAmountToDeut(askAmount, askId, rates);
	setTradeRatioDisplay(askDeut > 0 ? offerDeut / askDeut : 0);
}

/** Hooks the trade fields (market type, offered and requested resource, amounts) to their update handlers and applies the current state. */
function initTradeMarket() {
	if (typeof tradeMarket === 'undefined' || !tradeMarket) {
		return;
	}
	const handler = function (event) {
		const field = event.currentTarget;
		if (field.name === 'markettype') {
			applyTradeMarketType();
			return;
		}
		if (field.name === 'resOffer' || field.id === 'tradeOfferAmount') {
			onTradeOfferChanged();
			return;
		}
		if (field.name === 'resEx') {
			onTradeAskChanged();
			return;
		}
		updateTradeRatio();
	};
	qsa('#tradeOfferAmount, input[name=exchange], select[name=resOffer], select[name=resEx], select[name=markettype]').forEach(function (field) {
		field.addEventListener('input', handler);
		field.addEventListener('change', handler);
	});
	applyTradeMarketType();
}

/** tells the page that the ship amounts changed (fleetTable.js recomputes its sums) */
function amountsChanged() {
	document.dispatchEvent(new Event('fleet:amounts'));
}

function maxShip(id) {
	let input = document.getElementsByName(id)[0];
	let cell = document.getElementById(id + "_value");
	if (input && cell) {
		let raw = cell.getAttribute("data-count");
		input.value = (raw !== null && raw !== "") ? raw : cell.textContent.replace(/\D/g, "");
	}
  amountsChanged();
}

/** Fills every ship field with the maximum available. */
export function maxShips() {
	let id;
	qsa('input[name^="ship"]').forEach(function(input) {
		maxShip(input.name);
	});
  amountsChanged();
}


function noShip(id) {
	if (document.getElementsByName(id)[0]) {
		document.getElementsByName(id)[0].value = 0;
	}
  amountsChanged();
}


/** Sets every ship field to 0. */
export function noShips() {
	let id;
	qsa('input[name^="ship"]').forEach(function(input) {
		noShip(input.name);
	});
  amountsChanged();
}

function setNumber(name, number) {
	if (typeof document.getElementsByName("ship" + name)[0] != "undefined") {
		document.getElementsByName("ship" + name)[0].value = number;
	}
}

/**
 * Cargo preset links ("send resources to ..."): chooses small and/or large cargos so that `total` units are carried
 * (the preferred type first, capped by what the planet has, the rest covered by the other type), fills the resource
 * storage fields and, if given, the target coordinates.
 * @param {number} type 202 (small cargo) or 203 (large cargo): the preferred ship
 * @param {number} total resources to carry; neededNNN = ships of that type needed alone; shipNNNCapacity = cargo per ship
 */
function setCargoPreset(type, galaxy, system, planet, planet_type, metal, crystal, deuterium, total, ship202, ship203, needed202, needed203, ship202Capacity, ship203Capacity) {
	let ship202Needed = 0;
	let ship203Needed = 0;

	switch (type) {
		case 202: // Small Cargo
			ship202Needed = needed202 > ship202 ? ship202 : needed202;

			if (needed202 > ship202) {
				needed203 = Math.ceil((total - (ship202 * ship202Capacity)) / ship203Capacity);
				ship203Needed = needed203 > ship203 ? ship203 : needed203;
			}
			break;

		case 203: // Large Cargo
			ship203Needed = needed203 > ship203 ? ship203 : needed203;

			if (needed203 > ship203) {
				needed202 = Math.ceil((total - (ship203 * ship203Capacity)) / ship202Capacity);
				ship202Needed = needed202 > ship202 ? ship202 : needed202;
			}
			break;
	}

	setNumber(202, ship202Needed);
	setNumber(203, ship203Needed);

	document.getElementsByName("met_storage")[0].value = metal;
	document.getElementsByName("krist_storage")[0].value = crystal;
	document.getElementsByName("deut_storage")[0].value = deuterium;

	if(galaxy !== null && system !== null && planet !== null && planet_type !== null) {
		setTarget(galaxy, system, planet, planet_type);
	}
}

/** Fleet steps reserve the fleet on the server; leaving the page without sending releases it again through a beacon (`fleetStepAjax`). These three functions manage that listener. */
function removeBeforeUnload() {
	window.removeEventListener("beforeunload", handleBeforeUnload);
}
function handleBeforeUnload(event) {
	navigator.sendBeacon("game?page=fleetStepAjax&ajax=1&token=" + token);
}
function addBeforeUnload() {
	window.addEventListener("beforeunload", handleBeforeUnload);
}

/** Step 1 submit: validates the target on the server (`checkTarget`); "OK" submits the form, otherwise the message is shown. Returns false (the real submit happens afterwards). */
function CheckTarget()
{
	removeBeforeUnload();
	const kolo	= (typeof data.ships[208] == "object") ? 1 : 0;

	getJSON('game?page=fleetStep1&mode=checkTarget&galaxy='+document.getElementsByName("galaxy")[0].value+'&system='+document.getElementsByName("system")[0].value+'&planet='+document.getElementsByName("planet")[0].value+'&planet_type='+document.getElementsByName("type")[0].value+'&lang='+Lang+'&kolo='+kolo).then(function(data) {
		if (data == "OK") {
			submitForm(true);
		} else {
			addBeforeUnload();
			NotifyBox(data);
		}
	});
	return false;
}

/** Step 2 submit: validates cargo, mission and trade fields on the server (`checkResources`); "OK" submits, an error object ends the dispatch with a dialog (back to the fleet table), other text is shown as notice. Returns false. */
function CheckResources()
{
	removeBeforeUnload();
	let e = document.getElementsByName("resEx")[0];
	const resEx = e != null ? e.value : 0;
	e = document.getElementsByName("exchange")[0];
	const exchange = e != null ? e.value : 0;
	e = document.getElementsByName("markettype");
	const markettype = (e != null && typeof e[0] !== 'undefined') ? e[0].value : 0;
	getJSON('game?page=fleetStep2&mode=checkResources&metal='+document.getElementsByName("metal")[0].value+'&crystal='+document.getElementsByName("crystal")[0].value+'&deuterium='+document.getElementsByName("deuterium")[0].value+'&mission='+document.querySelector('input[name="mission"]:checked').value+'&markettype='+markettype+'&resEx='+resEx+'&exchange='+exchange+'&token='+document.getElementsByName("token")[0].value).then(function(data) {
		if (data == "OK") {
			submitForm();
		} else if (typeof data.error !== 'undefined' && data.error) {
			addBeforeUnload();
			Dialog.alert(data.message, function () {
				document.location.href = 'game?page=fleetTable';
			});
		} else {
			addBeforeUnload();
			showSubmit();
			NotifyBox(data);
		}
	});
	return false;
}

/** Switches the shortcut list into edit mode (adds an empty entry when there is none yet). */
function EditShortcuts(autoadd) {
	qsa(".shortcut-link").forEach(hide);
	// the stylesheet hides .shortcut-edit, so the display value has to be set explicitly
	qsa(".shortcut-edit:not(.shortcut-new)").forEach(function (el) { show(el, el.tagName === 'TR' ? 'table-row' : 'block'); });
	if(document.querySelectorAll('.shortcut-isset').length === 0)
		AddShortcuts();
}

/** Appends an empty shortcut entry cloned from the template row, numbering its field names `shortcut[<n>-new]`. */
function AddShortcuts() {
	let HTML	= Array.from(document.querySelector('.shortcut-new td').cloneNode(true).children);
	const count = document.querySelectorAll('.shortcut-link').length;
	HTML.forEach(function (child) {
		child.querySelectorAll('input, select').forEach(function (field) {
			field.name = field.name.replace("shortcut[]", "shortcut[" + count + "-new]");
		});
	});

  let newShortcut = document.createElement('div');
  newShortcut.className = 'shortcut-colum shortcut-isset';
  newShortcut.append(...HTML);
  document.querySelector('.shortcut-container').append(newShortcut);
  document.querySelectorAll('.shortcut-none').forEach(function (el) { el.remove(); });
}

/** Saves the shortcut fields (ajax POST `saveShortcuts`) and reloads the page. */
function SaveShortcuts(reedit) {
  post('game?page=fleetStep1&mode=saveShortcuts&ajax=1', serialize(qsa('.shortcut-row input, .shortcut-row select'))).then(function(res) {
		qsa(".shortcut-link").forEach(function (el) { show(el); });
		qsa(".shortcut-edit").forEach(hide);

    window.location.reload();
	});
}

/** Shows the stay-time selector only for missions with a hold time (5, 15, 16, 17 and -1). */
function checkHold(mission) {
	let stayHead = document.getElementById("stay_head");
	let stay = document.getElementById("stay");
	if ([5,15,16,17,-1].includes(mission)) {
		if (stayHead) {
			stayHead.style.display = 'initial';
		}
		if (stay) {
			stay.style.display = 'initial';
		}
	} else {
		if (stayHead) {
			stayHead.style.display = 'none';
		}
		if (stay) {
			stay.style.display = 'none';
		}
	}
}

/**
 * Send button of step 2: shows the wait row; for missions with a warning text (`questionList`) the click is cancelled
 * (returns false), the question asked in the modal and, when accepted, the form submitted (requestSubmit, with the
 * button as submitter); declined restores the button row.
 */
function sendFleet(button) {
	let mission = document.querySelector('input[name="mission"]:checked').value;
	let message = questionList[parseInt(mission)] || null;
	showWait();
	if (message) {
		Dialog.confirm(message).then((ok) => {
			if (ok) button.form.requestSubmit(button);
			else showSubmit();
		});
		return false;
	}
	return true;
}

/** swap the submit row for the "please wait" row and back */
function showWait() {
	hide(document.getElementById('submit'));
	show(document.getElementById('wait'));
}

function showSubmit() {
	hide(document.getElementById('wait'));
	show(document.getElementById('submit'));
}

/** native submit of #form; step 1 re-enables disabled fields first so they are posted */
function submitForm(enableDisabled = false) {
	const form = document.getElementById('form');
	if (enableDisabled) {
		form.querySelectorAll(':disabled').forEach(function (field) { field.disabled = false; });
	}
	form.submit();
}

on(document, 'click', '.shortcut-delete', function (event, button) {
	button.closest('.shortcut-colum')?.remove();
});

/** Live arrival time of the flight in the player's timezone (page element #arr_time, `duration` attribute in seconds), with a day prefix "nT" for more than a day. */
function update_arrival() {
	const current = new Date(Date.now())
	const deT = new Date(current.toLocaleString('en-us', { timeZone: serverTimezoneString})).getTime()
	const localT = new Date(current.toLocaleString('en-us', { timeZone: localtimezonestring})).getTime()
	const timeOffset = localT - deT
  const nd = new Date(new Date(serverTime).getTime() + timeOffset + parseInt(document.getElementById("arr_time").getAttribute("duration")) * 1000)
  let ddiv=Math.floor((nd-serverTime) / (1000 * 60 * 60 * 24));
  let tamt=""
  if(ddiv>0){
    tamt=ddiv + "T "
  }
  document.getElementById("arr_time").innerText = tamt +  nd.getHours() + ":" + pad(nd.getMinutes(), 2) + ":" + pad(nd.getSeconds(), 2)
}
onPageInit(function() {
if(document.getElementById("arr_time")){
  update_arrival();
  setVisibleInterval(update_arrival,1000)
}

});


const coords = (link) => [link.dataset.galaxy, link.dataset.system, link.dataset.planet, link.dataset.type];

registerActions({
	'set-target': (link) => { setTarget(...coords(link)); updateVars(); },
	'set-acs-target': (link) => setACSTarget(...coords(link), link.dataset.acs),
	'update-vars': () => updateVars(),
	'update-vars-speed': () => updateVars(false),
	'check-target': () => CheckTarget(),
	'edit-shortcuts': () => EditShortcuts(),
	'save-shortcuts': () => SaveShortcuts(),
	'add-shortcut': () => AddShortcuts(),
	'check-resources': () => CheckResources(),
	'check-hold': (radio) => checkHold(Number(radio.dataset.mission)),
	'calc-capacity': () => { calculateTransportCapacity(); },
	'max-trade-offer': () => maxTradeOffer(),
	'max-resource': (link) => maxResource(link.dataset.resource),
	'max-resources': () => maxResources(),
	'selected-resources': () => selectedResources(),
	'send-fleet': (button) => sendFleet(button),
	'max-ship': (link) => maxShip(link.dataset.ship),
	'no-ship': (link) => noShip(link.dataset.ship),
	'max-ships': () => maxShips(),
	'no-ships': () => noShips(),
	'cargo-preset': (span) => {
		const d = span.dataset;
		const n = (key) => Number(d[key]);
		setCargoPreset(n('type'), n('galaxy'), n('system'), n('planet'), n('planetType'), n('metal'), n('crystal'), n('deuterium'), n('total'),
			n('ship202'), n('ship203'), n('needed202'), n('needed203'), n('capacity202'), n('capacity203'));
	},
});

onPageInit(() => {
	if (fleetPage.step === 1) {
		addBeforeUnload();
		updateVars();
		FleetTime();
		setVisibleInterval(FleetTime, 1000);
	} else if (fleetPage.step === 2) {
		addBeforeUnload();
		calculateTransportCapacity();
		checkHold(Number(fleetPage.targetMission));
		initTradeMarket();
	}
});
