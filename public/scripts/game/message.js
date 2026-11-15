import { qs, qsa, show, hide, getText, getJSON, post, cookie, NotifyBox } from '../app/core.js';

const Message = {
  MessID: 0,
  categoryStorageKey: 'messages.lastCategory',
  openAllStorageKey: 'messages.openAllWithoutCategory',
  rememberCategoryStorageKey: 'messages.rememberLastCategory',

  initCategoryPreferences: function () {
    var openAll = localStorage.getItem(Message.openAllStorageKey) === '1';
    var rememberCategory = localStorage.getItem(Message.rememberCategoryStorageKey) === '1';
    var openAllSwitch = document.getElementById('message-open-all');
    var rememberCategorySwitch = document.getElementById('message-remember-category');
    var url = new URL(window.location.href);

    openAllSwitch.checked = openAll;
    rememberCategorySwitch.checked = rememberCategory;

    if (!url.searchParams.has('category')) {
      var lastCategory = localStorage.getItem(Message.categoryStorageKey);
      var categoryItem = /^\d+$/.test(String(lastCategory))
        ? document.querySelector('[data-message-category="' + lastCategory + '"]')
        : null;

      if (rememberCategory && categoryItem) {
        url.searchParams.set('category', lastCategory);
        window.location.replace(url.toString());
        return;
      }

      if (openAll) {
        url.searchParams.set('category', '100');
        window.location.replace(url.toString());
        return;
      }
    } else {
      Message.rememberCategory(url.searchParams.get('category'));
    }

    openAllSwitch.addEventListener('change', function () {
      localStorage.setItem(Message.openAllStorageKey, this.checked ? '1' : '0');
      if (this.checked) {
        rememberCategorySwitch.checked = false;
        localStorage.setItem(Message.rememberCategoryStorageKey, '0');
      }
    });

    rememberCategorySwitch.addEventListener('change', function () {
      localStorage.setItem(Message.rememberCategoryStorageKey, this.checked ? '1' : '0');
      if (this.checked) {
        openAllSwitch.checked = false;
        localStorage.setItem(Message.openAllStorageKey, '0');
      }
    });
  },

  rememberCategory: function (category) {
    if (/^\d+$/.test(String(category))) {
      localStorage.setItem(Message.categoryStorageKey, String(category));
    }
  },

  MessageCount: function () {
    var setText = function (selector, text) {
      var el = qs(selector);
      if (el) el.textContent = text;
    };
    var num = function (selector) {
      return parseInt(qs(selector)?.textContent, 10);
    };

    if (Message.MessID == 100) {
      [0, 1, 2, 3, 4, 5, 15, 99, 100].forEach(function (id) {
        setText('#unread_' + id, '0');
      });
      setText('#newmes', '');
    } else {
      var count = num('#unread_' + Message.MessID);
      var lmnew = num('#newmesnum');

      setText('#unread_' + Message.MessID, Math.max(0, num('#unread_100') - 10));
      if (Message.MessID != 999) {
        setText('#unread_100', num('#unread_100') - count);
      }

      if (lmnew - count <= 0)
        setText('#newmes', '');
      else
        setText('#newmesnum', lmnew - count);
    }
  },

  getMessages: async function (MessID, page) {
    if (typeof page === "undefined") {
      page = 1;
    }
    Message.MessID = MessID;
    Message.MessageCount(MessID);

    show(qs('#loading'));

    const data = await getText('game?page=messages&mode=view&messcat=' + MessID + '&site=' + page + '&ajax=1');
    hide(qs('#loading'));
    qs('#messagestable')?.remove();
    qs('#content table')?.insertAdjacentHTML('afterend', data);
  },

  stripHTML: function (string) {
    return string.replace(/<(.|\n)*?>/g, '');
  },

  CreateAnswer: function (Answer) {
    var answer = Message.stripHTML(Answer).trim();
    var prefixes = answer.match(/^(?:Re(?:\[(\d+)\])?:\s*)+/i);

    if (!prefixes) {
      return 'Re: ' + answer;
    }

    var replyLevel = 1;
    var prefix = prefixes[0];
    var levelMatches = prefix.match(/Re\[(\d+)\]/gi) || [];
    for (var i = 0; i < levelMatches.length; i++) {
      replyLevel = Math.max(replyLevel, parseInt(levelMatches[i].match(/\d+/)[0], 10));
    }

    return 'Re[' + (replyLevel + 1) + ']: ' + answer.substr(prefix.length);
  },

  getMessagesIDs: function (Infos) {
    var IDs = [];
    for (const mess of Infos) {
      if (mess.value == 'on')
        IDs.push(mess.name.replace(/delmes\[(\d+)\]/, '$1'));
    }
    return IDs;
  },

  delMessage: async function (ID, div_id) {
    show(qs('#loading'));

    const data = await getJSON('game?page=messages&mode=deleteMessage&delMessID=' + ID + '&ajax=1');
    hide(qs('#loading'));

    qs('#message_' + ID)?.remove();

    const group = document.getElementById(div_id);
    if (group && group.querySelectorAll(':scope > div').length == 0) {
      group.remove();
    }

    NotifyBox(data.mess);
  },

  favMessage: async function (ID, dpath) {
    show(qs('#loading'));

    const data = await getJSON('game?page=messages&mode=favMessage&favMessID=' + ID + '&ajax=1');
    hide(qs('#loading'));

    const img = qs('#imgfav_' + ID);
    if (img) {
      img.setAttribute('src', dpath + (data.code == 1 ? 'img/fav.png' : 'img/nofav.png'));
    }
  }
};

let selectBox;
let request = null;

// state set by inline scripts of the write/forward popup lives on window
// (emptyText, messageGroup, messageID, receiverList, receiver, searchNote, searchNoUser)

window.onPageInit(function () {
  selectBox = qs('.select-box');
  if (qs('#message-open-all')) {
    Message.initCategoryPreferences();
  }
  // "select all" boxes (above and below the list) tick every message on the page
  for (const all of qsa('[data-message-select-all]')) {
    all.addEventListener('change', function () {
      for (const box of qsa('input[name^="messageID["], [data-message-select-all]')) {
        box.checked = this.checked;
      }
    });
  }
});

async function sendAndClose(url, data) {
  const result = await post(url, data, 'json');
  window.Dialog.alert(result, () => window.Dialog.close());
}

function check() {
  const text = qs('#text').value;
  if (text.length === 0) {
    window.Dialog.alert(window.emptyText);
    return false;
  }
  sendAndClose('game?page=messages&mode=send&ajax=1', {
    'to[]': window.receiver,
    subject: qs('#subject').value,
    text: text,
    groupid: window.messageGroup,
  });
}

function checkForward() {
  sendAndClose('game?page=messages&mode=sendForward&ajax=1', {
    id: window.messageID,
    'to[]': window.receiver,
  });
}

function toggleSpyReport(messageId, dpath) {
  var content = document.getElementById('msgcontent_' + messageId);
  var details = document.getElementById('msgdetails_' + messageId);
  var title = document.getElementById('spytitle_' + messageId);
  var icon = document.getElementById('imgtoggle_' + messageId);

  if (content.style.display === 'none') {
    content.style.display = '';
    details.style.display = '';
    title.style.display = 'none';
    icon.src = dpath + 'img/collapse_spy_msg.png';
  } else {
    content.style.display = 'none';
    details.style.display = 'none';
    title.style.display = '';
    icon.src = dpath + 'img/expand_spy_msg.png';
  }
}

function toggleGrouping(MessID, fav) {
  var isGrp = parseInt(cookie.get('msgGrouping') || 0);
  cookie.set('msgGrouping', isGrp === 1 ? 0 : 1);
  window.location.href = 'game?page=messages&category=' + MessID + '&fav=' + fav;
}

async function loadUser() {
  const searchtext = qs('#to').value.trim();

  request?.abort();

  if (searchtext.length < 3) {
    selectBox.innerHTML = '<div class="select-box-option">' + window.searchNote + '</div>';
    return;
  }

  request = new AbortController();
  let data;
  try {
    const response = await fetch('game?page=messages&mode=loadUser&searchtext=' + encodeURIComponent(searchtext) + '&ajax=1', {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      signal: request.signal,
    });
    data = await response.json();
  } catch (error) {
    return; // aborted by a newer search
  }

  if (data.error || data.length === 0) {
    selectBox.innerHTML = '<div class="select-box-option">' + window.searchNoUser + '</div>';
  } else {
    selectBox.innerHTML = '';
    for (let i = 0; i < data.length; i++) {
      if (window.receiver.includes(data[i].id)) {
        continue;
      }
      const option = document.createElement('div');
      option.className = 'select-box-option';
      option.textContent = data[i].username;
      option.addEventListener('click', () => addUser(data[i].id, data[i].username));
      selectBox.append(option);
    }
  }
  if (selectBox.innerHTML === '') {
    selectBox.innerHTML = '<div class="select-box-option">' + window.searchNoUser + '</div>';
  }
}

function addUser(id, username) {
  hide(selectBox);
  qs('#to').value = '';
  selectBox.innerHTML = '';

  if (window.receiver.includes(id)) {
    return;
  }
  window.receiver.push(id);
  const entry = document.createElement('div');
  entry.className = 'receiver';
  entry.id = 'receiver-' + id;
  entry.append(username + ' ');
  const remove = document.createElement('span');
  remove.textContent = 'X';
  remove.addEventListener('click', () => removeUser(id));
  entry.append(remove);
  qs('#receiver-list').append(entry);
}

function removeUser(id) {
  window.receiver = window.receiver.filter(function (value) {
    return value !== id;
  });
  qs('#receiver-' + id)?.remove();
}

function hideSearch() {
  setTimeout(function () {
    hide(selectBox);
  }, 100);
}

function showSearch() {
  show(selectBox);
  loadUser();
}

Object.assign(window, {
  Message, check, checkForward, toggleSpyReport, toggleGrouping, loadUser, addUser, removeUser, hideSearch, showSearch,
});

function scavengers() {
  let stuff = document.getElementById("stuff");
  if (stuff == null) {
    return;
  }

  // TODO debris values übergeben
  const recycleList = {
    "202": 4000, //KT
    "203": 12000, //GT
    "204": 4000, //LJ
    "205": 10000, //SJ
    "206": 27000, //Xer
    "207": 60000, //SS
    "208": 30000, //Colo
    "209": 16000, //Rec
    "210": 1000, //Spio
    "211": 75000, //B
    "212": 2000, //Sat
    "213": 110000, //Zer
    "214": 9000000, //RIP
    "215": 70000, //SXer
  };

  let frombody = JSON.parse(stuff.getAttribute("jsthings"))
  const impulseEngineTech = frombody.impulseEngineTech
  const combustionEngineTech = frombody.combustionEngineTech
  const tfpercFleet = frombody.tfpercFleet
  const tfpercDeff = frombody.tfpercDeff
  const marketRatios = frombody.marketRatios
  const fleetspeed = frombody.fleetspeed
  const stbSettings = frombody.stbSettings
  const uniType = frombody.uniType
  const maxGalaxy = frombody.maxGalaxy

  function spyReportParser(spyReportElement) {
    let spyReport = {};
    var spyReportHead = spyReportElement.querySelector(".spyRaportHead");
    let cords = spyReportHead.getAttribute('coords').split(':');
    spyReport.head = {
      galaxy: cords[0],
      system: cords[1],
      planet: cords[2],
    };

    spyReport.content = {}
    for (let id of [901, 902, 903]) {
      spyReport.content[id] = 0;
    }
    for (let field of spyReportElement.querySelectorAll('div[data-info]')) {
      // Only read the amount itself: ships in the repair dock are appended as "(+ X)" in a child span
      let amount = field.firstChild ? field.firstChild.textContent : '';
      spyReport.content[field.getAttribute("data-info").split('_')[1]] = parseInt(amount.replace(/\D/g, ''))
    }
    return spyReport;
  }

  function sumUpResources(spyReport) {
    return spyReport.content[901] + spyReport.content[902] + spyReport.content[903];
  }

  function calculateNeededCapacity(metal, crystal, deuterium) {
    return 0.5 * Math.max(metal + crystal + deuterium, Math.min(0.75 * (2 * metal + crystal + deuterium), 2 * metal + deuterium));
  }

  function calculateResourceMarketValue(metal, crystal, deuterium) {
    return Math.ceil(metal / marketRatios[901] + crystal / marketRatios[902] + deuterium / marketRatios[903])
  }

  function estimateRecyclers(recycleValue) {
    return Math.ceil(recycleValue / 20000) + 1;
  }


  function raidTimeKT(met, kris, deut, raidLocation) {
    var bestPlanet,
      flytime = '',
      flytimeBestPlanet = '',
      flightSpeedKT = flightSpeed("202"),
      calculateResourceMarketValuePerSecond,
      calculateResourceMarketValuePerSecondBestPlanet,
      galaJump,
      galaJumpPlanet;

    var bestLocationForRaid = bestLocation(raidLocation);
    bestPlanet = bestLocationForRaid.bestPlanet;
    galaJump = bestLocationForRaid.galaJump;
    galaJumpPlanet = bestLocationForRaid.galaJumpPlanet;

    // Raid value estimator only — not the fleet send path (flotten.js GetDuration / getMissionDuration).
    flytime = Math.max(Math.round((3500 / (100 * 0.1) * Math.pow(bestLocationForRaid.activPlanetdistance * 10 / flightSpeedKT, 0.5) + 10) / 1 * Math.max(0, 1 + 100) * 1, 5));
    flytime = flytime / 10;

    flytimeBestPlanet = Math.max(Math.round((3500 / (100 * 0.1) * Math.pow(bestLocationForRaid.bestPlanetdistance * 10 / flightSpeedKT, 0.5) + 10) / 1 * Math.max(0, 1 + 100) * 1, 5));
    flytimeBestPlanet = flytimeBestPlanet / 10;

    calculateResourceMarketValuePerSecond = (calculateResourceMarketValue(met, kris, deut) / (flytime * 2));
    calculateResourceMarketValuePerSecond = calculateResourceMarketValuePerSecond.toFixed(2);

    calculateResourceMarketValuePerSecondBestPlanet = (calculateResourceMarketValue(met, kris, deut) / (flytimeBestPlanet * 2));
    calculateResourceMarketValuePerSecondBestPlanet = calculateResourceMarketValuePerSecondBestPlanet.toFixed(2);

    return {
      'calculateResourceMarketValuePerSecond': calculateResourceMarketValuePerSecond,
      'calculateResourceMarketValuePerSecondBestPlanet': calculateResourceMarketValuePerSecondBestPlanet,
      'bestPlanet': bestPlanet,
      'galaJumpPlanet': galaJumpPlanet,
      'galaJump': galaJump
    }

  }

  function bestLocation(raidLocation) {

    var activPlanet,
      activPlanetdistance,
      bestPlanet,
      bestPlanetdistance,
      galaJump,
      galaJumpPlanet,
      planetList,
      planetListLenght,
      bestPlanetGalaJump;

    planetList = document.getElementById("planetSelector");
    activPlanet = planetList.options[planetList.selectedIndex].text;
    planetListLenght = planetList.length;

    for (let index = 0; index < planetListLenght; index++) {

      let regexCoordinates = /\[(?<galaxy>[1-9]):(?<system>[0-9]{1,3}):(?<planet>[0-9]{1,2})\]/,
        planetCoordinates = planetList.options[index].text.match(regexCoordinates),
        tempPlananet = [planetCoordinates.groups.galaxy, planetCoordinates.groups.system, planetCoordinates.groups.planet],
        tempDiff,
        tempDistance,
        isActive = 0;

      if (activPlanet == planetList.options[index].text) {
        isActive = 1
      }

      // 0 = Lineares
      // 1 = Ring
      // 2 = Kreis
      if (uniType == 0){
        if (raidLocation[0] - tempPlananet[0] != 0) {
          tempDiff = Math.abs(raidLocation[0] - tempPlananet[0]);
          tempDistance = tempDiff * 20000;
          galaJump = 1;
        } else {
          if (raidLocation[1] - tempPlananet[1] != 0) {
            tempDiff = Math.abs(raidLocation[1] - tempPlananet[1]);
            tempDistance = tempDiff * 95 + 2700;
            galaJump = 0;
          } else {
            tempDiff = Math.abs(raidLocation[2] - tempPlananet[2]);
            tempDistance = tempDiff * 5 + 1000;
            galaJump = 0;
          }
        }
      } else if (uniType == 1){
        // alert("RING SYSTEM")
        if (raidLocation[0] - tempPlananet[0] != 0) {
          const max = maxGalaxy;
          let dist = 0;

          if (Math.abs(tempPlananet[0] - raidLocation[0]) < Math.ceil(max / 2)) {
            dist = Math.abs(tempPlananet[0] - raidLocation[0]);
          } else {
            dist = Math.floor(max / 2) - (Math.abs(tempPlananet[0] - raidLocation[0]) - Math.ceil(max / 2));
          }
          dist = Math.abs(dist);
          tempDistance = dist * 20000;
          galaJump = 1;
        } else {
          if (raidLocation[1] - tempPlananet[1] != 0) {
            const max = 400;
            let dist = 0;
            if (Math.abs(tempPlananet[1] - raidLocation[1]) < Math.ceil(max / 2)) {
              dist = Math.abs(tempPlananet[1] - raidLocation[1]);
            } else {
              dist = Math.floor(max / 2) - (Math.abs(tempPlananet[1] - raidLocation[1]) - Math.ceil(max / 2));
            }
            dist = Math.abs(dist);
            tempDistance = dist * 95 + 2700;
            galaJump = 0;
          } else {
            tempDiff = Math.abs(raidLocation[2] - tempPlananet[2]);
            tempDistance = tempDiff * 5 + 1000;
            galaJump = 0;
          }
        }
      }

      if (index == 0) {
        bestPlanetdistance = tempDistance;
        bestPlanet = tempPlananet;
        if (galaJump == 1) {
          bestPlanetGalaJump = 1
        }
      } else if (bestPlanetdistance > tempDistance) {
        bestPlanetdistance = tempDistance;
        bestPlanet = tempPlananet;
        if (galaJump == 0) {
          bestPlanetGalaJump = 0
        }
      }

      if (isActive == 1) {
        activPlanetdistance = tempDistance;
      }

      if (bestPlanetGalaJump == 1) {
        galaJumpPlanet = "Galajump " + bestPlanet[0]
      } else {
        galaJump = 0;
      }

    }

    return {
      'galaJump': galaJump,
      'galaJumpPlanet': galaJumpPlanet,
      'activPlanetdistance': activPlanetdistance,
      'bestPlanet': bestPlanet,
      'bestPlanetdistance': bestPlanetdistance
    }

  }

  function flightSpeed(shiptype) {
    var speed;


    if (shiptype === "202") {
      if (impulseEngineTech < 5) {
        speed = 5000 * (1 + (0.1 * combustionEngineTech));
      } else {
        speed = 10000 * (1 + (0.2 * impulseEngineTech));
      }
    }

    return fleetspeed * speed;

  }

  function determineRecycleValue(spyReport) {
    let summedUpRecycleValue = 0;
    Object.keys(recycleList).forEach(recycleKey => {
      let singleRecycleValue = recycleList[recycleKey];
      if (spyReport.content[recycleKey] !== undefined) {
        summedUpRecycleValue += spyReport.content[recycleKey] * singleRecycleValue;
      }
    });
    return summedUpRecycleValue * tfpercFleet / 100;
  }


  document.querySelectorAll(".spyRaport").forEach(spyReportElement => {

    if (spyReportElement.querySelector('*[name="totalRes"]') == null) {
      return;
    }

    let spyReport = spyReportParser(spyReportElement);

    var raidLocation = [spyReport.head.galaxy, spyReport.head.system, spyReport.head.planet];
    spyReport.conclusions = {};

    spyReport.conclusions.summedUpResources = sumUpResources(spyReport);
    spyReport.conclusions.potentialResources = sumUpResources(spyReport) / 2;
    spyReport.conclusions.neededCapacity = calculateNeededCapacity(
      spyReport.content[901],
      spyReport.content[902],
      spyReport.content[903]
    );
    spyReport.conclusions.recycleValue = determineRecycleValue(spyReport);
    spyReport.conclusions.recyclersNeeded = estimateRecyclers(spyReport.conclusions.recycleValue);
    spyReport.conclusions.marketValue = calculateResourceMarketValue(
      spyReport.content[901],
      spyReport.content[902],
      spyReport.content[903]
    );


    spyReport.conclusions.MarketValuePerSecond = raidTimeKT(spyReport.content[901], spyReport.content[902], spyReport.content[903], raidLocation);
    let bestrespertime = spyReport.conclusions.MarketValuePerSecond.calculateResourceMarketValuePerSecondBestPlanet
    let bestRessPerTimeClass = "lowRess";
    if (bestrespertime > stbSettings['stb_big_time']) {
      bestRessPerTimeClass = "realHighRess";
    } else if (bestrespertime > stbSettings['stb_med_time']) {
      bestRessPerTimeClass = "highRess";
    } else if (bestrespertime > stbSettings['stb_small_time']) {
      bestRessPerTimeClass = "midRess";
    }


    let ressourcesByMarketValue = spyReport.conclusions.marketValue
    let mkvaluetime = "lowRess";
    if (ressourcesByMarketValue > stbSettings['stb_big_ress']) {
      mkvaluetime = "realHighRess";
    } else if (ressourcesByMarketValue > stbSettings['stb_med_ress']) {
      mkvaluetime = "highRess";
    } else if (ressourcesByMarketValue > stbSettings['stb_small_ress']) {
      mkvaluetime = "midRess";
    }
    for (let d of spyReportElement.querySelectorAll('*[classadd="marketClass"]')) {
      d.classList.add(mkvaluetime);
    }

    for (let d of spyReportElement.querySelectorAll('*[classadd="timePerResClass"]')) {
      d.classList.add(bestRessPerTimeClass);
    }
    spyReportElement.querySelector('*[name="totalRes"]').innerText = pretty_number(spyReport.conclusions.summedUpResources);
    spyReportElement.querySelector('*[name="resToRaid"]').innerText = pretty_number(Math.floor(spyReport.conclusions.potentialResources));
    spyReportElement.querySelector('*[name="resToRec"]').innerText = pretty_number(spyReport.conclusions.recycleValue);
    spyReportElement.querySelector('*[name="recNeeded"]').innerText = pretty_number(spyReport.conclusions.recyclersNeeded);
    spyReportElement.querySelector('*[name="marketValue"]').innerText = pretty_number(Math.floor(spyReport.conclusions.marketValue));
    spyReportElement.querySelector('*[name="resPerSec"]').innerText = pretty_number(spyReport.conclusions.MarketValuePerSecond.calculateResourceMarketValuePerSecondBestPlanet, 2);
    spyReportElement.querySelector('*[name="bestPlanet"]').innerText = "[" + spyReport.conclusions.MarketValuePerSecond.bestPlanet.join(":") + "]";
  });

}


// spy report popup content arrives as modal fragment after the page init (scripts/app/dialog.js)
document.addEventListener('modal:load', () => scavengers());

window.onPageInit(function () {
  scavengers();
  for (const checkbox of document.querySelectorAll('input[name*="messageID"]')) {
    checkbox.addEventListener('change', function () {
      for (const inp of document.querySelectorAll('input[name="' + this.name + '"]')) {
        inp.checked = this.checked
      }
    });
  }
});
