import { serialize, getJSON } from '../app/core.js';

function submitinstall() {
  const lang = location.search.split('&')[2].substr(-2);
  getJSON(`?mode=ajax&action=install&lang=${lang}&${serialize(document.getElementById('install'))}`).then((data) => {
    alert(data.msg);
    if (!data.error) document.location.href = `?mode=ins&page=2&lang=${lang}`;
  });
  return false;
}

window.submitinstall = submitinstall;
