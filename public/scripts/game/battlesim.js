import { qs, qsa, on, post, serialize, pageData } from '../app/core.js';
import { registerActions } from '../app/actions.js';
import { Dialog } from '../app/dialog.js';
import { tabs } from '../app/ui.js';
import LZString from '../base/battlesim/lz-string.js';

/**
 * Battle simulator: submit helpers (more slots, swap sides, simulate), the slot tabs and the share string
 * (compressed JSON of the inputs). The texts come from the data island #sim-data; the buttons are
 * data-action="sim-*" markup (app/actions.js).
 */
const BS_LNG = pageData('sim-data');

/** Submits the simulator form (POST) to another action URL; returns true. */
function submitTo(action) {
	const form = document.getElementById('form');
	form.setAttribute('action', action);
	form.setAttribute('method', 'POST');
	form.submit();
	return true;
}

/** Reloads the form with one more attacker slot (server action `moreslots`). */
function add(){
	return submitTo('game?page=battleSimulator&action=moreslots');
}

/** Swaps attackers and defenders (server action `switch`). */
function switchSides(){
	return submitTo('game?page=battleSimulator&action=switch');
}

/**
 * Runs the simulation: posts the form (`mode=send`), opens the returned report id in the modal (game?page=raport&raport=...) or
 * shows a text answer (error) as alert. The button stays disabled for 2 s afterwards because the server allows one
 * simulation every few seconds. Returns false if a simulation is still pending.
 */
function check(){
	const button = document.getElementById('sim-submit');
	if (button.disabled) return false;
	const label = qs('span', button);
	const idle = label.textContent;
	button.disabled = true;
	button.classList.add('is-busy');
	label.textContent = button.dataset.busyLabel || idle;
	// the simulated report opens in the page's modal (formerly a full screen popup window)
	post('game?page=battleSimulator&mode=send', serialize(document.getElementById('form'))).then(function(data){
		try{
			data	= JSON.parse(data);
			Dialog.open('game?page=raport&raport=' + encodeURIComponent(data), 1100);
		} catch(e) {
			Dialog.alert(data);
		}
	}).finally(function(){
		// the server allows one simulation every few seconds
		setTimeout(function(){
			button.disabled = false;
			button.classList.remove('is-busy');
			label.textContent = idle;
		}, 2000);
	});
	return true;
}

/** The tab strip is built by the shared tabs widget (data-tabs); rebuilt after slots were added or removed (old click handlers are dropped by cloning the links). */
function rebuildTabs() {
	const container = document.getElementById('tabs');
	// drop the old click handlers by replacing the links
	qsa(':scope > ul a', container).forEach(function (link) { link.replaceWith(link.cloneNode(true)); });
	delete container.dataset.tabsReady;
	tabs(container);
}

/** "Clear" button in a section header: sets the number fields of its column in this section to 0. */
on(document, 'click', '.sim-clear', function (event, button) {
	event.preventDefault();
	let cell = button.closest('th');
	let index = Array.prototype.indexOf.call(cell.parentElement.children, cell);
	let row = cell.parentElement.nextElementSibling;
	while (row) {
		let target = row.children[index];
		if (target) {
			qsa('input[type="number"]', target).forEach(function (input) { input.value = 0; });
		}
		row = row.nextElementSibling;
	}
});

/**
 * Copies the current inputs of all slots as a share string: JSON {slot: {side(0 attacker/1 defender): {unitId: count}}},
 * compressed with LZString to Base64. Uses the clipboard API, falling back to a hidden textarea or a prompt.
 */
function generateShareString(){
	let slots = parseInt(document.getElementById('slots').value, 10) || 1;
	let data = {};
	for(let s=0;s<slots;s++){
		data[s] = {};
		data[s][0] = {};
		data[s][1] = {};
		qsa('[name^="battleinput['+s+']"]').forEach(function(input){
			let name = input.getAttribute('name');
			let m = name.match(/^battleinput\[\d+\]\[(\d)\]\[(\d+)\]$/);
			if(m){
				let side = parseInt(m[1],10);
				let id = m[2];
				let val = input.value;
				if(val === undefined || val === null || val === '') val = 0;
				data[s][side][id] = parseFloat(val);
			}
		});
	}
	try{
		let json = JSON.stringify(data);
		let b64 = LZString.compressToBase64(json);
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(b64).then(function(){
				Dialog.alert(BS_LNG.shareCopied);
			}, function(){
				fallbackCopy(b64);
			});
		} else {
			fallbackCopy(b64);
		}
	}catch(e){
		Dialog.alert(BS_LNG.shareError);
	}
}

/** Copies via a temporary textarea and execCommand; shows the text in a prompt when even that fails. */
function fallbackCopy(text){
	let el = document.createElement('textarea');
	el.value = text;
	el.setAttribute('readonly', '');
	el.style.position = 'absolute';
	el.style.left = '-9999px';
	document.body.appendChild(el);
	el.select();
	try{
		document.execCommand('copy');
		Dialog.alert(BS_LNG.shareCopied);
	}catch(e){
		Dialog.prompt(BS_LNG.shareCopyPrompt, { value: text });
	}
	document.body.removeChild(el);
}

/**
 * Fills the form from a share string (#shareInput): decodes it (invalid input gives an alert), removes extra slot tabs,
 * clones the first slot for every slot the string needs (field names renumbered, defense/resources only in slot 1), rebuilds
 * the tabs, sets the hidden slot count and writes the unit counts into the matching inputs.
 */
function loadShareString(){
	let str = document.getElementById('shareInput').value;
	if(!str) {
		Dialog.alert(BS_LNG.shareLoadNoInput);
		return;
	}
	let data;
	try{
		let json = LZString.decompressFromBase64(str);
		data = JSON.parse(json);
	}catch(e){
		Dialog.alert(BS_LNG.shareLoadInvalid);
		return;
	}

	const container = document.getElementById('tabs');
	const list = qs(':scope > ul', container);

	// determine required slots
	let slotsNeeded = 0;
	for(let k in data){ if(data.hasOwnProperty(k)) slotsNeeded = Math.max(slotsNeeded, parseInt(k,10)+1); }
	if(slotsNeeded === 0) slotsNeeded = 1;

	// current slots
	let currentSlots = qsa(':scope > div', container).length;

	// remove extra tabs first (keep first)
	if(currentSlots > 1){
		for(let i=currentSlots-1;i>=1;i--){
			list.children[i]?.remove();
			document.getElementById('tabs-'+i)?.remove();
		}
	}

	// clone template (tabs-0) to create needed slots
	let baseLabel = list.querySelector('li a').textContent.replace(/\s*\d+\s*$/, '') || 'ACS Slot';
	for(let i=1;i<slotsNeeded;i++){
		let newDiv = document.getElementById('tabs-0').cloneNode(true);
		newDiv.id = 'tabs-'+i;
		qsa('[name]', newDiv).forEach(function(field) {
			let name = field.getAttribute('name');
			if (name) {
				name = name.replace(/battleinput\[0\]/g, 'battleinput['+i+']');
				field.setAttribute('name', name);
			}
			if (field.tagName === 'INPUT') {
				field.value = 0;
			}
		});
		// defense and defender resources exist in the first slot only
		qsa('[data-first-slot-only]', newDiv).forEach(function (part) { part.remove(); });
		container.append(newDiv);
		list.insertAdjacentHTML('beforeend', '<li><a href="#tabs-'+i+'">'+baseLabel+' '+(i+1)+'</a></li>');
	}
	rebuildTabs();

	// update slots hidden value
	document.getElementById('slots').value = slotsNeeded;

	// populate inputs
	for(let s=0;s<slotsNeeded;s++){
		let slotData = data[s] || {0:{},1:{}};
		for(let side=0;side<=1;side++){
			let sideData = slotData[side] || {};
			for(let id in sideData){
				if(!sideData.hasOwnProperty(id)) continue;
				qsa('[name="battleinput['+s+']['+side+']['+id+']"]').forEach(function (field) { field.value = sideData[id]; });
			}
		}
	}
}

registerActions({
	'sim-add-slot': () => add(),
	'sim-switch-sides': () => switchSides(),
	'sim-send': () => check(),
	'sim-load-share': () => loadShareString(),
	'sim-generate-share': () => generateShareString(),
});
