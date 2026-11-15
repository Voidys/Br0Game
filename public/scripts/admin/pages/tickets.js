/** Ticket list: the category select hides the rows of the open tickets table that belong to other categories. */
document.getElementById('categoryID').addEventListener('change', (event) => {
  const category = event.target.value;
  for (const row of document.querySelectorAll('#openTickets tr')) {
    const other = category != 0 && row.dataset.category != null && row.dataset.category != category;
    row.style.display = other ? 'none' : '';
  }
});
