import { qs, qsa, on, post, serialize } from '../app/core.js';
import { Dialog } from '../app/dialog.js';
import { tabs } from '../app/ui.js';

function submitTo(action) {
	const form = document.getElementById('form');
	form.setAttribute('action', action);
	form.setAttribute('method', 'POST');
	form.submit();
	return true;
}

function add(){
	return submitTo('game?page=battleSimulator&action=moreslots');
}

function switchSides(){
	return submitTo('game?page=battleSimulator&action=switch');
}

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

// the tab strip is built by the shared tabs widget (data-tabs); rebuilt after slots were added or removed
function rebuildTabs() {
	const container = document.getElementById('tabs');
	// drop the old click handlers by replacing the links
	qsa(':scope > ul a', container).forEach(function (link) { link.replaceWith(link.cloneNode(true)); });
	delete container.dataset.tabsReady;
	tabs(container);
}

// clear button in a section header: sets the number fields of its column in this section to 0
on(document, 'click', '.sim-clear', function (event, button) {
	event.preventDefault();
	var cell = button.closest('th');
	var index = Array.prototype.indexOf.call(cell.parentElement.children, cell);
	var row = cell.parentElement.nextElementSibling;
	while (row) {
		var target = row.children[index];
		if (target) {
			qsa('input[type="number"]', target).forEach(function (input) { input.value = 0; });
		}
		row = row.nextElementSibling;
	}
});

function generateShareString(){
	var slots = parseInt(document.getElementById('slots').value, 10) || 1;
	var data = {};
	for(var s=0;s<slots;s++){
		data[s] = {};
		data[s][0] = {};
		data[s][1] = {};
		qsa('[name^="battleinput['+s+']"]').forEach(function(input){
			var name = input.getAttribute('name');
			var m = name.match(/^battleinput\[\d+\]\[(\d)\]\[(\d+)\]$/);
			if(m){
				var side = parseInt(m[1],10);
				var id = m[2];
				var val = input.value;
				if(val === undefined || val === null || val === '') val = 0;
				data[s][side][id] = parseFloat(val);
			}
		});
	}
	try{
		var json = JSON.stringify(data);
		var b64 = LZString.compressToBase64(json);
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

function fallbackCopy(text){
	var el = document.createElement('textarea');
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
		prompt(BS_LNG.shareCopyPrompt, text);
	}
	document.body.removeChild(el);
}

function loadShareString(){
	var str = document.getElementById('shareInput').value;
	if(!str) {
		Dialog.alert(BS_LNG.shareLoadNoInput);
		return;
	}
	try{
		var json = LZString.decompressFromBase64(str);
		var data = JSON.parse(json);
	}catch(e){
		Dialog.alert(BS_LNG.shareLoadInvalid);
		return;
	}

	const container = document.getElementById('tabs');
	const list = qs(':scope > ul', container);

	// determine required slots
	var slotsNeeded = 0;
	for(var k in data){ if(data.hasOwnProperty(k)) slotsNeeded = Math.max(slotsNeeded, parseInt(k,10)+1); }
	if(slotsNeeded === 0) slotsNeeded = 1;

	// current slots
	var currentSlots = qsa(':scope > div', container).length;

	// remove extra tabs first (keep first)
	if(currentSlots > 1){
		for(var i=currentSlots-1;i>=1;i--){
			list.children[i]?.remove();
			document.getElementById('tabs-'+i)?.remove();
		}
	}

	// clone template (tabs-0) to create needed slots
	var baseLabel = list.querySelector('li a').textContent.replace(/\s*\d+\s*$/, '') || 'ACS Slot';
	for(var i=1;i<slotsNeeded;i++){
		var newDiv = document.getElementById('tabs-0').cloneNode(true);
		newDiv.id = 'tabs-'+i;
		qsa('[name]', newDiv).forEach(function(field) {
			var name = field.getAttribute('name');
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
	for(var s=0;s<slotsNeeded;s++){
		var slotData = data[s] || {0:{},1:{}};
		for(var side=0;side<=1;side++){
			var sideData = slotData[side] || {};
			for(var id in sideData){
				if(!sideData.hasOwnProperty(id)) continue;
				qsa('[name="battleinput['+s+']['+side+']['+id+']"]').forEach(function (field) { field.value = sideData[id]; });
			}
		}
	}
}

Object.assign(window, { add, switchSides, check, generateShareString, loadShareString });
