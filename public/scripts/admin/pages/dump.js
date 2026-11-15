/** Database dump: "select all" (only the visible tables) and the live table filter of #dbtables. */
const boxes = () => document.querySelectorAll('#dbtables input[type=checkbox]');

document.getElementById('selectAll').addEventListener('click', (event) => {
  const { checked } = event.target;
  boxes().forEach((box) => { if (!box.closest('label').hidden) box.checked = checked; });
});

document.getElementById('dump-filter').addEventListener('input', (event) => {
  const query = event.target.value.toLowerCase();
  boxes().forEach((box) => { box.closest('label').hidden = query !== '' && !box.value.toLowerCase().includes(query); });
});
