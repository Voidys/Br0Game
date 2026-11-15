import { qs, qsa, getJSON, serialize } from '../app/core.js';

const Gate	= {
	max: function(ID) {
		var cell = qs('#ship'+ID+'_value');
		var raw = cell.getAttribute('data-count');
		qs('#ship'+ID+'_input').value = (raw !== null && raw !== '') ? raw : cell.textContent.replace(/\D/g, '');
	},

	submit: async function() {
		const data = await getJSON('?page=information&mode=sendFleet&'+serialize(qsa('.jumpgate')));
		window.Dialog.alert(data.message, function () {
			if (!data.error) window.Dialog.close();
		});
	}
};

window.Gate = Gate;
