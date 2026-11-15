/** Test data form: swaps the form for a spinner while the server creates the users. */
document.getElementById('testDataForm').addEventListener('submit', () => {
  document.getElementById('testDataForm').classList.add('hidden');
  document.getElementById('loadingSpinner').classList.remove('hidden');
});
