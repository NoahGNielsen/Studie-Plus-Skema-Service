// Kopiér-knap til kalenderlinket. Ingen eksterne scripts, ingen analytics.
document.addEventListener('click', async (event) => {
  const button = event.target.closest('[data-copy]');
  if (!button) return;
  const input = document.getElementById(button.dataset.copy);
  const status = document.getElementById('copy-status');
  if (!input) return;
  try {
    await navigator.clipboard.writeText(input.value);
    if (status) status.textContent = 'Linket er kopieret.';
  } catch {
    input.focus();
    input.select();
    if (status) status.textContent = 'Markér linket og kopiér det manuelt.';
  }
});

document.addEventListener('focusin', (event) => {
  if (event.target.classList && event.target.classList.contains('copy-input')) {
    event.target.select();
  }
});
