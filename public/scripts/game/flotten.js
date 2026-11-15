import { qsa, on, getJSON, post, serialize, pretty_number, dezInt, pad, getFormatedDate, setVisibleInterval, NotifyBox, now, show, hide, fadeOut } from '../app/core.js';
import { Dialog } from '../app/dialog.js';

let acstime = 0;
let dataFlyDistance = 0;
let dataFlyTimeRaw = 0;
let dataFlyTime = 0;
let dataFlyConsumption = 0;
let dataFlyCargoSpace = 0;
let transportCapacity = 0;

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
 * Changes needs to be done in includes/classes/class.FleetFuntions.php#getTargetDistance() aswell
 *
 * @returns int
 */
function GetDistance() {
	var thisGalaxy = Number(data.planet.galaxy);
	var thisSystem = Number(data.planet.system);
	var thisPlanet = Number(data.planet.planet);
	var targetGalaxy = Number(document.getElementsByName("galaxy")[0].value);
	var targetSystem = Number(document.getElementsByName("system")[0].value);
	var targetPlanet = Number(document.getElementsByName("planet")[0].value);

	if (thisGalaxy != targetGalaxy) {
		if (config.uni_type == 2) {
			return config.galaxy_distance_factor;
		}
		else if (config.uni_type == 1) {
			var max = Number(config.max_galaxy);
			var dist = 0;

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
			var max = Number(config.max_system);
			var dist = 0;

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
 * Keep in sync with FleetFunctions::getMissionDuration().
 */
function GetRawDuration() {
	var sp = Number(document.getElementsByName("speed")[0].value);
	var duration = ((3500 / (sp * 0.1) * Math.pow(dataFlyDistance * 10 / data.maxspeed, 0.5) + 10) / data.gamespeed) * data.fleetspeedfactor;
	return Math.max(duration, data.fleetMinDuration);
}

/**
 * Whole seconds shown in the UI and stored at send.
 * Floor after the speed factor matches ShowFleetStep3Page: floor($duration) + TIMESTAMP.
 * Keep in sync with FleetFunctions::getMissionDuration() and ConfigUni.tpl#getDuration().
 */
function GetDuration() {
	return Math.floor(GetRawDuration());
}

function GetConsumption() {
	var dataFlyConsumption = 0;
	var dataFlyConsumption2 = 0;
	var basicConsumption = 0;
	var i;
	Object.values(data.ships).forEach(function(ship){
		const spd = 35000 / Math.max(dataFlyTimeRaw * data.gamespeed - 10, 1) * Math.sqrt(dataFlyDistance * 10 / ship.speed);
		basicConsumption = ship.consumption * ship.amount;
		dataFlyConsumption2 += basicConsumption * dataFlyDistance / 35000 * (spd / 10 + 1) * (spd / 10 + 1);
	});
	return Math.round((dataFlyConsumption + dataFlyConsumption2) * honorboost) + 1;
}

function storage() {
	return data.fleetroom - dataFlyConsumption;
}

function refreshFormData() {
	var seconds = dataFlyTime;
	var hours = Math.floor(seconds / 3600);
	seconds -= hours * 3600;
	var minutes = Math.floor(seconds / 60);
	seconds -= minutes * 60;
	document.getElementById("duration").textContent = hours + (":" + dezInt(minutes, 2) + ":" + dezInt(seconds,2) + " h");
	document.getElementById("distance").textContent = pretty_number(dataFlyDistance);
	document.getElementById("maxspeed").textContent = pretty_number(data.maxspeed);
	const color = dataFlyCargoSpace >= 0 ? "lime" : "red";
	document.getElementById("consumption").innerHTML = "<font color=\"" + color + "\">" + pretty_number(dataFlyConsumption) + "</font>";
	document.getElementById("storage").innerHTML = "<font color=\"" + color + "\">" + pretty_number(dataFlyCargoSpace) + "</font>";
}

function setACSTarget(galaxy, solarsystem, planet, type, tacs) {
	setTarget(galaxy, solarsystem, planet, type);
	updateVars();
	document.getElementsByName("group")[0].value = tacs;
}

function setTarget(galaxy, solarsystem, planet, type) {
	document.getElementsByName("galaxy")[0].value = galaxy;
	document.getElementsByName("system")[0].value = solarsystem;
	document.getElementsByName("planet")[0].value = planet;
	document.getElementsByName("type")[0].value = type;
}

function FleetTime(){
	var starttime = dataFlyTime;
	var endtime	= starttime + dataFlyTime;
	document.getElementById("arrival").innerHTML = getFormatedDate(now().getTime()+1000*starttime, tdformat);
	document.getElementById("return").innerHTML = getFormatedDate(now().getTime()+1000*endtime, tdformat);
}

function setResource(id, val) {
	if (document.getElementsByName(id)[0]) {
		document.getElementsByName("resource" + id)[0].value = val;
	}
}

function maxResource(id) {
	var thisresource = getRessource(id);
	var thisresourcechosen = parseInt(document.getElementsByName(id)[0].value);
	if (isNaN(thisresourcechosen)) {
		thisresourcechosen = 0;
	}
	if (isNaN(thisresource)) {
		thisresource = 0;
	}

	var storCap = data.fleetroom - data.consumption;

	if (id == 'deuterium') {
		thisresource -= data.consumption;
	}
	var metalToTransport = parseInt(document.getElementsByName("metal")[0].value);
	var crystalToTransport = parseInt(document.getElementsByName("crystal")[0].value);
	var deuteriumToTransport = parseInt(document.getElementsByName("deuterium")[0].value);
	if (isNaN(metalToTransport)) {
		metalToTransport = 0;
	}
	if (isNaN(crystalToTransport)) {
		crystalToTransport = 0;
	}
	if (isNaN(deuteriumToTransport)) {
		deuteriumToTransport = 0;
	}
	var freeCapacity = Math.max(storCap - metalToTransport - crystalToTransport - deuteriumToTransport, 0);
	document.getElementsByName(id)[0].value = Math.min(freeCapacity + thisresourcechosen, thisresource);
	calculateTransportCapacity();
}


function maxResources() {
	maxResource('metal');
	maxResource('crystal');
	maxResource('deuterium');
}

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

function calculateTransportCapacity() {
	var metal = Math.abs(document.getElementsByName("metal")[0].value);
	var crystal = Math.abs(document.getElementsByName("crystal")[0].value);
	var deuterium = Math.abs(document.getElementsByName("deuterium")[0].value);
	transportCapacity = data.fleetroom - data.consumption - metal - crystal - deuterium;
	if (transportCapacity < 0) {
		document.getElementById("remainingresources").innerHTML = "<font color=red>" + pretty_number(transportCapacity) + "</font>";
	} else {
		document.getElementById("remainingresources").innerHTML = "<font color=lime>" + pretty_number(transportCapacity) + "</font>";
	}
	updateTradeRatio();
	return transportCapacity;
}

function parseTradeAmount(value) {
	var amount = parseInt(value, 10);
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

function convertTradeAmountToDeut(amount, resourceId, rates) {
	var key = tradeResourceKey(resourceId);
	if (!key || amount <= 0 || !rates) {
		return 0;
	}
	if (key === 'deuterium') {
		return Math.ceil(amount);
	}
	var rate = parseFloat(rates[key]);
	if (!isFinite(rate) || rate <= 0) {
		return 0;
	}
	return Math.ceil(amount / rate);
}

function setTradeRatioDisplay(ratio) {
	var ratioEl = document.getElementById('tradeRelativeRatio');
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

function getLoadedTradeResources() {
	var metalEl = document.getElementsByName('metal')[0];
	var crystalEl = document.getElementsByName('crystal')[0];
	var deuteriumEl = document.getElementsByName('deuterium')[0];
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
	var el = getTradeElement(name);
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
	var i;
	for (i = 0; i < selectEl.options.length; i++) {
		selectEl.options[i].disabled = parseInt(selectEl.options[i].value, 10) === disabledId;
	}
}

function setTradeCargo(metal, crystal, deuterium) {
	var metalEl = getTradeElement('metal');
	var crystalEl = getTradeElement('crystal');
	var deuteriumEl = getTradeElement('deuterium');
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

function ensureTradePairExclusive() {
	var offerEl = getTradeElement('resOffer');
	var askEl = getTradeElement('resEx');
	if (!offerEl || !askEl) {
		return;
	}
	setTradeOptionsDisabled(offerEl, 0);
	if (isFleetMarket()) {
		setTradeOptionsDisabled(askEl, 0);
		return;
	}
	var offerId = parseInt(offerEl.value, 10);
	if (offerId === parseInt(askEl.value, 10)) {
		askEl.value = String(otherTradeResource(offerId));
	}
	setTradeOptionsDisabled(askEl, offerId);
}

function syncTradeOfferToCargo() {
	if (isFleetMarket()) {
		setTradeCargo(0, 0, 0);
		return;
	}
	var amountEl = document.getElementById('tradeOfferAmount');
	if (!amountEl) {
		return;
	}
	var offerId = getTradeResourceId('resOffer');
	var amount = parseTradeAmount(amountEl.value);
	setTradeCargo(offerId === 1 ? amount : 0, offerId === 2 ? amount : 0, offerId === 3 ? amount : 0);
}

function applyTradeMarketType() {
	var isFleet = isFleetMarket();
	var offerRow = document.getElementById('tradeOfferRow');
	var selectedResRow = document.getElementById('selectedResRow');
	if (offerRow) {
		offerRow.style.display = isFleet ? 'none' : '';
	}
	if (selectedResRow && getTradeElement('resOffer')) {
		selectedResRow.style.display = isFleet ? 'none' : '';
	}
	if (isFleet) {
		var amountEl = document.getElementById('tradeOfferAmount');
		if (amountEl) {
			amountEl.value = '';
		}
	}
	ensureTradePairExclusive();
	syncTradeOfferToCargo();
	calculateTransportCapacity();
}

function maxTradeOffer() {
	if (isFleetMarket()) {
		return;
	}
	var key = tradeResourceKey(getTradeResourceId('resOffer'));
	if (!key || !getTradeElement(key)) {
		return;
	}
	['metal', 'crystal', 'deuterium'].forEach(function (name) {
		if (name !== key && getTradeElement(name)) {
			getTradeElement(name).value = 0;
		}
	});
	maxResource(key);
	var amountEl = document.getElementById('tradeOfferAmount');
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

function applySelectedResourcesToTrade() {
	if (!getTradeElement('resOffer')) {
		return;
	}
	if (isFleetMarket()) {
		syncTradeOfferToCargo();
		calculateTransportCapacity();
		return;
	}
	var amountEl = document.getElementById('tradeOfferAmount');
	var loaded = getLoadedTradeResources();
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

function updateTradeRatio() {
	if (typeof tradeMarket === 'undefined' || !tradeMarket) {
		return;
	}
	var markettypeEl = document.getElementsByName('markettype')[0];
	var resExEl = document.getElementsByName('resEx')[0];
	var exchangeEl = document.getElementsByName('exchange')[0];
	if (!markettypeEl || !resExEl || !exchangeEl) {
		return;
	}

	var markettype = parseInt(markettypeEl.value, 10) || 0;
	var askId = parseInt(resExEl.value, 10);
	var askAmount = parseTradeAmount(exchangeEl.value);
	var rates = tradeMarket.rates;

	if (markettype === 1) {
		var fleetAskDeut = convertTradeAmountToDeut(askAmount, askId, rates);
		setTradeRatioDisplay(fleetAskDeut > 0 ? tradeMarket.fleetDeut / fleetAskDeut : 0);
		return;
	}

	var loaded = getLoadedTradeResources();
	if (loaded.length !== 1 || loaded[0].id === askId) {
		setTradeRatioDisplay(0);
		return;
	}

	var offer = loaded[0];
	var offerDeut = convertTradeAmountToDeut(offer.amount, offer.id, rates);
	var askDeut = convertTradeAmountToDeut(askAmount, askId, rates);
	setTradeRatioDisplay(askDeut > 0 ? offerDeut / askDeut : 0);
}

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

function maxShip(id) {
	var input = document.getElementsByName(id)[0];
	var cell = document.getElementById(id + "_value");
	if (input && cell) {
		var raw = cell.getAttribute("data-count");
		input.value = (raw !== null && raw !== "") ? raw : cell.textContent.replace(/\D/g, "");
	}
  show_values();
}

function maxShips() {
	var id;
	qsa('input[name^="ship"]').forEach(function(input) {
		maxShip(input.name);
	});
  show_values();
}


function noShip(id) {
	if (document.getElementsByName(id)[0]) {
		document.getElementsByName(id)[0].value = 0;
	}
  show_values();
}


function noShips() {
	var id;
	qsa('input[name^="ship"]').forEach(function(input) {
		noShip(input.name);
	});
  show_values();
}

function setNumber(name, number) {
	if (typeof document.getElementsByName("ship" + name)[0] != "undefined") {
		document.getElementsByName("ship" + name)[0].value = number;
	}
}

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

function removeBeforeUnload() {
	window.removeEventListener("beforeunload", handleBeforeUnload);
}
function handleBeforeUnload(event) {
	navigator.sendBeacon("game?page=fleetStepAjax&ajax=1&token=" + token);
}
function addBeforeUnload() {
	window.addEventListener("beforeunload", handleBeforeUnload);
}

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

function EditShortcuts(autoadd) {
	qsa(".shortcut-link").forEach(hide);
	// the stylesheet hides .shortcut-edit, so the display value has to be set explicitly
	qsa(".shortcut-edit:not(.shortcut-new)").forEach(function (el) { show(el, el.tagName === 'TR' ? 'table-row' : 'block'); });
	if(document.querySelectorAll('.shortcut-isset').length === 0)
		AddShortcuts();
}

function AddShortcuts() {
	var HTML	= Array.from(document.querySelector('.shortcut-new td').cloneNode(true).children);
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

function SaveShortcuts(reedit) {
  post('game?page=fleetStep1&mode=saveShortcuts&ajax=1', serialize(qsa('.shortcut-row input, .shortcut-row select'))).then(function(res) {
		qsa(".shortcut-link").forEach(function (el) { show(el); });
		qsa(".shortcut-edit").forEach(hide);

    window.location.reload();
	});
}

function checkHold(mission) {
	var stayHead = document.getElementById("stay_head");
	var stay = document.getElementById("stay");
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

function sendFleet() {
	let mission = document.querySelector('input[name="mission"]:checked').value;
	let message = window.questionList[parseInt(mission)] || null;
	showWait();
	if (message) {
		if (!confirm(message)) {
			showSubmit();
			return false;
		}
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

function update_arrival() {
	const current = new Date(Date.now())
	const deT = new Date(current.toLocaleString('en-us', { timeZone: 'Europe/Berlin'})).getTime()
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


// called from inline handlers / links of the fleet templates and from fleetTable.js
Object.assign(window, {
	updateVars, setTarget, setACSTarget, setResource, maxResource, maxResources, selectedResources,
	calculateTransportCapacity, maxTradeOffer, maxShip, maxShips, noShip, noShips, setNumber, setCargoPreset,
	CheckTarget, CheckResources, EditShortcuts, AddShortcuts, SaveShortcuts, checkHold, sendFleet, FleetTime,
	initTradeMarket, addBeforeUnload, removeBeforeUnload,
});
