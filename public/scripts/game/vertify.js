import { qs, getJSON, getText, ready } from '../app/core.js';

const counters = { 1: ['fileok', 'green'], 2: ['filefail', 'red'], 3: ['fileerror', 'orange'], 4: ['filenew', 'grey'] };

function compareFiles(list, pointer, box) {
  if (typeof list[++pointer] === 'undefined') return;

  const file = list[pointer];
  const element = document.createElement('div');
  element.textContent = `File: ${file}`;
  box.append(element);
  box.scrollTop += 14;

  getText(`?page=vertify&action=check&file=${file}`).then((text) => {
    const percent = ((pointer + 1) / list.length) * 100;
    qs('.processbar').style.width = `${percent}%`;
    qs('.info').textContent = `${Math.ceil(percent)}%`;
    const [counter, color] = counters[text.trim()] ?? [];
    if (counter) {
      element.style.backgroundColor = color;
      if (color === 'grey') element.style.color = 'black';
      const target = document.getElementById(counter);
      target.textContent = parseInt(target.textContent, 10) + 1;
    }
    compareFiles(list, pointer, box);
  });
}

ready(() => {
  qs('.processbar').style.width = '1px';
  qs('.info').textContent = '0%';
  getJSON(`?page=vertify&action=getFileList&${document.location.search.split('&').pop()}`).then((list) => {
    const box = qs('#result > td > div');
    box.replaceChildren();
    compareFiles(list, 0, box);
  });
});
