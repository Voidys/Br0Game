import { qs, qsa, on, now, getRestTimeFormat, setVisibleInterval } from '../app/core.js';

const elapsed = () => (now().getTime() - (window.startTime ?? now().getTime())) / 1000;

function remaining(el, umode) {
  const time = Number(el.dataset.time);
  return umode ? time : time - elapsed();
}

function init() {
  const umode = qs('.timer')?.dataset.umode == 1;

  setVisibleInterval(() => {
    qsa('.fleets').forEach((el) => {
      const s = Number(el.dataset.fleetTime) - elapsed();
      el.textContent = s <= 0 ? '-' : getRestTimeFormat(s);
    });
  }, 1000);

  setVisibleInterval(() => {
    qsa('.timer').forEach((el) => {
      el.textContent = getRestTimeFormat(Math.max(0, remaining(el, umode)));
    });

    qsa('.timerrepair').forEach((el) => {
      let s = remaining(el, umode);
      if (s <= 0) {
        if (el.dataset.repairing === 'true' || el.dataset.repairing === '1') {
          el.dataset.repairing = 'false';
          el.dataset.time = Number(el.dataset.time) + 259200;
          const text = document.getElementById('repairtext');
          if (text) text.textContent = text.dataset.alttext;
          s = remaining(el, umode);
        } else {
          s = 0;
        }
      }
      el.textContent = getRestTimeFormat(Math.max(0, s));
    });

    qsa('.timershort').forEach((el) => {
      el.textContent = getRestTimeFormat(Math.max(0, remaining(el, umode)), true);
    });
  }, 1000);

  // news and event list toggles
  const news = document.getElementById('hidden-div');
  const newsShow = document.getElementById('chkbtn');
  on(document, 'click', '#chkbtn', (event, button) => {
    button.style.display = 'none';
    if (news) news.style.display = 'block';
  });
  on(document, 'click', '#chkbtn2', () => {
    if (newsShow) newsShow.style.display = '';
    if (news) news.style.display = 'none';
  });
  on(document, 'click', '#chkbtn1', (event, button) => {
    button.style.display = 'none';
    document.getElementById('hidden-div2').style.display = 'none';
    document.getElementById('tn3').style.display = '';
  });
  on(document, 'click', '#chkbtn3', () => {
    document.getElementById('chkbtn1').style.display = '';
    document.getElementById('hidden-div2').style.display = '';
    document.getElementById('tn3').style.display = 'none';
  });
}

window.onPageInit(init);
