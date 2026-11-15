import { qs, qsa, on, shortly_number, pretty_number } from '../app/core.js';

// CostInfo, Charge and charge are defined by the inline script of the template
function updateVars()
{
	var shipID 	= qs('#shipID').value;
	const img = qs('#img');
	img.setAttribute('src', ShipImg[shipID]);
	qs('#metal').textContent = pretty_number(CostInfo[shipID][2][901] * (1 - Charge / 100));
	qs('#crystal').textContent = pretty_number(CostInfo[shipID][2][902] * (1 - Charge / 100));
	qs('#deuterium').textContent = pretty_number(CostInfo[shipID][2][903] * (1 - Charge / 100));
	qs('#traderHead').textContent = CostInfo[shipID][1];
	Reset();
}

function MaxShips()
{
	var shipID 	= qs('#shipID').value;
	qs('#count').value = CostInfo[shipID][0];
	Total();
}

function Total()
{
	var Count	= qs('#count').value;

	if(isNaN(Count) || Count < 0) {
		qs('#count').value = 0;
		Count = 0;
	}

	var shipID 	= qs('#shipID').value;
	qs('#total_metal').textContent = pretty_number(CostInfo[shipID][2][901] * Count * (1 - Charge / 100));
	qs('#total_crystal').textContent = pretty_number(CostInfo[shipID][2][902] * Count * (1 - Charge / 100));
	qs('#total_deuterium').textContent = pretty_number(CostInfo[shipID][2][903] * Count * (1 - Charge / 100));
}

function Reset()
{
	qs('#count').value = 0;
	qs('#total_metal').textContent = 0;
	qs('#total_crystal').textContent = 0;
	qs('#total_deuterium').textContent = 0;
}

Object.assign(window, { updateVars, MaxShips, Total, Reset });

onPageInit(function() {
	updateVars();

	qsa('.trade_input').forEach(function(input) {
		const shortly = qs('#' + input.id + 'Shortly');
		if (shortly) shortly.innerHTML = 0;
	});

	on(document, 'keyup', '.trade_input', function(event, input) {
		const shortly = qs('#' + input.id + 'Shortly');
		if (shortly) shortly.innerHTML = shortly_number(input.value);

		var needResource	= 0;

		qsa('.trade_input').forEach(function(field) {
			needResource	+= parseFloat(field.value) * charge[field.dataset.resource];
		});
		qs('#ress').textContent = isNaN(needResource) ? 0 : pretty_number(needResource);
	});

	qs('#trader')?.addEventListener('submit', function() {
		qsa('.trade_input').forEach(function(field) {
			field.value = field.value.replace(/\D/g, '');
		});
	});
});
