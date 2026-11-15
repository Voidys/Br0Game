import { pretty_number } from '../app/core.js';

// CostInfo, Charge and ShipImg (resolved ship image URLs) are defined by the inline script of the template
const el = (id) => document.getElementById(id);

function updateVars()
{
	var shipID 	= el('shipID').value;
	const img = el('img');
	img.setAttribute('src', ShipImg[shipID]);
	el('metal').textContent = pretty_number(CostInfo[shipID][2][901] * (1 - Charge / 100));
	el('crystal').textContent = pretty_number(CostInfo[shipID][2][902] * (1 - Charge / 100));
	el('deuterium').textContent = pretty_number(CostInfo[shipID][2][903] * (1 - Charge / 100));
	el('traderHead').textContent = CostInfo[shipID][1];
	Reset();
}

function MaxShips()
{
	var shipID 	= el('shipID').value;
	el('count').value = CostInfo[shipID][0];
	Total();
}

function Total()
{
	var Count	= el('count').value;

	if(isNaN(Count) || Count < 0) {
		el('count').value = 0;
		Count = 0;
	}

	var shipID 	= el('shipID').value;
	el('total_metal').textContent = pretty_number(CostInfo[shipID][2][901] * Count * (1 - Charge / 100));
	el('total_crystal').textContent = pretty_number(CostInfo[shipID][2][902] * Count * (1 - Charge / 100));
	el('total_deuterium').textContent = pretty_number(CostInfo[shipID][2][903] * Count * (1 - Charge / 100));
}

function Reset()
{
	el('count').value = 0;
	el('total_metal').textContent = 0;
	el('total_crystal').textContent = 0;
	el('total_deuterium').textContent = 0;
}

Object.assign(window, { updateVars, MaxShips, Total });

onPageInit(updateVars);
