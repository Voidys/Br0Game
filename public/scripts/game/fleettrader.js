import { pretty_number, pageData } from '../app/core.js';
import { registerActions } from '../app/actions.js';

/**
 * Fleet dealer: shows the price of the chosen ship type (with the dealer's discount) and the sum for the
 * typed amount. costInfo (per ship: [max amount, name, {resource: price}]), charge (discount in percent)
 * and shipImg (resolved image URLs) come from the data island #fleet-dealer-data.
 */
const { costInfo: CostInfo, charge: Charge, shipImg: ShipImg } = pageData('fleet-dealer-data');
const el = (id) => document.getElementById(id);

/** Shows image, name and discounted per-ship price of the selected ship type (price * (1 - charge/100)) and resets the amount. */
function updateVars()
{
	let shipID 	= el('shipID').value;
	const img = el('img');
	img.setAttribute('src', ShipImg[shipID]);
	el('metal').textContent = pretty_number(CostInfo[shipID][2][901] * (1 - Charge / 100));
	el('crystal').textContent = pretty_number(CostInfo[shipID][2][902] * (1 - Charge / 100));
	el('deuterium').textContent = pretty_number(CostInfo[shipID][2][903] * (1 - Charge / 100));
	el('traderHead').textContent = CostInfo[shipID][1];
	Reset();
}

/** Fills the amount with the maximum available for the selected ship type and updates the total. */
function MaxShips()
{
	let shipID 	= el('shipID').value;
	el('count').value = CostInfo[shipID][0];
	Total();
}

/** Total price for the typed amount (invalid or negative input becomes 0), discount applied. */
function Total()
{
	let Count	= el('count').value;

	if(isNaN(Count) || Count < 0) {
		el('count').value = 0;
		Count = 0;
	}

	let shipID 	= el('shipID').value;
	el('total_metal').textContent = pretty_number(CostInfo[shipID][2][901] * Count * (1 - Charge / 100));
	el('total_crystal').textContent = pretty_number(CostInfo[shipID][2][902] * Count * (1 - Charge / 100));
	el('total_deuterium').textContent = pretty_number(CostInfo[shipID][2][903] * Count * (1 - Charge / 100));
}

/** Clears amount and totals. */
function Reset()
{
	el('count').value = 0;
	el('total_metal').textContent = 0;
	el('total_crystal').textContent = 0;
	el('total_deuterium').textContent = 0;
}

registerActions({
	'trader-update': () => updateVars(),
	'trader-total': () => Total(),
	'trader-max': () => { MaxShips(); return false; },
});

onPageInit(updateVars);
